<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Domain\Models\TaskSubmission;
use App\Modules\Claims\Domain\Models\Claim;
use App\Modules\Cms\Domain\Models\Page;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderInstallment;
use App\Modules\Sales\Domain\Models\PaymentAttempt;
use App\Modules\Sales\Http\OrderPresenter;
use App\Modules\Support\Domain\Models\Ticket;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que espera a una persona del personal, en cifras, para la campana de
 * avisos y los pendientes de Inicio. Cada cifra solo se calcula si la
 * persona puede atender ese tema; las demás llegan en null.
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
            // Tareas entregadas y sin nota: todas para quien revisa todo, las de sus cursos para el docente
            'submissions_to_grade' => match (true) {
                $can('submissions.review') => TaskSubmission::query()->where('status', TaskSubmission::STATUS_SUBMITTED)->count(),
                $can('submissions.assigned.review') => TaskSubmission::query()->where('status', TaskSubmission::STATUS_SUBMITTED)
                    ->whereIn('course_id', Course::query()->whereHas('teachers', fn ($teachers) => $teachers->whereKey($user->id))->select('id'))
                    ->count(),
                default => null,
            },
            // Cuotas de ventas en curso que ya pasaron su fecha
            'overdue_installments' => $can('payments.confirm')
                ? OrderInstallment::query()->where('status', OrderInstallment::STATUS_PENDING)->where('due_date', '<', OrderPresenter::today())
                    ->whereIn('order_id', Order::query()->where('status', Order::STATUS_PARTIAL)->select('id'))->count()
                : null,
            // Hojas del Libro de Reclamaciones sin respuesta
            'claims_unanswered' => $can('claims.read')
                ? Claim::query()->where('status', '!=', Claim::STATUS_ANSWERED)->count()
                : null,
        ]);
    }
}
