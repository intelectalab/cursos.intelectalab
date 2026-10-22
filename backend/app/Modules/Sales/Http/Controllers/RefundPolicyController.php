<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Application\RefundPolicy;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración → Política de reembolsos, y su publicación para compradores.
 */
class RefundPolicyController extends Controller
{
    public function __construct(private readonly RefundPolicy $policy) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->policy->current() + [
            'template' => $this->policy->template($request->attributes->get('tenant')),
            'default_days' => RefundPolicy::DEFAULT_DAYS,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:10000'],
            'days' => ['nullable', 'integer', 'between:1,'.RefundPolicy::MAX_DAYS],
        ], [], ['text' => 'texto de la política', 'days' => 'plazo']);

        return ApiResponse::success($this->policy->save($request->attributes->get('tenant'), $data['text'] ?? null, $data['days'] ?? null));
    }

    /**
     * La política publicada, para el checkout y la página pública. Sin política, null.
     */
    public function published(): JsonResponse
    {
        $current = $this->policy->current();

        return ApiResponse::success($current['text'] === null ? null : ['text' => $current['text'], 'days' => $current['days']]);
    }
}
