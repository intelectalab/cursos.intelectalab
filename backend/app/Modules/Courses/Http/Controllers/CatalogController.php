<?php

namespace App\Modules\Courses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Application\CourseCommunity;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Courses\Domain\Models\CourseReview;
use App\Modules\Courses\Http\CatalogPresenter;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Catálogo público de la organización (FP-02): los cursos en venta, visibles
 * sin iniciar sesión. Para comprar hace falta una cuenta.
 */
class CatalogController extends Controller
{
    public function __construct(private readonly TenantSettingsService $settings) {}

    public const PRICE_FREE = 'free';

    public const PRICE_PAID = 'paid';

    /**
     * Cursos en venta, con buscador y filtros (YC-WEB-002). Se filtra sobre
     * la versión publicada, que es lo que el visitante ve, no sobre el borrador.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:80'],
            'level' => ['nullable', Rule::in(Course::LEVELS)],
            'price' => ['nullable', Rule::in([self::PRICE_FREE, self::PRICE_PAID])],
        ]);

        $currency = $this->settings->currency();
        $courses = $this->query()->get();
        $ratings = CourseCommunity::ratings($courses->pluck('id'));
        $all = $courses
            ->map(fn (Course $course) => CatalogPresenter::card($course, $currency) + ['rating' => $ratings->get($course->id)])
            ->sortBy(fn (array $card) => self::plain($card['title']))
            ->values();

        return ApiResponse::success($this->filtered($all, $filters)->values(), [
            'currency' => $currency,
            // Con qué se puede filtrar: lo que hay en todo el catálogo, no solo en el resultado
            'categories' => $all->pluck('category')->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'levels' => array_values(array_intersect(Course::LEVELS, $all->pluck('level')->all())),
            'has_free' => $all->contains(fn (array $card) => (float) $card['price'] === 0.0),
            'has_paid' => $all->contains(fn (array $card) => (float) $card['price'] > 0),
            'total' => $all->count(),
        ]);
    }

    public function show(string $course): JsonResponse
    {
        $record = $this->query()->with('teachers')->whereKey($course)->first();

        if (! $record) {
            throw new ApiException(ErrorCode::NotFound, 'El curso no existe.', 404);
        }

        // Las reseñas visibles más recientes, con el nombre e inicial de quien la escribió
        $reviews = CourseReview::query()
            ->where('course_id', $record->id)
            ->where('status', CourseReview::STATUS_VISIBLE)
            ->with('student:id,first_name,last_name')
            ->latest('updated_at')
            ->limit(6)
            ->get();

        return ApiResponse::success(CatalogPresenter::detail($record, $this->settings->currency()) + [
            'rating' => CourseCommunity::ratings([$record->id])->get($record->id),
            'reviews' => $reviews->map(fn (CourseReview $review) => [
                'rating' => $review->rating,
                'comment' => $review->comment,
                'author' => CourseCommunity::publicName($review->student),
                'updated_at' => $review->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $cards
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filtered(Collection $cards, array $filters): Collection
    {
        // Cada palabra buscada debe aparecer en el título, el resumen o la categoría
        $words = array_filter(explode(' ', self::plain($filters['search'] ?? '')));

        return $cards
            ->when($words !== [], fn (Collection $list) => $list->filter(function (array $card) use ($words) {
                $text = self::plain("{$card['title']} {$card['summary']} {$card['category']}");

                return collect($words)->every(fn (string $word) => str_contains($text, $word));
            }))
            ->when($filters['category'] ?? null, fn (Collection $list, string $category) => $list->filter(
                fn (array $card) => self::plain((string) $card['category']) === self::plain($category)
            ))
            ->when($filters['level'] ?? null, fn (Collection $list, string $level) => $list->where('level', $level))
            ->when($filters['price'] ?? null, fn (Collection $list, string $price) => $list->filter(
                fn (array $card) => ((float) $card['price'] === 0.0) === ($price === self::PRICE_FREE)
            ));
    }

    /**
     * Texto sin mayúsculas ni tildes, para comparar como escribe la gente.
     */
    private static function plain(string $text): string
    {
        return Str::lower(Str::ascii(trim($text)));
    }

    /**
     * @return Builder<Course>
     */
    private function query(): Builder
    {
        return Course::query()
            ->where('status', Course::STATUS_PUBLISHED)
            ->whereNotNull('current_version_id')
            ->whereNotNull('price')
            ->with('currentVersion');
    }
}
