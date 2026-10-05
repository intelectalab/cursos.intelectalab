<?php

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Application\ActivityService;
use App\Modules\Academic\Domain\Models\Question;
use App\Modules\Academic\Domain\Models\QuestionBank;
use App\Modules\Academic\Domain\QuestionConfig;
use App\Modules\Academic\Http\AcademicPresenter;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuestionBankController extends Controller
{
    public function index(): JsonResponse
    {
        $banks = QuestionBank::query()->withCount('questions')->orderBy('name')->get();

        return ApiResponse::success(
            $banks->map(fn (QuestionBank $bank) => AcademicPresenter::bank($bank))->values(),
            ['question_types' => Question::TYPES]
        );
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $bank = QuestionBank::create($this->validatedBank($request, $context));

        return ApiResponse::success(AcademicPresenter::bank($bank), null, 201);
    }

    public function update(Request $request, QuestionBank $bank, TenantContext $context): JsonResponse
    {
        $bank->update($this->validatedBank($request, $context, $bank));

        return ApiResponse::success(AcademicPresenter::bank($bank));
    }

    public function destroy(QuestionBank $bank): JsonResponse
    {
        $inUse = $bank->questions()->whereHas('quizQuestions')->count();

        if ($inUse > 0) {
            throw new ApiException(
                ErrorCode::QuestionInUse,
                "No se puede eliminar: {$inUse} de sus preguntas se usan en cuestionarios.",
                409
            );
        }

        $bank->delete();

        return ApiResponse::success(null);
    }

    public function questions(Request $request, QuestionBank $bank): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(Question::TYPES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $questions = $bank->questions()
            ->withCount('quizQuestions')
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->whereRaw('lower(statement) like ?', ['%'.mb_strtolower($search).'%']))
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::paginated($questions, fn (Question $question) => AcademicPresenter::question($question));
    }

    public function storeQuestion(Request $request, QuestionBank $bank): JsonResponse
    {
        $type = $request->validate(['type' => ['required', Rule::in(Question::TYPES)]])['type'];

        $question = $bank->questions()->create(['type' => $type] + $this->validatedQuestion($request, $type));

        return ApiResponse::success(AcademicPresenter::question($question), null, 201);
    }

    /**
     * El tipo no cambia. Si la pregunta se usa en cuestionarios, sus cursos
     * quedan con cambios pendientes de publicar.
     */
    public function updateQuestion(Request $request, Question $question, ActivityService $activities): JsonResponse
    {
        $question->update($this->validatedQuestion($request, $question->type));
        $activities->markCoursesUsing($question);

        return ApiResponse::success(AcademicPresenter::question($question));
    }

    public function destroyQuestion(Question $question): JsonResponse
    {
        if ($question->quizQuestions()->exists()) {
            throw new ApiException(
                ErrorCode::QuestionInUse,
                'No se puede eliminar: la pregunta se usa en al menos un cuestionario. Quítala primero de ahí.',
                409
            );
        }

        $question->delete();

        return ApiResponse::success(null);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedBank(Request $request, TenantContext $context, ?QuestionBank $bank = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique('question_banks', 'name')->where('tenant_id', $context->tenantId())->ignore($bank),
            ],
            'description' => ['nullable', 'string', 'max:300'],
        ], ['name.unique' => 'Ya existe un banco con ese nombre.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedQuestion(Request $request, string $type): array
    {
        $data = $request->validate([
            'statement' => ['required', 'string', 'max:5000'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'config' => ['required', 'array'],
        ]);

        return [
            'statement' => $data['statement'],
            'explanation' => $data['explanation'] ?? null,
            'config' => QuestionConfig::normalize($type, $data['config']),
        ];
    }
}
