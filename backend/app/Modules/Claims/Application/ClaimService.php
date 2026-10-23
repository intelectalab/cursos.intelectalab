<?php

namespace App\Modules\Claims\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Claims\Domain\Models\Claim;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\BusinessCalendar;
use App\Modules\Tenancy\Application\StaffNotifier;
use App\Modules\Tenancy\Application\TenantNotifier;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Libro de Reclamaciones (RF-CLAIM-001, FP-20): formulario → código →
 * registro → seguimiento → respuesta. La hoja no se edita después de
 * registrada; su estado queda trazado (RN-018).
 */
class ClaimService
{
    public function __construct(
        private readonly TenantNotifier $notifier,
        private readonly StaffNotifier $staff,
        private readonly TenantSettingsService $settings,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly BusinessCalendar $calendar,
    ) {}

    /**
     * Datos del proveedor que deben figurar en la hoja.
     *
     * @return array{name: string, legal_name: ?string, tax_id: ?string, address: ?string}
     */
    public function provider(Tenant $tenant): array
    {
        $settings = $this->settings->get($tenant);

        return [
            'name' => $tenant->name,
            'legal_name' => $tenant->legal_name,
            'tax_id' => $tenant->tax_id,
            'address' => $settings['contact_address'] ?? null,
        ];
    }

    /**
     * Registra la hoja que llenó el consumidor y le asigna su código.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(Tenant $tenant, array $data, ?string $ip, ?string $userAgent): Claim
    {
        return DB::transaction(function () use ($tenant, $data, $ip, $userAgent) {
            // El día de registro es el de la organización, no el del servidor
            $today = now($this->settings->get($tenant)['timezone']);
            $year = (int) $today->year;

            // El correlativo es por organización y año: se serializa para no repetir número
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["claims:{$this->context->tenantId()}:{$year}"]);
            }

            $number = (int) Claim::query()->where('year', $year)->max('number') + 1;

            $claim = Claim::create($data + [
                'year' => $year,
                'number' => $number,
                'code' => sprintf('LR-%d-%06d', $year, $number),
                'currency' => isset($data['amount']) ? $this->settings->currency() : null,
                'status' => Claim::STATUS_RECEIVED,
                'provider' => $this->provider($tenant),
                // Días hábiles: no cuentan sábados, domingos ni los feriados que la organización registró
                'due_at' => $this->calendar->addBusinessDays($today, $this->settings->claimResponseDays())->toDateString(),
                'ip_address' => $ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
            ]);

            $claim->history()->create(['to_status' => Claim::STATUS_RECEIVED]);

            $this->audit->record('claims.registered', $claim, null, ['code' => $claim->code, 'type' => $claim->type]);

            $this->notifier->notifyEmail($claim->email, $claim->first_name, 'reclamo_registrado', [
                'tipo' => $claim->type === Claim::TYPE_CLAIM ? 'reclamo' : 'queja',
                'codigo' => $claim->code,
                'fecha_limite' => $claim->due_at->format('d/m/Y'),
            ], '/reclamaciones/seguimiento');

            // Aviso al personal: el plazo de respuesta es legal
            $this->staff->notify('claims.manage', 'personal_reclamo_nuevo', [
                'tipo' => $claim->type === Claim::TYPE_CLAIM ? 'reclamo' : 'queja',
                'codigo' => $claim->code,
                'fecha_limite' => $claim->due_at->format('d/m/Y'),
            ], '/claims');

            return $claim;
        });
    }

    /**
     * El consumidor consulta su hoja con el código y su número de documento.
     */
    public function find(string $code, string $docNumber): ?Claim
    {
        return Claim::query()
            ->where('code', mb_strtoupper(trim($code)))
            ->where('doc_number', mb_strtoupper(trim($docNumber)))
            ->first();
    }

    public function startReview(Claim $claim, User $actor): Claim
    {
        if ($claim->status !== Claim::STATUS_RECEIVED) {
            return $claim;
        }

        return $this->move($claim, Claim::STATUS_IN_REVIEW, $actor);
    }

    /**
     * Respuesta formal al consumidor. Es una sola y cierra la hoja.
     */
    public function respond(Claim $claim, User $actor, string $body): Claim
    {
        return DB::transaction(function () use ($claim, $actor, $body) {
            $claim = Claim::query()->lockForUpdate()->findOrFail($claim->id);

            if ($claim->isAnswered()) {
                throw new ApiException(ErrorCode::ClaimAlreadyAnswered, 'Esta hoja ya fue respondida.', 409);
            }

            $claim->messages()->create(['author_id' => $actor->id, 'body' => $body, 'is_public' => true]);
            $claim->update(['answered_at' => now()]);

            $this->notifier->notifyEmail($claim->email, $claim->first_name, 'reclamo_respondido', ['codigo' => $claim->code], '/reclamaciones/seguimiento');

            return $this->move($claim, Claim::STATUS_ANSWERED, $actor);
        });
    }

    /**
     * Nota interna del personal: el consumidor no la ve.
     */
    public function addNote(Claim $claim, User $actor, string $body): Claim
    {
        $claim->messages()->create(['author_id' => $actor->id, 'body' => $body, 'is_public' => false]);

        return $claim;
    }

    private function move(Claim $claim, string $status, User $actor): Claim
    {
        $from = $claim->status;

        $claim->update(['status' => $status]);
        $claim->history()->create(['from_status' => $from, 'to_status' => $status, 'changed_by' => $actor->id]);

        $this->audit->record('claims.status_changed', $claim, ['status' => $from], ['status' => $status, 'code' => $claim->code]);

        return $claim;
    }
}
