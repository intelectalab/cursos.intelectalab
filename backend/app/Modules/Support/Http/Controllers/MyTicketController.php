<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Support\Application\TicketService;
use App\Modules\Support\Domain\Models\Ticket;
use App\Modules\Support\Http\TicketPresenter;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tickets de quien tiene la sesión: los abre, los sigue y responde. Un ticket
 * ajeno no existe para él.
 */
class MyTicketController extends Controller
{
    public const RELATIONS = ['requester', 'assignee', 'course', 'order', 'messages.author', 'messages.attachments.file'];

    public function __construct(
        private readonly TicketService $tickets,
        private readonly FileStorage $storage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tickets = Ticket::query()
            ->where('requester_id', $request->user()->id)
            ->with(['requester', 'assignee'])
            ->orderByDesc('last_message_at')
            ->get();

        return ApiResponse::success($tickets->map(fn (Ticket $ticket) => TicketPresenter::summary($ticket))->values());
    }

    /**
     * Lo que hace falta para abrir un ticket: categorías, adjuntos permitidos
     * y los cursos y compras propios a los que puede referirse.
     */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            'categories' => Ticket::CATEGORIES,
            'attachments' => [
                'max_files' => Ticket::MAX_ATTACHMENTS,
                'max_kb' => config('files.limits.'.config('files.purposes.'.Ticket::FILE_PURPOSE.'.limit')),
                'extensions' => config('files.purposes.'.Ticket::FILE_PURPOSE.'.extensions'),
            ],
            'courses' => Enrollment::query()
                ->where('student_id', $user->id)
                ->with('course:id,title')
                ->get()
                ->map(fn (Enrollment $enrollment) => ['id' => $enrollment->course_id, 'title' => $enrollment->course->title])
                ->unique('id')
                ->sortBy('title')
                ->values(),
            'orders' => Order::query()
                ->where('customer_id', $user->id)
                ->orderByDesc('number')
                ->get(['id', 'number'])
                ->map(fn (Order $order) => ['id' => $order->id, 'number' => $order->number])
                ->values(),
        ]);
    }

    /**
     * Maestro §7.2 (POST /tickets).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:5', 'max:200'],
            'category' => ['required', Rule::in(Ticket::CATEGORIES)],
            'course_id' => ['nullable', 'uuid'],
            'order_id' => ['nullable', 'uuid'],
        ] + $this->messageRules());

        $ticket = $this->tickets->open($request->user(), $data, $request->file('files', []));

        return $this->respond($ticket, 201);
    }

    public function show(Request $request, string $ticket): JsonResponse
    {
        return $this->respond($this->own($request, $ticket));
    }

    public function reply(Request $request, string $ticket): JsonResponse
    {
        $data = $request->validate($this->messageRules());

        $ticket = $this->tickets->replyAsRequester($this->own($request, $ticket), $request->user(), $data['body'], $request->file('files', []));

        return $this->respond($ticket, 201);
    }

    public function close(Request $request, string $ticket): JsonResponse
    {
        return $this->respond($this->tickets->closeByRequester($this->own($request, $ticket)));
    }

    /**
     * @return array<string, mixed>
     */
    private function messageRules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'files' => ['sometimes', 'array', 'max:'.Ticket::MAX_ATTACHMENTS],
            'files.*' => $this->storage->rules(Ticket::FILE_PURPOSE),
        ];
    }

    private function own(Request $request, string $ticket): Ticket
    {
        return Ticket::query()->where('requester_id', $request->user()->id)->findOrFail($ticket);
    }

    private function respond(Ticket $ticket, int $status = 200): JsonResponse
    {
        return ApiResponse::success(TicketPresenter::make($ticket->load(self::RELATIONS), forStaff: false), null, $status);
    }
}
