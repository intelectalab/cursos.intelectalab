<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Support\Application\TicketService;
use App\Modules\Support\Domain\Models\Ticket;
use App\Modules\Support\Domain\Models\TicketAttachment;
use App\Modules\Support\Http\TicketPresenter;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\StateCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bandeja de soporte: el personal ve todos los tickets, responde, deja notas
 * internas, asigna y cambia el estado.
 */
class TicketController extends Controller
{
    /** Vistas de las tarjetas, además de cada estado */
    private const VIEWS = ['PENDING', 'AWAITING', 'UNASSIGNED', 'MINE'];

    public function __construct(
        private readonly TicketService $tickets,
        private readonly FileStorage $storage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            // Vistas de las tarjetas: PENDING = abiertos y en atención; AWAITING = esperan respuesta;
            // UNASSIGNED = por atender sin responsable; MINE = por atender a mi cargo
            'status' => ['nullable', Rule::in([...Ticket::STATUSES, ...self::VIEWS])],
            'category' => ['nullable', Rule::in(Ticket::CATEGORIES)],
            // "me" = los míos; "none" = sin asignar; o el id de alguien del equipo
            'assignee' => ['nullable', 'string', 'max:36'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $byState = function (Builder $q, string $status) use ($request) {
            $pending = fn () => $q->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS]);

            match ($status) {
                'PENDING' => $pending(),
                'AWAITING' => $pending()->where('last_message_by', Ticket::BY_REQUESTER),
                'UNASSIGNED' => $pending()->whereNull('assignee_id'),
                'MINE' => $pending()->where('assignee_id', $request->user()->id),
                default => $q->where('status', $status),
            };
        };

        $base = Ticket::query()
            ->when($filters['category'] ?? null, fn (Builder $q, string $category) => $q->where('category', $category))
            ->when($filters['assignee'] ?? null, fn (Builder $q, string $assignee) => match ($assignee) {
                'none' => $q->whereNull('assignee_id'),
                'me' => $q->where('assignee_id', $request->user()->id),
                default => $q->where('assignee_id', $assignee),
            })
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $number = ctype_digit($search) ? (int) $search : null;

                $q->where(fn (Builder $match) => $match
                    ->whereRaw('lower(subject) like ?', [$like])
                    ->when($number, fn (Builder $byNumber) => $byNumber->orWhere('number', $number))
                    ->orWhereHas('requester', fn (Builder $requester) => $requester
                        ->whereRaw('lower(email) like ?', [$like])
                        ->orWhereRaw("lower(first_name || ' ' || last_name) like ?", [$like])));
            });

        // Cuántos hay en cada vista con la búsqueda actual: alimenta las tarjetas que filtran
        $states = StateCounts::of($base, [...self::VIEWS, Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED], $byState);

        $tickets = $base
            ->with(['requester', 'assignee'])
            ->when($filters['status'] ?? null, $byState)
            // Primero los que esperan respuesta del personal, del más antiguo al más reciente
            ->orderByRaw("case when status in ('OPEN', 'IN_PROGRESS') and last_message_by = 'REQUESTER' then 0 else 1 end")
            ->orderByRaw("case when status in ('OPEN', 'IN_PROGRESS') and last_message_by = 'REQUESTER' then last_message_at end asc")
            ->orderByDesc('last_message_at')
            ->orderBy('number')
            ->paginate($filters['per_page'] ?? 50);

        $pending = Ticket::query()->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS]);

        return ApiResponse::success(
            collect($tickets->items())->map(fn (Ticket $ticket) => TicketPresenter::summary($ticket))->values(),
            [
                'page' => $tickets->currentPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
                'last_page' => $tickets->lastPage(),
                // Cifras de la bandeja, sin importar los filtros
                'states' => $states,
                'counts' => [
                    'awaiting' => (clone $pending)->where('last_message_by', Ticket::BY_REQUESTER)->count(),
                    'unassigned' => (clone $pending)->whereNull('assignee_id')->count(),
                    'mine' => (clone $pending)->where('assignee_id', $request->user()->id)->count(),
                ],
            ]
        );
    }

    /**
     * Personal que puede atender tickets, para asignar.
     */
    public function agents(): JsonResponse
    {
        return ApiResponse::success($this->tickets->agents()->get()->map(fn (User $user) => [
            'id' => $user->id,
            'name' => trim("{$user->first_name} {$user->last_name}"),
        ])->values());
    }

    public function show(Ticket $ticket): JsonResponse
    {
        return $this->respond($ticket);
    }

    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'assignee_id' => ['sometimes', 'nullable', 'uuid'],
            'priority' => ['sometimes', Rule::in(Ticket::PRIORITIES)],
            'category' => ['sometimes', Rule::in(Ticket::CATEGORIES)],
        ]);

        return $this->respond($this->tickets->update($ticket, $data));
    }

    public function reply(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'is_internal' => ['sometimes', 'boolean'],
            'files' => ['sometimes', 'array', 'max:'.Ticket::MAX_ATTACHMENTS],
            'files.*' => $this->storage->rules(Ticket::FILE_PURPOSE),
        ]);

        $ticket = $this->tickets->replyAsStaff($ticket, $request->user(), $data['body'], $request->file('files', []), $request->boolean('is_internal'));

        return $this->respond($ticket, 201);
    }

    public function changeStatus(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(Ticket::STATUSES)]]);

        return $this->respond($this->tickets->changeStatus($ticket, $data['status']));
    }

    /**
     * Descarga de un adjunto. Lo ve el personal de soporte, o quien abrió el
     * ticket si no pertenece a una nota interna; para los demás no existe.
     */
    public function attachment(Request $request, TicketAttachment $attachment): StreamedResponse
    {
        $user = $request->user();
        $message = $attachment->message;

        $isStaff = $user->hasPermission('tickets.read');
        $isRequester = $message->ticket->requester_id === $user->id && ! $message->is_internal;

        abort_unless($isStaff || $isRequester, 404);

        return $this->storage->response($attachment->file, download: true);
    }

    private function respond(Ticket $ticket, int $status = 200): JsonResponse
    {
        return ApiResponse::success(TicketPresenter::make($ticket->load(MyTicketController::RELATIONS), forStaff: true), null, $status);
    }
}
