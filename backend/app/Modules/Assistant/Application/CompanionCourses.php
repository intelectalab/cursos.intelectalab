<?php

namespace App\Modules\Assistant\Application;

use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderItem;
use Illuminate\Support\Facades\DB;

/**
 * «Quienes llevaron este curso también tomaron…»: los cursos que compraron
 * las mismas personas. Solo cuentan las compras pagadas o en cuotas (no las
 * canceladas, vencidas ni reembolsadas), y solo se sugieren cursos
 * publicados y a la venta.
 */
class CompanionCourses
{
    /** Personas en común que hacen falta para sugerir un curso */
    public const MIN_PEOPLE = 2;

    public const LIMIT = 4;

    /**
     * @param  list<string>  $courseIds
     * @return list<array{id: string, title: string, price: string, people: int}>
     */
    public function for(array $courseIds, int $limit = self::LIMIT): array
    {
        if ($courseIds === []) {
            return [];
        }

        $bought = fn () => OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', [Order::STATUS_PAID, Order::STATUS_PARTIAL]);

        // Quienes compraron alguno de los cursos dados
        $buyers = $bought()->whereIn('order_items.course_id', $courseIds)->select('orders.customer_id');

        $rows = $bought()
            ->whereIn('orders.customer_id', $buyers)
            ->whereNotIn('order_items.course_id', $courseIds)
            ->select('order_items.course_id', DB::raw('count(distinct orders.customer_id) as people'))
            ->groupBy('order_items.course_id')
            ->havingRaw('count(distinct orders.customer_id) >= ?', [self::MIN_PEOPLE])
            ->orderByDesc('people')
            ->get()
            ->pluck('people', 'course_id');

        if ($rows->isEmpty()) {
            return [];
        }

        return Course::query()
            ->whereIn('id', $rows->keys())
            ->where('status', Course::STATUS_PUBLISHED)
            ->whereNotNull('current_version_id')
            ->whereNotNull('price')
            ->with('currentVersion')
            ->get()
            ->map(fn (Course $course) => [
                'id' => $course->id,
                // Lo que ve el comprador es la versión publicada
                'title' => $course->currentVersion->snapshot['title'] ?? $course->title,
                'price' => number_format((float) $course->price, 2, '.', ''),
                'people' => (int) $rows[$course->id],
            ])
            ->sortBy([['people', 'desc'], ['title', 'asc']])
            ->take($limit)
            ->values()
            ->all();
    }
}
