<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\Application\EmailPreferences;
use App\Modules\Tenancy\Domain\EmailCatalog;
use App\Modules\Tenancy\Domain\Models\EmailOptOut;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Qué correos de la academia recibe cada persona: desde el enlace al pie
 * del correo (sin sesión, con la firma), con un clic desde el programa de
 * correo, o desde «Mi cuenta».
 */
class EmailPreferencesController extends Controller
{
    public function __construct(
        private readonly EmailPreferences $preferences,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * La página del enlace: a quién le llegan y qué recibe hoy.
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $email = $this->emailOf($tenant, $token);

        return ApiResponse::success([
            'academy' => $tenant->name,
            'email' => EmailPreferences::mask($email),
            'categories' => $this->preferences->of($email),
        ]);
    }

    public function update(Request $request, string $token): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $email = $this->emailOf($tenant, $token);

        $this->save($email, $this->choices($request), EmailOptOut::SOURCE_LINK);

        return ApiResponse::success(['categories' => $this->preferences->of($email)]);
    }

    /**
     * Baja con un clic (RFC 8058): el programa de correo envía un POST sin
     * sesión y sin abrir la página. Responde vacío.
     */
    public function oneClick(Request $request, string $token): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $email = $this->emailOf($tenant, $token);
        $category = (string) $request->query('category');

        if (! array_key_exists($category, EmailCatalog::OPT_OUTS)) {
            throw new ApiException(ErrorCode::BadRequest, 'Ese tipo de correo no existe.', 422);
        }

        $this->save($email, [$category => false], EmailOptOut::SOURCE_ONE_CLICK);

        return ApiResponse::success(['unsubscribed' => $category]);
    }

    /**
     * «Mi cuenta»: lo mismo, para quien inició sesión.
     */
    public function mine(Request $request): JsonResponse
    {
        return ApiResponse::success(['categories' => $this->preferences->of($request->user()->email)]);
    }

    public function updateMine(Request $request): JsonResponse
    {
        $email = $request->user()->email;

        $this->save($email, $this->choices($request), EmailOptOut::SOURCE_ACCOUNT);

        return ApiResponse::success(['categories' => $this->preferences->of($email)]);
    }

    /**
     * @return array<string, bool>
     */
    private function choices(Request $request): array
    {
        $data = $request->validate([
            'categories' => ['required', 'array'],
            'categories.*' => ['boolean'],
        ]);

        if (array_diff(array_keys($data['categories']), array_keys(EmailCatalog::OPT_OUTS)) !== []) {
            throw ValidationException::withMessages(['categories' => 'Hay tipos de correo que no existen.']);
        }

        return array_map(fn ($value) => (bool) $value, $data['categories']);
    }

    /**
     * @param  array<string, bool>  $choices
     */
    private function save(string $email, array $choices, string $source): void
    {
        $before = $this->subscriptions($email);
        $this->preferences->update($email, $choices, $source);
        $after = $this->subscriptions($email);

        if ($before !== $after) {
            $this->audit->record('email_preferences.updated', 'email_opt_outs', $before, $after + ['email' => EmailPreferences::mask($email), 'source' => $source]);
        }
    }

    /**
     * @return array<string, bool>
     */
    private function subscriptions(string $email): array
    {
        return collect($this->preferences->of($email))->mapWithKeys(fn (array $category) => [$category['key'] => $category['subscribed']])->all();
    }

    private function emailOf(Tenant $tenant, string $token): string
    {
        return EmailPreferences::emailFromToken($tenant, $token)
            ?? throw new ApiException(ErrorCode::NotFound, 'El enlace no es válido. Ábrelo tal como llegó en el correo.', 404);
    }
}
