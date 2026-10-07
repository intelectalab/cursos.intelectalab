<?php

namespace App\Modules\Promotions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Promotions\Domain\Models\Coupon;
use App\Modules\Promotions\Domain\Models\CouponRedemption;
use App\Modules\Promotions\Domain\Models\Promotion;
use App\Modules\Promotions\Http\PromotionPresenter;
use App\Modules\Sales\Domain\Models\Package;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Promociones y sus cupones: la regla del descuento y los códigos que la activan.
 */
class PromotionController extends Controller
{
    private const EDITABLE = ['name', 'discount_type', 'discount_value', 'starts_at', 'ends_at', 'max_redemptions', 'once_per_customer', 'min_purchase', 'applies_to', 'status'];

    public function __construct(
        private readonly TenantSettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        $promotions = $this->listing()->orderByDesc('created_at')->orderBy('id')->get();
        $uses = $this->usesByCoupon();

        return ApiResponse::success(
            $promotions->map(fn (Promotion $promotion) => PromotionPresenter::make($promotion, $uses, $this->settings->timezone()))->values(),
            ['currency' => $this->settings->currency()]
        );
    }

    /**
     * A qué puede aplicar una promoción: cursos en venta con precio y paquetes.
     */
    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'currency' => $this->settings->currency(),
            'courses' => Course::query()
                ->where('status', Course::STATUS_PUBLISHED)
                ->where('price', '>', 0)
                ->orderBy('title')
                ->get(['id', 'title', 'price'])
                ->map(fn (Course $course) => ['id' => $course->id, 'title' => $course->title, 'price' => $course->price])
                ->values(),
            'packages' => Package::query()
                ->orderBy('title')
                ->get(['id', 'title', 'price'])
                ->map(fn (Package $package) => ['id' => $package->id, 'title' => $package->title, 'price' => $package->price])
                ->values(),
        ]);
    }

    /**
     * Crea la promoción con su primer cupón.
     */
    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $data = $this->validated($request) + $request->validate($this->couponRules($context), $this->couponMessages());

        $promotion = DB::transaction(function () use ($request, $data) {
            $promotion = Promotion::create(collect($data)->only(self::EDITABLE)->all() + ['created_by' => $request->user()->id]);

            $this->syncItems($promotion, $data);
            $promotion->coupons()->create(['code' => Coupon::normalize($data['code']), 'label' => $data['label'] ?? null]);

            $this->audit->record('promotions.created', $promotion, null, [
                'name' => $promotion->name,
                'discount' => "{$promotion->discount_type} {$promotion->discount_value}",
                'coupon' => Coupon::normalize($data['code']),
            ]);

            return $promotion;
        });

        return $this->respond($promotion, 201);
    }

    public function show(Promotion $promotion): JsonResponse
    {
        return $this->respond($promotion);
    }

    /**
     * Cambia la regla. Las órdenes ya creadas conservan el descuento que tuvieron.
     */
    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($promotion, $data) {
            $before = $promotion->only(self::EDITABLE);

            $promotion->update(collect($data)->only(self::EDITABLE)->all());
            $this->syncItems($promotion, $data);

            $this->audit->record('promotions.updated', $promotion, $before, $promotion->only(self::EDITABLE));
        });

        return $this->respond($promotion);
    }

    public function storeCoupon(Request $request, Promotion $promotion, TenantContext $context): JsonResponse
    {
        $data = $request->validate($this->couponRules($context), $this->couponMessages());

        $coupon = $promotion->coupons()->create(['code' => Coupon::normalize($data['code']), 'label' => $data['label'] ?? null]);

        $this->audit->record('coupons.created', $coupon, null, ['code' => $coupon->code, 'promotion' => $promotion->name]);

        return $this->respond($promotion, 201);
    }

    /**
     * Activa o desactiva un código sin tocar los demás de la promoción.
     */
    public function updateCoupon(Request $request, Coupon $coupon): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Promotion::STATUS_ACTIVE, Promotion::STATUS_INACTIVE])],
            'label' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $before = $coupon->only(['status', 'label']);
        $coupon->update($data);

        if ($coupon->wasChanged()) {
            $this->audit->record('coupons.updated', $coupon, $before, $coupon->only(['status', 'label']) + ['code' => $coupon->code]);
        }

        return $this->respond($coupon->promotion);
    }

    /**
     * Compras en las que se usó la promoción.
     */
    public function redemptions(Promotion $promotion): JsonResponse
    {
        $redemptions = CouponRedemption::query()
            ->where('promotion_id', $promotion->id)
            ->with(['coupon', 'customer', 'order.currency'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return ApiResponse::success($redemptions->map(fn (CouponRedemption $redemption) => PromotionPresenter::redemption($redemption))->values());
    }

    /**
     * @return Builder<Promotion>
     */
    private function listing(): Builder
    {
        return Promotion::query()
            ->with(['items', 'coupons'])
            ->withCount(['redemptions as uses_count' => fn (Builder $query) => $query->counting()]);
    }

    /**
     * @return array<string, int>
     */
    private function usesByCoupon(): array
    {
        return CouponRedemption::query()
            ->counting()
            ->selectRaw('coupon_id, count(*) as total')
            ->groupBy('coupon_id')
            ->pluck('total', 'coupon_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    private function respond(Promotion $promotion, int $status = 200): JsonResponse
    {
        return ApiResponse::success(
            PromotionPresenter::make($this->listing()->findOrFail($promotion->id), $this->usesByCoupon(), $this->settings->timezone()),
            ['currency' => $this->settings->currency()],
            $status
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncItems(Promotion $promotion, array $data): void
    {
        $promotion->items()->delete();

        if ($promotion->applies_to !== Promotion::APPLIES_SELECTED) {
            return;
        }

        foreach ($data['items'] ?? [] as $item) {
            $promotion->items()->create(['product_type' => $item['type'], 'product_id' => $item['id']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $percent = $request->input('discount_type') === Promotion::TYPE_PERCENT;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'discount_type' => ['required', Rule::in(Promotion::TYPES)],
            'discount_value' => ['required', 'numeric', 'gt:0', $percent ? 'max:100' : 'max:9999999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'once_per_customer' => ['required', 'boolean'],
            'min_purchase' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'applies_to' => ['required', Rule::in([Promotion::APPLIES_ALL, Promotion::APPLIES_SELECTED])],
            'status' => ['sometimes', Rule::in([Promotion::STATUS_ACTIVE, Promotion::STATUS_INACTIVE])],
            'items' => ['required_if:applies_to,'.Promotion::APPLIES_SELECTED, 'array', 'max:200'],
            'items.*.type' => ['required', Rule::in(Promotion::PRODUCT_TYPES)],
            'items.*.id' => ['required', 'uuid'],
        ], [
            'discount_value.max' => $percent ? 'Un porcentaje no puede pasar de 100.' : 'El monto es demasiado alto.',
            'ends_at.after_or_equal' => 'La fecha de fin debe ser posterior a la de inicio.',
            'items.required_if' => 'Elige al menos un curso o paquete.',
        ]);

        // Solo cursos y paquetes de esta organización
        $courses = collect($data['items'] ?? [])->where('type', Promotion::PRODUCT_COURSE)->pluck('id');
        $packages = collect($data['items'] ?? [])->where('type', Promotion::PRODUCT_PACKAGE)->pluck('id');

        abort_if(
            Course::query()->whereIn('id', $courses)->count() !== $courses->unique()->count()
                || Package::query()->whereIn('id', $packages)->count() !== $packages->unique()->count(),
            422,
            'Uno de los cursos o paquetes elegidos no existe.'
        );

        // Una fecha sin hora es un día de la organización: empieza a su medianoche y termina al final de su día
        $timezone = $this->settings->timezone();
        $day = fn (string $value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? Carbon::parse($value, $timezone) : null;

        $data['starts_at'] = isset($data['starts_at'])
            ? ($day($data['starts_at'])?->startOfDay() ?? Carbon::parse($data['starts_at']))->utc()
            : null;
        $data['ends_at'] = isset($data['ends_at'])
            ? ($day($data['ends_at'])?->endOfDay()->startOfSecond() ?? Carbon::parse($data['ends_at']))->utc()
            : null;

        if ($data['starts_at'] && $data['ends_at'] && $data['ends_at']->lte($data['starts_at'])) {
            throw ValidationException::withMessages(['ends_at' => 'La fecha de fin debe ser posterior a la de inicio.']);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function couponRules(TenantContext $context): array
    {
        return [
            'code' => [
                'required', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
                // Único sin distinguir mayúsculas
                function (string $attribute, mixed $value, \Closure $fail) use ($context) {
                    if (DB::table('coupons')->where('tenant_id', $context->tenantId())->where('code', Coupon::normalize((string) $value))->exists()) {
                        $fail('Ya existe un cupón con ese código.');
                    }
                },
            ],
            'label' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function couponMessages(): array
    {
        return ['code.regex' => 'El código solo admite letras, números y guiones, sin espacios.'];
    }
}
