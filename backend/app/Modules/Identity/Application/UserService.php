<?php

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;

class UserService
{
    public function __construct(
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
                'status' => User::STATUS_ACTIVE,
            ]);

            $this->audit->record('users.created', $user, null, $this->snapshot($user));
            $this->roles->assignToUser($actor, $user, $data['roles']);

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
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        return $user->only(['email', 'first_name', 'last_name', 'phone', 'status']);
    }
}
