<?php

namespace App\Modules\Schedules\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Schedules\Application\ScheduleService;
use App\Modules\Schedules\Domain\Models\TeacherSchedule;
use App\Modules\Schedules\Http\SchedulePresenter;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Mi horario» del docente: lo ve, lo confirma o lo observa. No lo edita.
 * Y las clases en vivo que ve el alumno de sus cursos.
 */
class MyScheduleController extends Controller
{
    public function __construct(
        private readonly ScheduleService $schedules,
        private readonly TenantSettingsService $settings,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $schedule = TeacherSchedule::query()->where('teacher_id', $request->user()->id)->with('sender')->first();

        return ApiResponse::success(
            SchedulePresenter::schedule($schedule, SchedulePresenter::courseTitles(collect([$schedule])->filter())),
            ['timezone' => $this->settings->timezone()]
        );
    }

    public function confirm(Request $request): JsonResponse
    {
        $schedule = $this->schedules->confirm($request->user());

        return ApiResponse::success(SchedulePresenter::schedule($schedule, SchedulePresenter::courseTitles(collect([$schedule]))));
    }

    public function observe(Request $request): JsonResponse
    {
        $data = $request->validate(['observation' => ['required', 'string', 'min:5', 'max:1000']], [], ['observation' => 'observación']);

        $schedule = $this->schedules->observe($request->user(), trim($data['observation']));

        return ApiResponse::success(SchedulePresenter::schedule($schedule, SchedulePresenter::courseTitles(collect([$schedule]))));
    }

    public function liveClasses(Request $request): JsonResponse
    {
        return ApiResponse::success($this->schedules->liveClassesFor($request->user()), ['timezone' => $this->settings->timezone()]);
    }
}
