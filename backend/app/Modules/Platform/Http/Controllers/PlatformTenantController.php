<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Billing\Application\SubscriptionService;
use App\Modules\Billing\Domain\Models\TenantSubscription;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\ProvisionTenant;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\Models\TenantSetting;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Consola del administrador de plataforma: todas las organizaciones, sus
 * cifras, su alta, la ficha de cada una y el acceso de soporte en solo lectura.
 */
class PlatformTenantController extends Controller
{
    /** Minutos que dura un acceso de soporte; no se renueva con el uso */
    public const SUPPORT_MINUTES = 30;

    /** Permiso único del acceso de soporte: sin "*", la sesión es de solo lectura */
    public const SUPPORT_ABILITY = 'support:read';

    /** Datos de contacto de la organización que la plataforma puede corregir */
    private const CONTACT_KEYS = ['contact_email', 'contact_phone', 'contact_address'];

    /** Lo que la ficha muestra como historial de la plataforma sobre la organización */
    private const HISTORY_ACTIONS = [
        'platform.support_session_started',
        'tenants.status_changed',
        'platform.tenant_updated',
        'platform.admin_password_reset',
        'platform.subscription_changed',
    ];

    private const HISTORY_LIMIT = 50;

    private const SALES_MONTHS = 6;

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tenants = Tenant::query()
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $like = '%'.mb_strtolower($search).'%';

                $query->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(slug) like ?', [$like]));
            })
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 25);

        $stats = $this->stats(collect($tenants->items())->pluck('id'));

        return ApiResponse::paginated($tenants, fn (Tenant $tenant) => $this->present($tenant, $stats), [
            'totals' => [
                'tenants' => Tenant::query()->count(),
                'active' => Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->count(),
                'suspended' => Tenant::query()->where('status', Tenant::STATUS_SUSPENDED)->count(),
                'users' => DB::table('users')->where('status', User::STATUS_ACTIVE)->count(),
                'students' => (int) DB::table('enrollments')->where('status', 'ACTIVE')->distinct()->count('student_id'),
                'published_courses' => DB::table('courses')->where('status', 'PUBLISHED')->count(),
            ],
        ]);
    }

    /**
     * Alta de una organización desde la consola. Su administrador entra con
     * una contraseña temporal que debe cambiar.
     */
    public function store(Request $request, ProvisionTenant $provision): JsonResponse
    {
        $request->merge([
            'subdomain' => strtolower(trim((string) $request->input('subdomain'))),
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $data = $request->validate([
            'organization_name' => ['required', 'string', 'max:150'],
            'subdomain' => [
                'required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(config('tenancy.reserved_slugs')),
                Rule::unique('tenants', 'slug'),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:180'],
            'password' => ['required', 'string', 'min:8'],
        ], [
            'subdomain.regex' => 'El subdominio solo admite minúsculas, números y guiones.',
            'subdomain.not_in' => 'Ese subdominio está reservado.',
            'subdomain.unique' => 'Ese subdominio ya está en uso.',
        ]);

        $tenant = $provision->handle(
            ['name' => $data['organization_name'], 'slug' => $data['subdomain']],
            ['first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'email' => $data['email'], 'password' => $data['password']],
            mustChangePassword: true,
        );

        return ApiResponse::success($this->present($tenant, $this->stats(collect([$tenant->id]))), null, 201);
    }

    /**
     * Suspende o reactiva una organización.
     */
    public function updateStatus(Request $request, Tenant $tenant, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED])],
            // Suspender deja a toda la organización sin acceso: hay que decir por qué
            'reason' => ['required_if:status,'.Tenant::STATUS_SUSPENDED, 'nullable', 'string', 'max:300'],
        ], ['reason.required_if' => 'Indica el motivo de la suspensión.']);

        $before = $tenant->status;

        if ($before !== $data['status']) {
            $tenant->update(['status' => $data['status']]);

            // El evento queda en la auditoría de la organización afectada
            $context->runForTenant($tenant->id, fn () => $audit->record(
                'tenants.status_changed',
                $tenant,
                ['status' => $before],
                array_filter([
                    'status' => $tenant->status,
                    'reason' => $data['reason'] ?? null,
                    'platform_admin' => $request->user()->email,
                ]),
            ));
        }

        return ApiResponse::success($this->present($tenant, $this->stats(collect([$tenant->id]))));
    }

    /**
     * Acceso de soporte: una sesión corta y de solo lectura dentro de la
     * organización, con la vista de su administrador. Queda en su auditoría.
     */
    public function supportSession(Request $request, Tenant $tenant, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        if (! $tenant->isActive()) {
            throw new ApiException(ErrorCode::TenantInactive, 'La organización está suspendida: reactívala para poder entrar.', 409);
        }

        return $context->runForTenant($tenant->id, function () use ($request, $tenant, $audit) {
            // Se entra con lo que ve su administrador más antiguo
            $admin = User::query()
                ->where('status', User::STATUS_ACTIVE)
                ->whereHas('roles', fn ($role) => $role->where('code', RoleCatalog::ADMIN)->where('is_system', true))
                ->orderBy('created_at')
                ->first();

            if ($admin === null) {
                throw new ApiException(ErrorCode::NotFound, 'La organización no tiene un administrador activo.', 409);
            }

            $expiresAt = now()->addMinutes(self::SUPPORT_MINUTES);
            $token = $admin->createToken('support-session', [self::SUPPORT_ABILITY], $expiresAt);
            $token->accessToken->forceFill([
                'ip_address' => $request->ip(),
                'user_agent' => 'Soporte de plataforma: '.$request->user()->email,
            ])->save();

            $audit->record('platform.support_session_started', $tenant, null, [
                'platform_admin' => $request->user()->email,
                'as_user' => $admin->email,
                'expires_at' => $expiresAt->toIso8601String(),
            ], $admin->id);

            return ApiResponse::success([
                'token' => $token->plainTextToken,
                'slug' => $tenant->slug,
                'as_user' => trim("{$admin->first_name} {$admin->last_name}"),
                'expires_at' => $expiresAt->toIso8601String(),
            ], null, 201);
        });
    }

    /**
     * Ficha de una organización: sus datos, sus cifras, sus administradores y
     * lo que la plataforma hizo sobre ella. No muestra datos de sus alumnos.
     */
    public function show(Tenant $tenant, TenantContext $context): JsonResponse
    {
        $settings = $this->storedSettings($tenant);
        $monthStart = now()->startOfMonth();
        $firstMonth = $monthStart->copy()->subMonths(self::SALES_MONTHS - 1);

        $sales = DB::table('orders')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'PAID')
            ->where('paid_at', '>=', $firstMonth)
            ->selectRaw("to_char(paid_at, 'YYYY-MM') as month, sum(total_amount) as total, count(*) as orders")
            ->groupBy('month')
            ->get()
            ->keyBy('month');

        $admins = $context->runForTenant($tenant->id, fn () => User::query()
            ->whereHas('roles', fn ($role) => $role->where('code', RoleCatalog::ADMIN)->where('is_system', true))
            ->orderBy('created_at')
            ->get());

        return ApiResponse::success($this->present($tenant, $this->stats(collect([$tenant->id]))) + [
            'legal_name' => $tenant->legal_name,
            'tax_id' => $tenant->tax_id,
            'contact_email' => $settings['contact_email'] ?? null,
            'contact_phone' => $settings['contact_phone'] ?? null,
            'contact_address' => $settings['contact_address'] ?? null,
            'timezone' => $settings['timezone'] ?? 'America/Lima',
            // Solo órdenes pagadas, en la moneda de la organización, como en sus propios reportes
            'monthly_sales' => collect(range(0, self::SALES_MONTHS - 1))->map(function (int $offset) use ($firstMonth, $sales) {
                $month = $firstMonth->copy()->addMonths($offset)->format('Y-m');

                return [
                    'month' => $month,
                    'total' => number_format((float) ($sales[$month]->total ?? 0), 2, '.', ''),
                    'orders' => (int) ($sales[$month]->orders ?? 0),
                ];
            })->all(),
            'enrollments_this_month' => DB::table('enrollments')->where('tenant_id', $tenant->id)->where('enrolled_at', '>=', $monthStart)->count(),
            'certificates_issued' => DB::table('certificates')->where('tenant_id', $tenant->id)->where('status', 'ISSUED')->count(),
            'storage_bytes' => (int) DB::table('files')->where('tenant_id', $tenant->id)->sum('size_bytes'),
            'admins' => $admins->map(fn (User $admin) => [
                'id' => $admin->id,
                'name' => trim("{$admin->first_name} {$admin->last_name}"),
                'email' => $admin->email,
                'status' => $admin->status,
                'last_login_at' => $admin->last_login_at?->toIso8601String(),
                'must_change_password' => (bool) $admin->must_change_password,
            ])->values(),
            'history' => DB::table('audit_events')
                ->where('tenant_id', $tenant->id)
                ->whereIn('action', self::HISTORY_ACTIONS)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(self::HISTORY_LIMIT)
                ->get(['id', 'action', 'before', 'after', 'created_at'])
                ->map(fn (object $event) => [
                    'id' => $event->id,
                    'action' => $event->action,
                    'before' => $event->before ? json_decode($event->before, true) : null,
                    'after' => $event->after ? json_decode($event->after, true) : null,
                    'created_at' => Carbon::parse($event->created_at)->toIso8601String(),
                ])->all(),
        ]);
    }

    /**
     * La plataforma corrige los datos de una organización. El subdominio no
     * se cambia aquí: rompería los enlaces que la organización ya compartió.
     */
    public function update(Request $request, Tenant $tenant, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'contact_email' => ['nullable', 'email', 'max:180'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contact_address' => ['nullable', 'string', 'max:250'],
        ]);

        $context->runForTenant($tenant->id, function () use ($request, $tenant, $data, $audit) {
            DB::transaction(function () use ($request, $tenant, $data, $audit) {
                $stored = $this->storedSettings($tenant);
                $before = $tenant->only(['name', 'legal_name', 'tax_id']) + array_merge(array_fill_keys(self::CONTACT_KEYS, null), array_intersect_key($stored, array_flip(self::CONTACT_KEYS)));
                $after = array_merge(array_fill_keys(array_keys($before), null), array_intersect_key($data, $before));

                $tenant->update(array_intersect_key($after, array_flip(['name', 'legal_name', 'tax_id'])));
                // Solo se tocan los datos de contacto: el resto de su configuración queda como estaba
                TenantSetting::query()->updateOrCreate(
                    ['key' => 'general'],
                    ['value' => array_merge($stored, array_intersect_key($after, array_flip(self::CONTACT_KEYS)))]
                );

                $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

                if ($changed !== []) {
                    $audit->record(
                        'platform.tenant_updated',
                        $tenant,
                        array_intersect_key($before, array_flip($changed)),
                        array_intersect_key($after, array_flip($changed)) + ['platform_admin' => $request->user()->email],
                    );
                }
            });
        });

        return $this->show($tenant->refresh(), $context);
    }

    /**
     * Devuelve el acceso a un administrador de la organización que lo perdió:
     * contraseña temporal, cambio obligatorio al entrar y sesiones cerradas.
     */
    public function resetAdminPassword(Request $request, Tenant $tenant, string $user, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'max:100']]);

        return $context->runForTenant($tenant->id, function () use ($request, $tenant, $user, $data, $audit) {
            // Solo administradores de esta organización: de cualquier otra cuenta se ocupa su propio administrador
            $admin = User::query()
                ->whereHas('roles', fn ($role) => $role->where('code', RoleCatalog::ADMIN)->where('is_system', true))
                ->findOrFail($user);

            DB::transaction(function () use ($request, $tenant, $admin, $data, $audit) {
                $admin->update(['password' => $data['password'], 'must_change_password' => true]);
                $admin->tokens()->delete();

                $audit->record('platform.admin_password_reset', $tenant, null, [
                    'user' => $admin->email,
                    'platform_admin' => $request->user()->email,
                ], $admin->id);
            });

            return ApiResponse::success(['email' => $admin->email, 'must_change_password' => true]);
        });
    }

    /**
     * Configuración general tal como está guardada, sin valores por defecto.
     *
     * @return array<string, mixed>
     */
    private function storedSettings(Tenant $tenant): array
    {
        $value = DB::table('tenant_settings')->where('tenant_id', $tenant->id)->where('key', 'general')->value('value');

        return $value ? (json_decode($value, true) ?: []) : [];
    }

    /**
     * Cifras de varias organizaciones con una consulta por cifra.
     *
     * @param  Collection<int, string>  $tenantIds
     * @return array<string, Collection<string, mixed>>
     */
    private function stats(Collection $tenantIds): array
    {
        $by = fn (string $table) => DB::table($table)->whereIn('tenant_id', $tenantIds)->groupBy('tenant_id');
        $monthStart = now()->startOfMonth();

        return [
            'users' => $by('users')->where('status', User::STATUS_ACTIVE)->selectRaw('tenant_id, count(*) as value')->pluck('value', 'tenant_id'),
            'students' => $by('enrollments')->where('status', 'ACTIVE')->selectRaw('tenant_id, count(distinct student_id) as value')->pluck('value', 'tenant_id'),
            'courses' => $by('courses')->where('status', 'PUBLISHED')->selectRaw('tenant_id, count(*) as value')->pluck('value', 'tenant_id'),
            'sales' => $by('orders')->where('status', 'PAID')->where('paid_at', '>=', $monthStart)->selectRaw('tenant_id, sum(total_amount) as value')->pluck('value', 'tenant_id'),
            'last_login' => $by('users')->selectRaw('tenant_id, max(last_login_at) as value')->pluck('value', 'tenant_id'),
            'currency' => DB::table('tenant_settings')->whereIn('tenant_id', $tenantIds)->where('key', 'general')
                ->selectRaw("tenant_id, value->>'primary_currency' as value")->pluck('value', 'tenant_id'),
            'subscriptions' => TenantSubscription::query()->whereIn('tenant_id', $tenantIds)->with('plan')->get()->keyBy('tenant_id'),
        ];
    }

    /**
     * @param  array<string, Collection<string, mixed>>  $stats
     * @return array<string, mixed>
     */
    private function present(Tenant $tenant, array $stats): array
    {
        $lastLogin = $stats['last_login'][$tenant->id] ?? null;

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'created_at' => $tenant->created_at?->toIso8601String(),
            'users_count' => (int) ($stats['users'][$tenant->id] ?? 0),
            'students_count' => (int) ($stats['students'][$tenant->id] ?? 0),
            'published_courses_count' => (int) ($stats['courses'][$tenant->id] ?? 0),
            // Ventas pagadas en el mes en curso, en la moneda de la organización
            'sales_this_month' => number_format((float) ($stats['sales'][$tenant->id] ?? 0), 2, '.', ''),
            'currency' => $stats['currency'][$tenant->id] ?? 'PEN',
            'last_login_at' => $lastLogin ? Carbon::parse($lastLogin)->toIso8601String() : null,
            // Prueba o plan vigente, con el cupo de alumnos que usa
            'subscription' => $this->subscriptions->present($stats['subscriptions'][$tenant->id] ?? null, (int) ($stats['students'][$tenant->id] ?? 0)),
        ];
    }
}
