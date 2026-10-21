<?php

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Catalog\PermissionCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantNotifier;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

class UserService
{
    public function __construct(
        private readonly TenantNotifier $notifier,
        private readonly RoleService $roles,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone?: ?string, password: string, roles: list<string>}  $data
     */
    public function create(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data) {
            $user = User::create([
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'] ?? null,
                'doc_type' => $data['doc_type'] ?? null,
                'doc_number' => $data['doc_number'] ?? null,
                'status' => User::STATUS_ACTIVE,
            ]);

            $this->audit->record('users.created', $user, null, $this->snapshot($user));
            $this->roles->assignToUser($actor, $user, $data['roles']);

            $this->notifier->notify($user, 'cuenta_nueva', ['correo' => $user->email], '/login');

            return $user;
        });
    }

    /**
     * @param  array{first_name: string, last_name: string, phone?: ?string, status: string}  $data
     */
    public function update(User $user, array $data): User
    {
        $deactivating = $user->isActive() && $data['status'] !== User::STATUS_ACTIVE;

        if ($deactivating) {
            $this->roles->assertAdministratorRemainsWithout($user);
        }

        return DB::transaction(function () use ($user, $data, $deactivating) {
            $before = $this->snapshot($user);

            $user->update([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'] ?? null,
                'doc_type' => $data['doc_type'] ?? null,
                'doc_number' => $data['doc_number'] ?? null,
                'status' => $data['status'],
            ]);

            if ($deactivating) {
                // Una cuenta desactivada pierde sus sesiones abiertas
                $user->tokens()->delete();
            }

            $this->audit->record('users.updated', $user, $before, $this->snapshot($user));

            return $user;
        });
    }

    /**
     * Activa o desactiva varias cuentas. Las que no se pueden cambiar (la propia
     * al desactivar, el último administrador) se devuelven con su motivo.
     *
     * @param  list<string>  $userIds
     * @return array{updated: int, skipped: list<array{email: string, reason: string}>}
     */
    public function setStatusOfMany(User $actor, array $userIds, string $status): array
    {
        $updated = 0;
        $skipped = [];

        foreach (User::query()->whereIn('id', $userIds)->orderBy('email')->get() as $user) {
            if ($user->status === $status) {
                continue;
            }

            if ($status !== User::STATUS_ACTIVE && $actor->is($user)) {
                $skipped[] = ['email' => $user->email, 'reason' => 'No puedes desactivar tu propia cuenta.'];

                continue;
            }

            try {
                $this->update($user, ['status' => $status] + $user->only(['first_name', 'last_name', 'phone', 'doc_type', 'doc_number']));
                $updated++;
            } catch (ApiException $e) {
                $skipped[] = ['email' => $user->email, 'reason' => $e->getMessage()];
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Cierra las sesiones de una persona. Si el actor se las cierra a sí mismo,
     * conserva la que está usando.
     *
     * @return int cuántas sesiones se cerraron
     */
    public function revokeSessions(User $actor, User $user): int
    {
        $revoked = $user->tokens()
            ->when($actor->is($user), fn ($query) => $query->whereKeyNot($actor->currentAccessToken()->getKey()))
            ->delete();

        $this->audit->record('users.sessions_revoked', $user, null, ['revoked' => $revoked]);

        return $revoked;
    }

    /**
     * Quien administra los usuarios le pone a una persona una contraseña
     * temporal (la olvidó, o alguien más entró a su cuenta): se cierran todas
     * sus sesiones y al entrar debe elegir una propia. No se puede tomar así
     * una cuenta con permisos que quien la restablece no tiene.
     *
     * @return int sesiones cerradas
     */
    public function resetPassword(User $actor, User $user, string $password): int
    {
        if ($actor->is($user)) {
            throw new ApiException(ErrorCode::Forbidden, 'Tu propia contraseña se cambia en «Mi cuenta».', 403);
        }

        $own = $actor->permissionCodes();

        if (collect($user->permissionCodes())->contains(fn (string $code) => ! PermissionCatalog::covers($own, $code))) {
            throw new ApiException(
                ErrorCode::PrivilegeEscalation,
                'Esta persona tiene permisos que tu usuario no tiene: su contraseña solo la puede restablecer alguien con al menos sus mismos permisos.',
                403
            );
        }

        return DB::transaction(function () use ($user, $password) {
            $user->update(['password' => $password, 'must_change_password' => true]);
            $revoked = $user->tokens()->delete();

            // La contraseña no se guarda en la auditoría
            $this->audit->record('users.password_reset', $user, null, ['email' => $user->email, 'revoked_sessions' => $revoked]);

            return $revoked;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        return $user->only(['email', 'first_name', 'last_name', 'phone', 'doc_type', 'doc_number', 'status']);
    }
}
