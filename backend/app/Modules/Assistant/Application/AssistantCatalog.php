<?php

namespace App\Modules\Assistant\Application;

use App\Modules\Courses\Application\CourseAccess;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Package;
use App\Modules\Tenancy\Application\TenantSettingsService;

/**
 * Lo que el asistente puede buscar, liviano para guardarlo en el navegador:
 * los cursos y paquetes que la persona puede ver. El personal ve también los
 * borradores; el docente, solo sus cursos; los demás, lo que está a la venta.
 */
class AssistantCatalog
{
    public function __construct(
        private readonly CourseAccess $access,
        private readonly TenantSettingsService $settings,
    ) {}

    /**
     * @return array{currency: string, timezone: string, staff: bool, items: list<array<string, mixed>>}
     */
    public function for(User $user): array
    {
        $staff = $user->hasPermission('courses.read') || $user->hasPermission('courses.assigned.read');

        $courses = $staff
            ? $this->access->scope(Course::query(), $user)
                ->where('status', '!=', Course::STATUS_ARCHIVED)
                ->orderBy('title')
                ->get(['id', 'title', 'summary', 'category', 'status', 'price'])
                ->map(fn (Course $course) => self::item('COURSE', $course->id, $course->title, $course->summary, $course->category, $course->price, $course->status))
            : Course::query()
                ->where('status', Course::STATUS_PUBLISHED)
                ->whereNotNull('current_version_id')
                ->whereNotNull('price')
                ->with('currentVersion')
                ->get()
                // Lo que ve el comprador es la versión publicada, no el borrador
                ->map(fn (Course $course) => self::item(
                    'COURSE',
                    $course->id,
                    $course->currentVersion->snapshot['title'] ?? $course->title,
                    $course->currentVersion->snapshot['summary'] ?? null,
                    $course->currentVersion->snapshot['category'] ?? null,
                    $course->price,
                    $course->status,
                ))
                ->sortBy('title')
                ->values();

        $packages = Package::query()
            ->when(! $user->hasPermission('packages.read'), fn ($query) => $query->where('status', Package::STATUS_ACTIVE))
            ->orderBy('title')
            ->get(['id', 'title', 'summary', 'price', 'status'])
            ->map(fn (Package $package) => self::item('PACKAGE', $package->id, $package->title, $package->summary, null, $package->price, $package->status));

        return [
            'currency' => $this->settings->currency(),
            // Para que «hoy» o «este mes» se cuenten en la hora de la academia, no en la del navegador
            'timezone' => $this->settings->timezone(),
            'staff' => $staff,
            'items' => $courses->concat($packages)->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(string $type, string $id, string $title, ?string $summary, ?string $category, mixed $price, string $status): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'title' => $title,
            'summary' => $summary,
            'category' => $category,
            // null = no está a la venta (solo matrícula manual); "0.00" = gratuito
            'price' => $price === null ? null : number_format((float) $price, 2, '.', ''),
            'status' => $status,
        ];
    }
}
