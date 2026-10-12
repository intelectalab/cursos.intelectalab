<?php

namespace App\Modules\Sales\Application\Payments\Online;

use App\Modules\Sales\Domain\Models\GatewayError;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registro de lo que las pasarelas rechazan o no responden, para que el
 * personal lo vea en pantalla. El error sigue su camino tal cual: aquí solo
 * queda anotado. Si anotarlo falla, no cambia el resultado de la operación.
 */
class GatewayErrorLog
{
    /** El mismo error repetido dentro de este plazo suma una ocurrencia en vez de crear otra fila */
    private const REPEAT_WINDOW_MINUTES = 30;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Ejecuta una operación contra una pasarela y anota el error si lo hay.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    public function watch(string $scope, string $gateway, string $operation, ?string $subject, callable $action): mixed
    {
        try {
            return $action();
        } catch (ApiException $error) {
            if ($error->errorCode === ErrorCode::GatewayError) {
                // Un aviso de la pasarela a la plataforma llega sin organización ni sesión: se anota como sistema
                $this->context->hasTenant() || $this->context->isSystem()
                    ? $this->record($scope, $gateway, $operation, $subject, $error)
                    : $this->context->runAsSystem(fn () => $this->record($scope, $gateway, $operation, $subject, $error));
            }

            throw $error;
        }
    }

    /**
     * Los últimos errores de un ámbito, del más reciente al más antiguo.
     *
     * @return list<array<string, mixed>>
     */
    public function latest(string $scope, int $limit = 30): array
    {
        return GatewayError::query()->where('scope', $scope)->with('tenant:id,name')->orderByDesc('last_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (GatewayError $error) => [
                'id' => $error->id,
                'gateway' => $error->gateway,
                'operation' => $error->operation,
                'subject' => $error->subject,
                'message' => $error->message,
                'gateway_status' => $error->gateway_status,
                'gateway_reason' => $error->gateway_reason,
                // Inicio del identificador: es el «Código» que la pantalla muestra junto a un error
                'code' => $error->request_id ? substr($error->request_id, 0, 8) : null,
                'occurrences' => $error->occurrences,
                'first_at' => $error->created_at->toIso8601String(),
                'last_at' => $error->last_at->toIso8601String(),
                'tenant' => $error->tenant?->name,
            ])->all();
    }

    private function record(string $scope, string $gateway, string $operation, ?string $subject, ApiException $error): void
    {
        try {
            $details = $error->details;
            $same = [
                'scope' => $scope,
                'gateway' => $gateway,
                'operation' => $operation,
                'subject' => $subject,
                'message' => $error->getMessage(),
            ];

            // Una clave vencida falla cada vez que alguien abre una orden: se cuenta, no se repite la fila.
            // Solo se ven las filas de la organización en curso, así que no se mezclan entre organizaciones
            $previous = GatewayError::query()->where($same)->where('last_at', '>=', now()->subMinutes(self::REPEAT_WINDOW_MINUTES))->latest('last_at')->first();

            if ($previous !== null) {
                $previous->update(['occurrences' => $previous->occurrences + 1, 'last_at' => now(), 'request_id' => Context::get('request_id')]);

                return;
            }

            GatewayError::create($same + [
                'gateway_status' => $details['gateway_status'] ?? null,
                'gateway_reason' => mb_substr((string) ($details['gateway_reason'] ?? $details['gateway_cause'] ?? ''), 0, 300) ?: null,
                'request_id' => Context::get('request_id'),
                'last_at' => now(),
            ]);
        } catch (Throwable $failure) {
            Log::warning('No se pudo anotar un error de pasarela: '.$failure->getMessage());
        }
    }
}
