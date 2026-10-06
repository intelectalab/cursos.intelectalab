<?php

namespace App\Modules\Sales\Http;

use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Courses\Http\CatalogPresenter;
use App\Modules\Sales\Domain\Models\Package;

class PackagePresenter
{
    /**
     * Para el personal. Espera courses cargados.
     *
     * @return array<string, mixed>
     */
    public static function make(Package $package, string $currency): array
    {
        $listPrice = $package->listPrice();

        return [
            'id' => $package->id,
            'title' => $package->title,
            'summary' => $package->summary,
            'price' => $package->price,
            'currency' => $currency,
            // Lo que costarían sus cursos por separado y cuánto se ahorra
            'list_price' => $listPrice,
            'savings' => number_format(max(0, (float) $listPrice - (float) $package->price), 2, '.', ''),
            'status' => $package->status,
            // Activo y con todos sus cursos todavía en venta
            'for_sale' => $package->isForSale(),
            'courses' => $package->courses->map(fn (Course $course) => [
                'id' => $course->id,
                'title' => $course->title,
                'price' => $course->price,
                'for_sale' => $course->isForSale(),
            ])->values(),
            'path' => "/catalog/packages/{$package->id}",
        ];
    }

    /**
     * Para el catálogo público: cada curso con su tarjeta. Espera courses.currentVersion cargados.
     *
     * @return array<string, mixed>
     */
    public static function catalog(Package $package, string $currency): array
    {
        return [
            'courses' => $package->courses->map(fn (Course $course) => CatalogPresenter::card($course, $currency))->values(),
        ] + self::make($package, $currency);
    }
}
