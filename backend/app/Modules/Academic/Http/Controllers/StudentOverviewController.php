<?php

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Domain\Models\QuizAttempt;
use App\Modules\Academic\Domain\Models\TaskSubmission;
use App\Modules\Enrollments\Application\ProgressService;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Http\ClassroomPresenter;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inicio del alumno: en qué va, qué tiene pendiente y sus últimas notas.
 */
class StudentOverviewController extends Controller
{
    private const LIMIT = 8;

    public function show(Request $request, ProgressService $progress, TenantSettingsService $settings): JsonResponse
    {
        $student = $request->user();

        $enrollments = Enrollment::query()
            ->where('student_id', $student->id)
            ->accessible()
            ->whereHas('course', fn ($course) => $course->whereNotNull('current_version_id'))
            ->with('course.currentVersion')
            ->orderByDesc('enrolled_at')
            ->get();

        $courses = [];
        $pending = [];
        $titles = [];

        foreach ($enrollments as $enrollment) {
            $summary = $progress->summary($enrollment);
            $version = $enrollment->course->currentVersion;

            $courses[] = ClassroomPresenter::summary($enrollment) + ['progress_percent' => $summary['percent']];

            foreach (ClassroomPresenter::activitiesOf($version) as $activity) {
                $titles[$activity['id']] = $activity['title'];

                if (! in_array($activity['id'], $summary['activity_ids'], true)) {
                    $pending[] = [
                        'course_id' => $enrollment->course_id,
                        'course_title' => $version->snapshot['title'],
                        'activity_id' => $activity['id'],
                        'type' => $activity['type'],
                        'title' => $activity['title'],
                    ];
                }
            }
        }

        $courseTitles = $enrollments->mapWithKeys(fn (Enrollment $enrollment) => [
            $enrollment->course_id => $enrollment->course->currentVersion->snapshot['title'],
        ]);

        $quizGrades = QuizAttempt::query()
            ->where('student_id', $student->id)
            ->whereIn('course_id', $courseTitles->keys())
            ->where('status', QuizAttempt::STATUS_SUBMITTED)
            ->latest('submitted_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (QuizAttempt $attempt) => [
                'type' => 'QUIZ',
                'course_id' => $attempt->course_id,
                'course_title' => $courseTitles[$attempt->course_id],
                'title' => $titles[$attempt->activity_id] ?? 'Cuestionario',
                'grade_percent' => $attempt->score_percent,
                'passed' => (bool) $attempt->passed,
                'at' => $attempt->submitted_at,
            ]);

        $taskGrades = TaskSubmission::query()
            ->where('student_id', $student->id)
            ->whereIn('course_id', $courseTitles->keys())
            ->where('status', TaskSubmission::STATUS_GRADED)
            ->latest('graded_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (TaskSubmission $submission) => [
                'type' => 'TASK',
                'course_id' => $submission->course_id,
                'course_title' => $courseTitles[$submission->course_id],
                'title' => $titles[$submission->activity_id] ?? 'Tarea',
                'grade_percent' => $submission->grade_percent,
                'passed' => null,
                'at' => $submission->graded_at,
            ]);

        return ApiResponse::success([
            'courses' => $courses,
            'pending' => array_slice($pending, 0, self::LIMIT),
            'pending_total' => count($pending),
            'recent_grades' => $quizGrades->concat($taskGrades)
                ->sortByDesc('at')
                ->take(self::LIMIT)
                ->map(fn (array $grade) => ['at' => $grade['at']->toIso8601String()] + $grade)
                ->values(),
        ], ['grade_scale' => $settings->gradeScale()]);
    }
}
