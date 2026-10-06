<?php

namespace App\Modules\B2b\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\B2b\Domain\Models\CorporateAccount;
use App\Modules\B2b\Domain\Models\CorporateMember;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Personas de una empresa cliente: sus trabajadores (alumnos) y sus
 * responsables (quienes entran al portal de empresa).
 */
class MemberService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Registra a una persona en la empresa. Crea su cuenta con el rol que le
     * corresponde: Alumno para un trabajador, Responsable de empresa para
     * quien administra el portal. No pasa por la regla "nadie otorga permisos
     * que no tiene": aquí el rol es fijo y no lo elige quien registra.
     *
     * @param  array{first_name: string, last_name: string, email: string, password?: ?string, is_manager?: bool}  $data
     * @param  bool  $canLinkExisting  el personal puede vincular una cuenta que ya existe en la academia; el responsable, no
     */
    public function add(CorporateAccount $account, User $actor, array $data, bool $canLinkExisting): CorporateMember
    {
        $email = strtolower($data['email']);
        $isManager = (bool) ($data['is_manager'] ?? false);

        return DB::transaction(function () use ($account, $actor, $data, $email, $isManager, $canLinkExisting) {
            $user = User::query()->where('email', $email)->first();

            if ($user) {
                $this->assertLinkable($account, $user, $canLinkExisting);
            } else {
                if (empty($data['password'])) {
                    throw new ApiException(ErrorCode::ValidationError, 'Indica una contraseña inicial.', 422, [
                        'password' => ['La contraseña inicial es obligatoria para una cuenta nueva.'],
                    ]);
                }

                $user = User::create([
                    'email' => $email,
                    'password' => $data['password'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'status' => User::STATUS_ACTIVE,
                ]);

                $this->audit->record('users.created', $user, null, $user->only(['email', 'first_name', 'last_name', 'status']));
            }

            $role = Role::query()
                ->where('code', $isManager ? RoleCatalog::COMPANY : RoleCatalog::STUDENT)
                ->where('is_system', true)
                ->valueOrFail('id');
            $user->roles()->syncWithoutDetaching([$role]);

            $member = CorporateMember::create([
                'corporate_account_id' => $account->id,
                'user_id' => $user->id,
                'is_manager' => $isManager,
                'created_by' => $actor->id,
            ]);

            $this->audit->record('corporate_members.added', $member, null, [
                'company' => $account->company->name,
                'user' => $user->email,
                'is_manager' => $isManager,
            ]);

            return $member;
        });
    }

    private function assertLinkable(CorporateAccount $account, User $user, bool $canLinkExisting): void
    {
        $membership = CorporateMember::query()->where('user_id', $user->id)->first();

        $message = match (true) {
            $membership?->corporate_account_id === $account->id => 'Esta persona ya está registrada en la empresa.',
            $membership !== null => 'Esta persona ya pertenece a otra empresa.',
            // El responsable no puede atraer a su empresa una cuenta ajena de la academia
            ! $canLinkExisting => 'Ya existe una cuenta con ese correo en la academia. Pide a la academia que la vincule a tu empresa.',
            ! $user->isActive() => 'La cuenta con ese correo está desactivada.',
            default => null,
        };

        if ($message !== null) {
            throw new ApiException(ErrorCode::ValidationError, $message, 422, ['email' => [$message]]);
        }
    }
}
