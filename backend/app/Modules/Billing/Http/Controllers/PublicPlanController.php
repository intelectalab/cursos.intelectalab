<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Domain\Models\Plan;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Planes que se muestran en la portada de la plataforma, sin iniciar sesión:
 * solo los vigentes, y los términos de la prueba gratuita con la que nace
 * toda academia.
 */
class PublicPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::query()->where('status', Plan::STATUS_ACTIVE)->orderBy('price')->get();

        return ApiResponse::success([
            'plans' => $plans->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'price' => (float) $plan->price,
                'currency' => $plan->currency,
                'billing_months' => $plan->billing_months,
                'max_students' => $plan->max_students,
            ])->values(),
            'trial' => [
                'days' => (int) config('billing.trial_days'),
                'max_students' => (int) config('billing.trial_max_students'),
            ],
        ]);
    }
}
