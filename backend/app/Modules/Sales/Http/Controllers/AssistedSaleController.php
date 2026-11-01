<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Identity\Application\InvitationService;
use App\Modules\Identity\Application\RoleService;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Application\OrderService;
use App\Modules\Sales\Domain\Billing;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Package;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Sales\Http\OrderPresenter;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Venta asistida: el personal registra una compra a nombre de un alumno
 * (ya registrado o nuevo), con cupón o descuento manual, y dice cómo paga.
 */
class AssistedSaleController extends Controller
{
    /** Medios de pago que el personal puede registrar: los de siempre más la tarjeta en el POS de la academia */
    public const METHODS = [...Payment::METHODS, Payment::METHOD_CARD];

    /** Plazo máximo para pagar después, en días */
    private const MAX_DAYS_TO_PAY = 90;

    public function __construct(
        private readonly OrderService $orders,
        private readonly RoleService $roles,
        private readonly InvitationService $invitations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Lo que se puede vender y cómo se puede cobrar.
     */
    public function options(TenantSettingsService $settings): JsonResponse
    {
        $courses = Course::query()
            ->where('status', Course::STATUS_PUBLISHED)
            ->whereNotNull('current_version_id')
            ->whereNotNull('price')
            ->orderBy('title')
            ->get(['id', 'title', 'price']);

        $packages = Package::query()
            ->where('status', Package::STATUS_ACTIVE)
            ->with('courses')
            ->orderBy('title')
            ->get()
            ->filter(fn (Package $package) => $package->isForSale());

        return ApiResponse::success([
            'courses' => $courses->map(fn (Course $course) => ['id' => $course->id, 'title' => $course->title, 'price' => $course->price])->values(),
            'packages' => $packages->map(fn (Package $package) => ['id' => $package->id, 'title' => $package->title, 'price' => $package->price, 'courses' => $package->courses->count()])->values(),
            'payment_methods' => self::METHODS,
            'currency' => $settings->currency(),
            'expiration_hours' => $settings->orderExpirationHours(),
            'max_days_to_pay' => self::MAX_DAYS_TO_PAY,
            'document_types' => array_keys(User::DOCUMENT_TYPES),
        ]);
    }

    /**
     * Alumnos para elegir a quién se le vende: por nombre, correo o documento.
     */
    public function students(Request $request): JsonResponse
    {
        $search = (string) $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'];
        $like = '%'.mb_strtolower(trim($search)).'%';

        $students = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles', fn (Builder $roles) => $roles->where('code', RoleCatalog::STUDENT))
            ->when(trim($search) !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->whereRaw('lower(email) like ?', [$like])
                ->orWhereRaw("lower(first_name || ' ' || last_name) like ?", [$like])
                ->orWhere('doc_number', 'like', $like)))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(20)
            ->get();

        return ApiResponse::success($students->map(fn (User $student) => [
            'id' => $student->id,
            'name' => trim("{$student->first_name} {$student->last_name}"),
            'email' => $student->email,
            'document' => $student->doc_number,
        ])->values());
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->tenantId();
        $type = is_string($request->input('student.doc_type')) ? $request->input('student.doc_type') : null;
        $mode = $request->input('mode');

        $data = $request->validate([
            // A quién: un alumno registrado o uno nuevo
            'student_id' => ['required_without:student', 'nullable', 'uuid', Rule::exists('users', 'id')->where('tenant_id', $tenant)],
            'student' => ['required_without:student_id', 'nullable', 'array', 'prohibits:student_id'],
            'student.first_name' => ['required_with:student', 'string', 'max:100'],
            'student.last_name' => ['required_with:student', 'string', 'max:100'],
            'student.email' => ['required_with:student', 'email', 'max:180', Rule::unique('users', 'email')->where('tenant_id', $tenant)],
            'student.phone' => ['nullable', 'string', 'max:30'],
            'student.doc_type' => ['nullable', 'required_with:student.doc_number', Rule::in(array_keys(User::DOCUMENT_TYPES))],
            'student.doc_number' => ['nullable', 'required_with:student.doc_type', 'string', User::DOCUMENT_TYPES[$type]['rule'] ?? 'max:30',
                Rule::unique('users', 'doc_number')->where('tenant_id', $tenant)->where('doc_type', $type)],
            // Qué compra
            'course_id' => ['required_without:package_id', 'nullable', 'uuid', Rule::exists('courses', 'id')->where('tenant_id', $tenant)],
            'package_id' => ['required_without:course_id', 'nullable', 'uuid', 'prohibits:course_id', Rule::exists('packages', 'id')->where('tenant_id', $tenant)],
            // Un cupón o un descuento manual con su motivo
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'discount' => ['nullable', 'array', 'prohibits:coupon_code'],
            'discount.amount' => ['required_with:discount', 'numeric', 'min:0.01', 'max:9999999'],
            'discount.reason' => ['required_with:discount', 'string', 'min:3', 'max:200'],
            // Cómo paga
            'mode' => ['required', Rule::in(OrderService::SALE_MODES)],
            'expires_at' => ['nullable', 'date', 'after:now', 'before:'.now()->addDays(self::MAX_DAYS_TO_PAY + 1)->toIso8601String()],
            'payment' => [Rule::requiredIf($mode === OrderService::SALE_PAID), 'nullable', 'array'],
            'payment.method' => ['required_with:payment', Rule::in(self::METHODS)],
            'payment.reference' => ['nullable', 'string', 'max:120'],
            'payment.notes' => ['nullable', 'string', 'max:500'],
            'expected_total' => ['nullable', 'numeric', 'min:0'],
        ] + Billing::rules(is_string($request->input('billing.doc_type')) ? $request->input('billing.doc_type') : null), Billing::messages() + [
            'student.email.unique' => 'Ya hay una persona con ese correo: búscala en «Alumno registrado».',
            'student.doc_number.unique' => 'Ya hay una persona con ese documento: búscala en «Alumno registrado».',
            'discount.prohibits' => 'Usa un cupón o un descuento manual, no los dos.',
            'discount.reason.required_with' => 'Escribe el motivo del descuento.',
            'expires_at.before' => 'El plazo para pagar es de hasta '.self::MAX_DAYS_TO_PAY.' días.',
            'payment.required' => 'Indica cómo pagó.',
        ]);

        $installments = $mode === OrderService::SALE_INSTALLMENTS ? OrderController::validateInstallments($request) : null;

        $order = DB::transaction(function () use ($request, $data, $installments) {
            $customer = isset($data['student_id'])
                ? User::query()->findOrFail($data['student_id'])
                : $this->createStudent($data['student']);

            $product = isset($data['package_id'])
                ? Package::query()->findOrFail($data['package_id'])
                : Course::query()->with('currentVersion')->findOrFail($data['course_id']);

            $order = $this->orders->placeAssisted($request->user(), $customer, $product, [
                'coupon_code' => $data['coupon_code'] ?? null,
                'discount' => isset($data['discount']) ? ['amount' => (string) $data['discount']['amount'], 'reason' => trim($data['discount']['reason'])] : null,
                'mode' => $data['mode'],
                'expires_at' => isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
                'payment' => $data['payment'] ?? null,
                'installments' => $installments,
                'expected_total' => isset($data['expected_total']) ? (string) $data['expected_total'] : null,
            ]);

            // Antes de que termine la operación: el comprobante del pago sale con estos datos
            $order->forceFill(['billing' => Billing::from($data['billing'] ?? null, $customer)])->save();

            return $order;
        });

        return ApiResponse::success(OrderPresenter::make(Order::query()->with(OrderController::RELATIONS)->findOrFail($order->id)), null, 201);
    }

    /**
     * Alumno nuevo: cuenta de alumno sin contraseña y la invitación por correo para crear la suya.
     *
     * @param  array{first_name: string, last_name: string, email: string, phone?: ?string, doc_type?: ?string, doc_number?: ?string}  $data
     */
    private function createStudent(array $data): User
    {
        $user = User::create([
            'email' => strtolower($data['email']),
            'password' => InvitationService::placeholderPassword(),
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'phone' => $data['phone'] ?? null,
            'doc_type' => $data['doc_type'] ?? null,
            'doc_number' => $data['doc_number'] ?? null,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->audit->record('users.created', $user, null, ['email' => $user->email, 'name' => trim("{$user->first_name} {$user->last_name}"), 'source' => 'assisted_sale']);
        $this->roles->grantBaseRole($user, RoleCatalog::STUDENT);
        $this->invitations->invite($user);

        return $user;
    }
}
