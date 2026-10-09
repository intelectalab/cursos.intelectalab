<?php

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Domain\Models\Plan;

class PlanPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function plan(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'billing_months' => $plan->billing_months,
            // null = sin tope de alumnos
            'max_students' => $plan->max_students,
            'status' => $plan->status,
        ];
    }
}
