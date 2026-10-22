<?php

namespace App\Modules\Assistant\Application;

use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderItem;
use App\Modules\Sales\Domain\Models\Package;
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
     * @return list<array{type: string, id: string, title: string, price: string, people: int}>
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

        $courses = $rows->isEmpty() ? collect() : Course::query()
            ->whereIn('id', $rows->keys())
            ->where('status', Course::STATUS_PUBLISHED)
            ->whereNotNull('current_version_id')
            ->whereNotNull('price')
            ->with('currentVersion')
            ->get()
            ->map(fn (Course $course) => [
                'type' => 'COURSE',
                'id' => $course->id,
                // Lo que ve el comprador es la versión publicada
                'title' => $course->currentVersion->snapshot['title'] ?? $course->title,
                'price' => number_format((float) $course->price, 2, '.', ''),
                'people' => (int) $rows[$course->id],
            ]);

        // Paquetes que compraron las mismas personas (como paquete, no curso por curso)
        $packageRows = Order::query()
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_PARTIAL])
            ->whereNotNull('package_id')
            ->whereIn('customer_id', $buyers)
            ->select('package_id', DB::raw('count(distinct customer_id) as people'))
            ->groupBy('package_id')
            ->havingRaw('count(distinct customer_id) >= ?', [self::MIN_PEOPLE])
            ->pluck('people', 'package_id');

        $packages = $packageRows->isEmpty() ? collect() : Package::query()
            ->whereIn('id', $packageRows->keys())
            ->where('status', Package::STATUS_ACTIVE)
            // Un paquete que ya trae el curso que se está mirando no es una sugerencia nueva
            ->whereDoesntHave('courses', fn ($included) => $included->whereIn('courses.id', $courseIds))
            ->get(['id', 'title', 'price'])
            ->map(fn (Package $package) => [
                'type' => 'PACKAGE',
                'id' => $package->id,
                'title' => $package->title,
                'price' => number_format((float) $package->price, 2, '.', ''),
                'people' => (int) $packageRows[$package->id],
            ]);

        return $courses->concat($packages)
            ->sortBy([['people', 'desc'], ['title', 'asc']])
            ->take($limit)
            ->values()
            ->all();
    }
}
