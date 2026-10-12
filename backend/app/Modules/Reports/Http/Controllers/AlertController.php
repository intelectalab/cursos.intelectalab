<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Domain\Models\Page;
use App\Modules\Sales\Domain\Models\PaymentAttempt;
use App\Modules\Support\Domain\Models\Ticket;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que espera a una persona del personal, en cifras, para la campana de
 * avisos. Cada cifra solo se calcula si la persona puede atender ese tema;
 * las demás llegan en null.
 */
class AlertController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $can = fn (string ...$permissions) => collect($permissions)->contains(fn (string $permission) => $user->hasPermission($permission));

        return ApiResponse::success([
            // Cobros que una pasarela hizo y no se aplicaron a su orden
            'payment_reviews' => $can('payments.confirm', 'orders.read')
                ? PaymentAttempt::query()->where('status', PaymentAttempt::STATUS_NEEDS_REVIEW)->count()
                : null,
            // Consultas abiertas en las que lo último lo escribió quien consulta
            'tickets_waiting' => $can('tickets.read')
                ? Ticket::query()->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS])->where('last_message_by', Ticket::BY_REQUESTER)->count()
                : null,
            // Páginas que alguien envió a revisión y esperan a quien publica
            'pages_in_review' => $can('cms.publish')
                ? Page::query()->where('review_status', Page::REVIEW_PENDING)->count()
                : null,
        ]);
    }
}
