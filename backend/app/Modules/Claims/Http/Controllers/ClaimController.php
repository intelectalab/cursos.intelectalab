<?php

namespace App\Modules\Claims\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Claims\Application\ClaimService;
use App\Modules\Claims\Domain\Models\Claim;
use App\Modules\Claims\Http\ClaimPresenter;
use App\Shared\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Hojas del Libro de Reclamaciones que atiende el personal.
 */
class ClaimController extends Controller
{
    private const RELATIONS = ['history.author', 'messages.author'];

    public function __construct(private readonly ClaimService $claims) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            // PENDING = sin responder; OVERDUE = sin responder y fuera de plazo
            'status' => ['nullable', Rule::in([...Claim::STATUSES, 'PENDING', 'OVERDUE'])],
            'type' => ['nullable', Rule::in(Claim::TYPES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $pending = fn (Builder $q) => $q->where('status', '!=', Claim::STATUS_ANSWERED);
        $overdue = fn (Builder $q) => $pending($q)->whereDate('due_at', '<', now()->toDateString());

        $claims = Claim::query()
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => match ($status) {
                'PENDING' => $pending($q),
                'OVERDUE' => $overdue($q),
                default => $q->where('status', $status),
            })
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('type', $type))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';

                $q->where(fn (Builder $match) => $match
                    ->whereRaw('lower(code) like ?', [$like])
                    ->orWhereRaw('lower(doc_number) like ?', [$like])
                    ->orWhereRaw("lower(first_name || ' ' || last_name) like ?", [$like]));
            })
            // Lo pendiente primero, por la fecha límite más próxima
            ->orderByRaw("case when status = 'ANSWERED' then 1 else 0 end")
            ->orderByRaw("case when status = 'ANSWERED' then null else due_at end asc")
            ->orderByDesc('created_at')
            ->orderByDesc('number')
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::success(
            collect($claims->items())->map(fn (Claim $claim) => ClaimPresenter::summary($claim))->values(),
            [
                'page' => $claims->currentPage(),
                'per_page' => $claims->perPage(),
                'total' => $claims->total(),
                'last_page' => $claims->lastPage(),
                'counts' => [
                    'pending' => $pending(Claim::query())->count(),
                    'overdue' => $overdue(Claim::query())->count(),
                ],
            ]
        );
    }

    public function show(Claim $claim): JsonResponse
    {
        return $this->respondWith($claim);
    }

    public function startReview(Request $request, Claim $claim): JsonResponse
    {
        return $this->respondWith($this->claims->startReview($claim, $request->user()));
    }

    public function respond(Request $request, Claim $claim): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:10', 'max:5000']]);

        return $this->respondWith($this->claims->respond($claim, $request->user(), $data['body']));
    }

    public function storeNote(Request $request, Claim $claim): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        return $this->respondWith($this->claims->addNote($claim, $request->user(), $data['body']), 201);
    }

    private function respondWith(Claim $claim, int $status = 200): JsonResponse
    {
        return ApiResponse::success(ClaimPresenter::make($claim->load(self::RELATIONS), forStaff: true), null, $status);
    }
}
