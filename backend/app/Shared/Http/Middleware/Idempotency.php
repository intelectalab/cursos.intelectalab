<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Idempotency\IdempotencyKey;
use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotencia de las operaciones con efecto (Maestro §7.1): si la petición
 * trae la cabecera Idempotency-Key y ya fue atendida, devuelve la respuesta
 * guardada en lugar de repetir la operación. Sin cabecera, la ruta se comporta
 * como siempre.
 */
class Idempotency
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get(self::HEADER);

        if ($key === null || $request->user() === null) {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key)) {
            throw new ApiException(ErrorCode::ValidationError, 'La cabecera Idempotency-Key no es válida.', 422);
        }

        $identity = ['user_id' => $request->user()->getAuthIdentifier(), 'key' => $key];
        $fingerprint = [
            'method' => $request->method(),
            'path' => mb_substr($request->path(), 0, 255),
            'request_hash' => hash('sha256', $request->getContent()),
        ];

        // Una clave vencida que aún no se limpió no cuenta
        IdempotencyKey::query()->where($identity)
            ->where('created_at', '<', now()->subHours(IdempotencyKey::RETENTION_HOURS))
            ->delete();

        // Inserción que no falla si la clave ya existe: dos peticiones simultáneas no pasan ambas
        $id = (string) Str::uuid();
        $claimed = IdempotencyKey::query()->insertOrIgnore($identity + $fingerprint + [
            'id' => $id,
            'tenant_id' => $this->context->tenantId(),
            'created_at' => now(),
        ]);

        if ($claimed === 0) {
            return $this->replay(IdempotencyKey::query()->where($identity)->firstOrFail(), $fingerprint);
        }

        $record = IdempotencyKey::query()->findOrFail($id);
        $response = $next($request);

        // Solo se guarda lo que salió bien: un error deja la clave libre para reintentar
        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $record->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getData(true),
            ]);
        } else {
            $record->delete();
        }

        return $response;
    }

    /**
     * @param  array{method: string, path: string, request_hash: string}  $fingerprint
     */
    private function replay(IdempotencyKey $record, array $fingerprint): Response
    {
        if ($record->only(array_keys($fingerprint)) !== $fingerprint) {
            throw new ApiException(
                ErrorCode::IdempotencyKeyReused,
                'Esa clave de idempotencia ya se usó con otra operación.',
                422
            );
        }

        if ($record->response_status === null) {
            throw new ApiException(
                ErrorCode::IdempotencyInProgress,
                'La operación anterior todavía está en curso. Intente nuevamente en unos segundos.',
                409
            );
        }

        return response()
            ->json($record->response_body, $record->response_status)
            ->header(self::REPLAYED_HEADER, 'true');
    }
}
