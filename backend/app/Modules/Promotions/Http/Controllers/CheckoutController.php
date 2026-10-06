<?php

namespace App\Modules\Promotions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Promotions\Application\CouponService;
use App\Modules\Promotions\Domain\Models\Promotion;
use App\Modules\Sales\Domain\Models\Package;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    /**
     * Maestro §7.2 (POST /checkout/validate-coupon): le dice al comprador si
     * su cupón aplica y cuánto pagaría. No reserva nada: el descuento se
     * vuelve a calcular al crear la orden (RN-008).
     */
    public function validateCoupon(Request $request, CouponService $coupons, TenantSettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'course_id' => ['required_without:package_id', 'nullable', 'uuid'],
            'package_id' => ['required_without:course_id', 'nullable', 'uuid'],
        ]);

        if (isset($data['package_id'])) {
            $package = Package::query()->with('courses')->findOrFail($data['package_id']);
            [$type, $id, $subtotal, $forSale] = [Promotion::PRODUCT_PACKAGE, $package->id, $package->price, $package->isForSale()];
        } else {
            $course = Course::query()->findOrFail($data['course_id']);
            [$type, $id, $subtotal, $forSale] = [Promotion::PRODUCT_COURSE, $course->id, $course->price, $course->isForSale()];
        }

        if (! $forSale) {
            throw new ApiException(ErrorCode::CourseNotForSale, 'Esto ya no está en venta.', 409);
        }

        $applied = $coupons->evaluate($data['code'], $request->user(), $type, $id, $subtotal);

        return ApiResponse::success([
            'code' => $applied['coupon']->code,
            'promotion' => $applied['promotion']->name,
            'discount_type' => $applied['promotion']->discount_type,
            'discount_value' => $applied['promotion']->discount_value,
            'currency' => $settings->currency(),
            'subtotal_amount' => number_format((float) $subtotal, 2, '.', ''),
            'discount_amount' => $applied['discount'],
            'total_amount' => number_format((float) $subtotal - (float) $applied['discount'], 2, '.', ''),
        ]);
    }
}
