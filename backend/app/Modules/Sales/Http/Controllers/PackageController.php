<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Sales\Domain\Models\Package;
use App\Modules\Sales\Http\PackagePresenter;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Paquetes de cursos (RF-PKG-001): el personal los arma; el público los ve
 * en el catálogo mientras estén activos y todos sus cursos sigan en venta.
 */
class PackageController extends Controller
{
    public function __construct(
        private readonly TenantSettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        $currency = $this->settings->currency();
        $packages = Package::query()->with('courses')->orderBy('title')->get();

        return ApiResponse::success($packages->map(fn (Package $package) => PackagePresenter::make($package, $currency))->values(), ['currency' => $currency]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $data = $this->validated($request, $context);

        $package = DB::transaction(function () use ($request, $data) {
            $package = Package::create(collect($data)->only(['title', 'summary', 'price', 'status'])->all() + ['created_by' => $request->user()->id]);
            $this->syncCourses($package, $data['course_ids']);

            $this->audit->record('packages.created', $package, null, ['title' => $package->title, 'price' => $package->price, 'courses' => count($data['course_ids'])]);

            return $package;
        });

        return $this->respond($package, 201);
    }

    public function show(Package $package): JsonResponse
    {
        return $this->respond($package);
    }

    /**
     * Las órdenes ya creadas conservan el precio y los cursos que tenía el paquete al comprarlo.
     */
    public function update(Request $request, Package $package, TenantContext $context): JsonResponse
    {
        $data = $this->validated($request, $context);

        DB::transaction(function () use ($package, $data) {
            $before = $package->only(['title', 'price', 'status']);

            $package->update(collect($data)->only(['title', 'summary', 'price', 'status'])->all());
            $this->syncCourses($package, $data['course_ids']);

            $this->audit->record('packages.updated', $package, $before, $package->only(['title', 'price', 'status']) + ['courses' => count($data['course_ids'])]);
        });

        return $this->respond($package);
    }

    /**
     * Catálogo público: paquetes en venta.
     */
    public function catalog(): JsonResponse
    {
        $currency = $this->settings->currency();

        $packages = Package::query()
            ->where('status', Package::STATUS_ACTIVE)
            ->with('courses.currentVersion')
            ->orderBy('title')
            ->get()
            ->filter(fn (Package $package) => $package->isForSale())
            ->values();

        return ApiResponse::success($packages->map(fn (Package $package) => PackagePresenter::catalog($package, $currency)), ['currency' => $currency]);
    }

    public function catalogShow(string $package): JsonResponse
    {
        return ApiResponse::success(PackagePresenter::catalog($this->forSale($package), $this->settings->currency()));
    }

    /**
     * Checkout de un paquete: lo que ve quien ya tiene sesión, con los cursos que ya son suyos.
     */
    public function checkout(Request $request, string $package): JsonResponse
    {
        $record = $this->forSale($package);

        $owned = Enrollment::query()
            ->where('student_id', $request->user()->id)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->whereIn('course_id', $record->courses->pluck('id'))
            ->pluck('course_id');

        return ApiResponse::success(PackagePresenter::catalog($record, $this->settings->currency()) + [
            // Ya está matriculado en estos: el paquete solo le dará los demás
            'owned_course_ids' => $owned->values(),
        ]);
    }

    private function forSale(string $package): Package
    {
        $record = Package::query()->with('courses.currentVersion')->find($package);

        if (! $record || ! $record->isForSale()) {
            throw new ApiException(ErrorCode::NotFound, 'El paquete no existe o ya no está en venta.', 404);
        }

        return $record;
    }

    private function respond(Package $package, int $status = 200): JsonResponse
    {
        return ApiResponse::success(PackagePresenter::make($package->load('courses'), $this->settings->currency()), null, $status);
    }

    /**
     * @param  list<string>  $courseIds
     */
    private function syncCourses(Package $package, array $courseIds): void
    {
        $tenant = $package->tenant_id;

        $package->courses()->sync(collect(array_values($courseIds))->mapWithKeys(fn (string $id, int $position) => [
            $id => ['id' => (string) str()->uuid7(), 'tenant_id' => $tenant, 'position' => $position, 'created_at' => now(), 'updated_at' => now()],
        ])->all());

        $package->unsetRelation('courses');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, TenantContext $context): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'status' => ['required', Rule::in(Package::STATUSES)],
            'course_ids' => ['required', 'array', 'min:'.Package::MIN_COURSES, 'max:30'],
            'course_ids.*' => ['uuid', 'distinct', Rule::exists('courses', 'id')->where('tenant_id', $context->tenantId())],
        ], [
            'course_ids.min' => 'Un paquete reúne al menos dos cursos.',
        ]);

        // Para venderse, todos sus cursos deben estar publicados y con precio
        if ($data['status'] === Package::STATUS_ACTIVE) {
            $notForSale = Course::query()->whereIn('id', $data['course_ids'])->get()->reject(fn (Course $course) => $course->isForSale());

            if ($notForSale->isNotEmpty()) {
                throw new ApiException(ErrorCode::ValidationError, 'Para activar el paquete, todos sus cursos deben estar publicados y tener precio.', 422, [
                    'course_ids' => $notForSale->map(fn (Course $course) => "\"{$course->title}\" no está en venta.")->values()->all(),
                ]);
            }
        }

        return $data;
    }
}
