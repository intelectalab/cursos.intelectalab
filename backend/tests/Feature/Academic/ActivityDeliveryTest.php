<?php

namespace Tests\Feature\Academic;

use App\Modules\Academic\Domain\Models\QuizAttempt;
use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Prueba académica de punta a punta (criterio de salida de S3): un alumno
 * matriculado rinde un cuestionario, entrega una tarea, responde una
 * encuesta y su avance se calcula en el servidor.
 */
class ActivityDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const JSON = ['Accept' => 'application/json'];

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private Tenant $tenant;

    private string $admin;

    private string $course;

    private string $section;

    private string $resource;

    private string $quiz;

    private string $task;

    private string $survey;

    private User $student;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('files.disk'));
        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');

        $this->buildCourse();

        $this->student = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => 'Ana', 'last_name' => 'Alumna', 'email' => 'ana@acme.com']);
        $this->enroll($this->student);
        $this->token = $this->login('acme', 'ana@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function me(string $path = ''): string
    {
        return $this->api("/me/courses/{$this->course}{$path}");
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function enroll(User $student): void
    {
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/enrollments"), ['student_ids' => [$student->id]])->assertCreated();
    }

    /**
     * Curso publicado con una lección, un cuestionario de dos preguntas
     * (3 y 1 puntos, aprueba con 55 %, 2 intentos), una tarea y una encuesta.
     */
    private function buildCourse(): void
    {
        $admin = fn () => $this->withToken($this->admin);

        $this->course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es'])->json('data.id');
        $this->section = $admin()->postJson($this->api("/courses/{$this->course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $this->resource = $admin()->postJson($this->api("/courses/{$this->course}/sections/{$this->section}/resources"), [
            'type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.',
        ])->json('data.id');

        $bank = $admin()->postJson($this->api('/question-banks'), ['name' => 'Excel'])->json('data.id');
        $single = $admin()->postJson($this->api("/question-banks/{$bank}/questions"), [
            'type' => 'SINGLE', 'statement' => '¿Qué función busca un valor?', 'explanation' => 'BUSCARV busca en la primera columna.',
            'config' => ['options' => [['id' => 'a', 'text' => 'SUMA', 'correct' => false], ['id' => 'b', 'text' => 'BUSCARV', 'correct' => true]]],
        ])->json('data.id');
        $boolean = $admin()->postJson($this->api("/question-banks/{$bank}/questions"), [
            'type' => 'TRUE_FALSE', 'statement' => 'Excel es una hoja de cálculo.', 'config' => ['answer' => true],
        ])->json('data.id');

        $activities = $this->api("/courses/{$this->course}/sections/{$this->section}/activities");
        $this->quiz = $admin()->postJson($activities, ['type' => 'QUIZ', 'title' => 'Evaluación', 'passing_percent' => 55, 'max_attempts' => 2])->json('data.id');
        $admin()->putJson($this->api("/courses/{$this->course}/activities/{$this->quiz}/questions"), [
            'items' => [['question_id' => $single, 'points' => 3], ['question_id' => $boolean, 'points' => 1]],
        ])->assertOk();
        $this->task = $admin()->postJson($activities, [
            'type' => 'TASK', 'title' => 'Presupuesto', 'description' => 'Arma un presupuesto.', 'allow_text' => true, 'allow_file' => true,
        ])->json('data.id');
        $this->survey = $admin()->postJson($activities, ['type' => 'SURVEY', 'title' => 'Satisfacción', 'questions' => [
            ['id' => 'util', 'type' => 'SCALE', 'text' => '¿Qué tan útil fue?'],
            ['id' => 'canal', 'type' => 'CHOICE', 'text' => '¿Cómo llegaste?', 'options' => ['Redes', 'Recomendación'], 'required' => false],
            ['id' => 'mejora', 'type' => 'TEXT', 'text' => '¿Qué mejorarías?', 'required' => false],
        ]])->json('data.id');

        $admin()->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();
    }

    /**
     * Abre un intento y devuelve su id y los ids de pregunta por tipo.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function startAttempt(?string $token = null): array
    {
        $attempt = $this->withToken($token ?? $this->token)->postJson($this->me("/activities/{$this->quiz}/attempts"))->assertCreated()->json('data');

        return [$attempt['id'], collect($attempt['questions'])->pluck('id', 'type')->all()];
    }

    public function test_quiz_is_taken_and_graded_on_the_server_without_leaking_answers(): void
    {
        $opened = $this->withToken($this->token)->postJson($this->me("/activities/{$this->quiz}/attempts"))
            ->assertCreated()
            ->assertJsonPath('data.number', 1)
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonCount(2, 'data.questions')
            ->json('data');

        // Las preguntas llegan sin la respuesta correcta
        $payload = json_encode($opened, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('correct', $payload);
        $this->assertStringNotContainsString('"answer"', $payload);
        $this->assertStringNotContainsString('BUSCARV busca', $payload);

        // Abrir de nuevo retoma el mismo intento
        $this->withToken($this->token)->postJson($this->me("/activities/{$this->quiz}/attempts"))->assertCreated()->assertJsonPath('data.id', $opened['id']);

        $ids = collect($opened['questions'])->pluck('id', 'type');

        // Acierta la de 3 puntos y falla la de 1: 75 %. El cliente no puede dictar la nota.
        $this->withToken($this->token)->postJson($this->api("/me/attempts/{$opened['id']}/submit"), [
            'answers' => [$ids['SINGLE'] => 'b', $ids['TRUE_FALSE'] => false],
            'score_percent' => 100, 'passed' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.score_percent', 75)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.answers_revealed', true)
            ->assertJsonPath('data.questions.0.score', 1)
            ->assertJsonPath('data.questions.0.explanation', 'BUSCARV busca en la primera columna.')
            ->assertJsonPath('data.questions.1.score', 0)
            ->assertJsonPath('data.questions.1.answer', false);

        // Un intento cerrado no se puede volver a enviar
        $this->withToken($this->token)->postJson($this->api("/me/attempts/{$opened['id']}/submit"), ['answers' => []])
            ->assertStatus(409)->assertJsonPath('code', 'ATTEMPT_CLOSED');

        $this->withToken($this->token)->getJson($this->me('/activities'))
            ->assertOk()
            ->assertJsonPath("data.{$this->quiz}.passed", true)
            ->assertJsonPath("data.{$this->quiz}.best_percent", 75)
            ->assertJsonPath("data.{$this->quiz}.attempts_left", 1)
            ->assertJsonPath("data.{$this->quiz}.open_attempt_id", null);
    }

    public function test_best_attempt_counts_and_attempts_are_limited(): void
    {
        [$first, $ids] = $this->startAttempt();

        // Primer intento reprobado: aún no se muestran las respuestas correctas
        $this->withToken($this->token)->postJson($this->api("/me/attempts/{$first}/submit"), ['answers' => [$ids['TRUE_FALSE'] => true]])
            ->assertOk()
            ->assertJsonPath('data.score_percent', 25)
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.answers_revealed', false)
            ->assertJsonPath('data.questions.0.correct', null);

        [$second] = $this->startAttempt();
        $this->assertNotSame($first, $second);
        $this->withToken($this->token)->postJson($this->api("/me/attempts/{$second}/submit"), ['answers' => []])
            ->assertOk()
            ->assertJsonPath('data.score_percent', 0)
            // Sin intentos restantes ya se pueden ver
            ->assertJsonPath('data.answers_revealed', true);

        $this->withToken($this->token)->postJson($this->me("/activities/{$this->quiz}/attempts"))
            ->assertStatus(409)->assertJsonPath('code', 'NO_ATTEMPTS_LEFT');

        $this->withToken($this->token)->getJson($this->me('/activities'))
            ->assertJsonPath("data.{$this->quiz}.best_percent", 25)
            ->assertJsonPath("data.{$this->quiz}.passed", false)
            ->assertJsonPath("data.{$this->quiz}.attempts_left", 0)
            ->assertJsonCount(2, "data.{$this->quiz}.attempts");

        // El primer intento, consultado ahora, ya muestra las respuestas
        $this->withToken($this->token)->getJson($this->api("/me/attempts/{$first}"))
            ->assertOk()->assertJsonPath('data.answers_revealed', true);
    }

    public function test_time_limit_is_enforced_by_the_server(): void
    {
        $this->withToken($this->admin)->putJson($this->api("/courses/{$this->course}/activities/{$this->quiz}"), [
            'title' => 'Evaluación', 'passing_percent' => 55, 'max_attempts' => 2, 'time_limit_minutes' => 10,
        ])->assertOk();
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();

        [$attempt, $ids] = $this->startAttempt();
        $this->withToken($this->token)->getJson($this->api("/me/attempts/{$attempt}"))->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');

        $this->travel(12)->minutes();

        $this->withToken($this->token)->postJson($this->api("/me/attempts/{$attempt}/submit"), ['answers' => [$ids['SINGLE'] => 'b', $ids['TRUE_FALSE'] => true]])
            ->assertStatus(409)->assertJsonPath('code', 'ATTEMPT_CLOSED');

        // El intento vencido cuenta como usado, con nota cero
        $this->withToken($this->token)->getJson($this->me('/activities'))
            ->assertJsonPath("data.{$this->quiz}.attempts.0.status", 'EXPIRED')
            ->assertJsonPath("data.{$this->quiz}.attempts.0.score_percent", 0)
            ->assertJsonPath("data.{$this->quiz}.attempts_left", 1);
    }

    public function test_answers_marked_so_far_are_recovered_when_the_attempt_is_resumed(): void
    {
        [$attempt, $ids] = $this->startAttempt();
        $draft = $this->api("/me/attempts/{$attempt}/draft");

        // Recién abierto no trae nada marcado
        $this->withToken($this->token)->getJson($this->api("/me/attempts/{$attempt}"))->assertOk()->assertJsonPath('data.answers', []);

        // Se guarda lo marcado; una pregunta que no es de este intento se descarta
        $this->withToken($this->token)->putJson($draft, ['answers' => [$ids['TRUE_FALSE'] => true, 'pregunta-ajena' => 'x']])->assertOk();

        // Al volver a abrir el cuestionario, el intento trae lo marcado y sigue sin nota
        $this->withToken($this->token)->postJson($this->me("/activities/{$this->quiz}/attempts"))
            ->assertCreated()
            ->assertJsonPath('data.id', $attempt)
            ->assertJsonPath('data.status', 'IN_PROGRESS')
            ->assertExactJsonStructure(['data' => ['id', 'number', 'status', 'started_at', 'expires_at', 'server_time', 'answers' => [$ids['TRUE_FALSE']], 'questions']])
            ->assertJsonPath("data.answers.{$ids['TRUE_FALSE']}", true);

        // Otra persona no puede escribir en el intento
        $other = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'otro@acme.com']);
        $this->enroll($other);
        $this->withToken($this->login('acme', 'otro@acme.com'))->putJson($draft, ['answers' => []])->assertNotFound();

        // Enviado el intento, ya no admite guardados
        $this->withToken($this->token)->postJson($this->api("/me/attempts/{$attempt}/submit"), ['answers' => [$ids['TRUE_FALSE'] => true]])->assertOk();
        $this->withToken($this->token)->putJson($draft, ['answers' => []])->assertStatus(409)->assertJsonPath('code', 'ATTEMPT_CLOSED');
    }

    public function test_attempts_belong_to_their_student(): void
    {
        [$attempt] = $this->startAttempt();
        $other = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'otro@acme.com']);
        $this->enroll($other);
        $otherToken = $this->login('acme', 'otro@acme.com');

        $this->withToken($otherToken)->getJson($this->api("/me/attempts/{$attempt}"))->assertNotFound();
        $this->withToken($otherToken)->postJson($this->api("/me/attempts/{$attempt}/submit"), ['answers' => []])->assertNotFound();

        // Sin matrícula no hay actividades
        $outsider = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'fuera@acme.com']);
        $outsiderToken = $this->login('acme', $outsider->email);
        $this->withToken($outsiderToken)->getJson($this->me('/activities'))->assertNotFound();
        $this->withToken($outsiderToken)->postJson($this->me("/activities/{$this->quiz}/attempts"))->assertNotFound();

        // Una actividad que no es cuestionario no admite intentos
        $this->withToken($this->token)->postJson($this->me("/activities/{$this->task}/attempts"))->assertNotFound();
    }

    public function test_task_is_submitted_replaced_and_graded_by_a_reviewer(): void
    {
        $endpoint = $this->me("/activities/{$this->task}/submission");

        $this->withToken($this->token)->post($endpoint, [], self::JSON)->assertStatus(422)->assertJsonStructure(['details' => ['submission']]);
        $this->withToken($this->token)->post($endpoint, ['file' => $this->upload('virus.pdf', '<?php echo 1;')], self::JSON)->assertStatus(422);

        $this->withToken($this->token)->post($endpoint, ['body' => 'Mi primera versión.', 'file' => $this->upload('presupuesto.pdf', self::PDF)], self::JSON)
            ->assertOk()
            ->assertJsonPath('data.status', 'SUBMITTED')
            ->assertJsonPath('data.file.name', 'presupuesto.pdf');

        // Mientras no se califique, se puede reemplazar; el archivo anterior se borra
        $this->withToken($this->token)->post($endpoint, ['body' => 'Versión corregida.', 'file' => $this->upload('presupuesto-v2.pdf', self::PDF)], self::JSON)
            ->assertOk()->assertJsonPath('data.file.name', 'presupuesto-v2.pdf');
        $this->assertCount(1, Storage::disk(config('files.disk'))->allFiles());
        $this->withToken($this->token)->get($this->me("/activities/{$this->task}/submission/download"))
            ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=presupuesto-v2.pdf');

        $submission = $this->withToken($this->admin)->getJson($this->api("/courses/{$this->course}/submissions?status=SUBMITTED"))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.student.name', 'Ana Alumna')
            ->assertJsonPath('data.0.activity_title', 'Presupuesto')
            ->assertJsonPath('data.0.body', 'Versión corregida.')
            ->json('data.0.id');

        $this->withToken($this->admin)->get($this->api("/courses/{$this->course}/submissions/{$submission}/download"))->assertOk();
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/submissions/{$submission}/grade"), ['grade_percent' => 120])->assertStatus(422);
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/submissions/{$submission}/grade"), [
            'grade_percent' => 85, 'feedback' => 'Buen trabajo; revisa los totales.',
        ])->assertOk()->assertJsonPath('data.status', 'GRADED');

        $this->withToken($this->token)->getJson($this->me("/activities/{$this->task}"))
            ->assertOk()
            ->assertJsonPath('data.state.submission.grade_percent', 85)
            ->assertJsonPath('data.state.submission.feedback', 'Buen trabajo; revisa los totales.');

        // Calificada, ya no se reemplaza
        $this->withToken($this->token)->post($endpoint, ['body' => 'Otra vez.'], self::JSON)
            ->assertStatus(409)->assertJsonPath('code', 'SUBMISSION_GRADED');

        $this->assertTrue(AuditEvent::query()->where('action', 'submissions.graded')->exists());
    }

    public function test_survey_is_answered_once_and_results_are_aggregated(): void
    {
        $endpoint = $this->me("/activities/{$this->survey}/survey");

        $this->withToken($this->token)->getJson($this->me("/activities/{$this->survey}"))
            ->assertOk()->assertJsonCount(3, 'data.questions')->assertJsonPath('data.state.answered', false);

        // Falta la obligatoria; valores fuera de rango o de la lista
        $this->withToken($this->token)->postJson($endpoint, ['answers' => ['canal' => 'Redes']])->assertStatus(422)->assertJsonStructure(['details' => ['util']]);
        $this->withToken($this->token)->postJson($endpoint, ['answers' => ['util' => 9, 'canal' => 'Televisión']])
            ->assertStatus(422)->assertJsonStructure(['details' => ['util', 'canal']]);

        $this->withToken($this->token)->postJson($endpoint, ['answers' => ['util' => 5, 'canal' => 'Redes', 'mejora' => ' Más ejemplos. ']])
            ->assertCreated()->assertJsonPath('data.answered', true);
        $this->withToken($this->token)->postJson($endpoint, ['answers' => ['util' => 1]])
            ->assertStatus(409)->assertJsonPath('code', 'SURVEY_ALREADY_ANSWERED');

        $other = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'otro@acme.com']);
        $this->enroll($other);
        $this->withToken($this->login('acme', 'otro@acme.com'))->postJson($endpoint, ['answers' => ['util' => 2]])->assertCreated();

        $results = $this->withToken($this->admin)->getJson($this->api("/courses/{$this->course}/activities/{$this->survey}/survey-results"))
            ->assertOk()
            ->assertJsonPath('data.responses', 2)
            ->assertJsonPath('data.questions.0.average', 3.5)
            ->assertJsonPath('data.questions.0.distribution.5', 1)
            ->assertJsonPath('data.questions.1.distribution.Redes', 1)
            ->assertJsonPath('data.questions.1.answered', 1)
            ->assertJsonPath('data.questions.2.texts', ['Más ejemplos.'])
            ->json('data');

        // Los resultados no identifican a quien respondió
        $this->assertStringNotContainsString('ana@acme.com', json_encode($results));
        $this->assertStringNotContainsString($this->student->id, json_encode($results));
    }

    public function test_progress_is_computed_on_the_server_from_completed_items(): void
    {
        // 4 elementos: 1 recurso y 3 actividades
        $this->withToken($this->token)->getJson($this->me())->assertJsonPath('data.progress.percent', 0)->assertJsonPath('data.progress.total', 4);

        $this->withToken($this->token)->postJson($this->me("/resources/{$this->resource}/complete"))->assertOk()->assertJsonPath('data.percent', 25);
        // Abrirlo otra vez no suma
        $this->withToken($this->token)->postJson($this->me("/resources/{$this->resource}/complete"))->assertOk()->assertJsonPath('data.completed', 1);
        $this->withToken($this->token)->postJson($this->me('/resources/'.fake()->uuid().'/complete'))->assertNotFound();

        // Un cuestionario reprobado no cuenta; aprobado, sí
        [$failed] = $this->startAttempt();
        $this->withToken($this->token)->postJson($this->api("/me/attempts/{$failed}/submit"), ['answers' => []])->assertOk();
        $this->withToken($this->token)->getJson($this->me())->assertJsonPath('data.progress.percent', 25);

        [$passed, $ids] = $this->startAttempt();
        $this->withToken($this->token)->postJson($this->api("/me/attempts/{$passed}/submit"), ['answers' => [$ids['SINGLE'] => 'b']])->assertOk();
        $this->withToken($this->token)->getJson($this->me())->assertJsonPath('data.progress.percent', 50);

        // La tarea cuenta al entregarla y la encuesta al responderla
        $this->withToken($this->token)->post($this->me("/activities/{$this->task}/submission"), ['body' => 'Entrega.'], self::JSON)->assertOk();
        $this->withToken($this->token)->postJson($this->me("/activities/{$this->survey}/survey"), ['answers' => ['util' => 4]])->assertCreated();

        $this->withToken($this->token)->getJson($this->me())
            ->assertJsonPath('data.progress.percent', 100)
            ->assertJsonCount(3, 'data.progress.activity_ids');
        $this->withToken($this->token)->getJson($this->api('/me/courses'))->assertJsonPath('data.0.progress_percent', 100);

        // Si se publica contenido nuevo, el avance baja porque hay más por hacer
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/sections/{$this->section}/resources"), ['type' => 'TEXT', 'title' => 'Extra', 'body' => 'x'])->assertCreated();
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();
        $this->withToken($this->token)->getJson($this->me())->assertJsonPath('data.progress.percent', 80)->assertJsonPath('data.progress.total', 5);
    }

    public function test_reviewers_follow_progress_and_grades_of_their_courses_only(): void
    {
        [$attempt, $ids] = $this->startAttempt();
        $this->withToken($this->token)->postJson($this->api("/me/attempts/{$attempt}/submit"), ['answers' => [$ids['SINGLE'] => 'b', $ids['TRUE_FALSE'] => true]])->assertOk();
        $this->withToken($this->token)->post($this->me("/activities/{$this->task}/submission"), ['body' => 'Entrega.'], self::JSON)->assertOk();

        $this->withToken($this->admin)->getJson($this->api("/courses/{$this->course}/tracking"))
            ->assertOk()
            ->assertJsonCount(3, 'data.activities')
            ->assertJsonPath('data.students.0.name', 'Ana Alumna')
            ->assertJsonPath('data.students.0.progress.percent', 50)
            ->assertJsonPath("data.students.0.grades.{$this->quiz}.grade_percent", 100)
            ->assertJsonPath("data.students.0.grades.{$this->task}.status", 'SUBMITTED')
            ->assertJsonPath("data.students.0.grades.{$this->survey}.done", false)
            ->assertJsonPath('meta.pending_submissions', 1)
            ->assertJsonPath('meta.grade_scale', 20);

        // El profesor solo ve y califica en los cursos donde está asignado
        $teacher = $this->createUser($this->tenant, RoleCatalog::TEACHER);
        $teacherToken = $this->login('acme', $teacher->email);
        $submission = $this->withToken($this->admin)->getJson($this->api("/courses/{$this->course}/submissions"))->json('data.0.id');

        $this->withToken($teacherToken)->getJson($this->api("/courses/{$this->course}/tracking"))->assertNotFound();
        $this->withToken($teacherToken)->postJson($this->api("/courses/{$this->course}/submissions/{$submission}/grade"), ['grade_percent' => 100])->assertNotFound();

        $this->withToken($this->admin)->putJson($this->api("/courses/{$this->course}/teachers"), ['user_ids' => [$teacher->id]])->assertOk();

        $this->withToken($teacherToken)->getJson($this->api("/courses/{$this->course}"))->assertJsonPath('meta.can_review', true);
        $this->withToken($teacherToken)->getJson($this->api("/courses/{$this->course}/tracking"))->assertOk();
        $this->withToken($teacherToken)->postJson($this->api("/courses/{$this->course}/submissions/{$submission}/grade"), ['grade_percent' => 70])->assertOk();
        $this->withToken($teacherToken)->getJson($this->api("/courses/{$this->course}/activities/{$this->survey}/survey-results"))->assertOk();

        // El alumno no entra al seguimiento
        $this->withToken($this->token)->getJson($this->api("/courses/{$this->course}/tracking"))->assertForbidden();
        $this->withToken($this->token)->getJson($this->api("/courses/{$this->course}/submissions"))->assertForbidden();
    }

    public function test_task_deadline_marks_late_submissions_or_rejects_them(): void
    {
        $task = ['title' => 'Presupuesto', 'description' => 'Arma un presupuesto.', 'allow_text' => true, 'allow_file' => true];
        $endpoint = $this->me("/activities/{$this->task}/submission");

        $this->withToken($this->admin)->putJson($this->api("/courses/{$this->course}/activities/{$this->task}"), $task + ['due_days' => 3])
            ->assertOk()->assertJsonPath('data.due_days', 3)->assertJsonPath('data.accept_late', true);
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();

        // Dentro del plazo: tres días desde el inicio de la matrícula
        $this->withToken($this->token)->getJson($this->me("/activities/{$this->task}"))
            ->assertOk()
            ->assertJsonPath('data.state.past_due', false)
            ->assertJsonPath('data.state.can_submit', true);
        $this->assertNotNull($this->withToken($this->token)->getJson($this->me("/activities/{$this->task}"))->json('data.state.due_at'));

        $this->travel(4)->days();
        $token = $this->login('acme', 'ana@acme.com');

        // Fuera de plazo se recibe, marcada como tardía
        $this->withToken($token)->post($endpoint, ['body' => 'Llegué tarde.'], self::JSON)
            ->assertOk()->assertJsonPath('data.is_late', true);

        $admin = $this->login('acme', 'admin@acme.com');
        $this->withToken($admin)->getJson($this->api("/courses/{$this->course}/tracking"))
            ->assertJsonPath("data.students.0.grades.{$this->task}.is_late", true);

        // Si la tarea no admite tardías, quien aún no entregó ya no puede hacerlo
        $this->withToken($admin)->putJson($this->api("/courses/{$this->course}/activities/{$this->task}"), $task + ['due_days' => 3, 'accept_late' => false])->assertOk();
        $this->withToken($admin)->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();

        $other = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'beto@acme.com']);
        $this->withToken($admin)->postJson($this->api("/courses/{$this->course}/enrollments"), ['student_ids' => [$other->id]])->assertCreated();

        $this->travel(4)->days();
        $otherToken = $this->login('acme', 'beto@acme.com');

        $this->withToken($otherToken)->getJson($this->me("/activities/{$this->task}"))
            ->assertJsonPath('data.state.past_due', true)
            ->assertJsonPath('data.state.can_submit', false);
        $this->withToken($otherToken)->post($endpoint, ['body' => 'Tarde.'], self::JSON)
            ->assertStatus(409)->assertJsonPath('code', 'SUBMISSION_LATE');
    }

    public function test_reviewer_returns_a_submission_and_the_student_resubmits(): void
    {
        $endpoint = $this->me("/activities/{$this->task}/submission");
        $this->withToken($this->token)->post($endpoint, ['body' => 'Primera versión.'], self::JSON)->assertOk();

        $submission = $this->withToken($this->admin)->getJson($this->api("/courses/{$this->course}/submissions"))->json('data.0.id');
        $review = $this->api("/courses/{$this->course}/submissions/{$submission}");

        $this->withToken($this->admin)->postJson("{$review}/grade", ['grade_percent' => 40])->assertOk();

        // Devolver exige decir qué corregir, y borra la nota
        $this->withToken($this->admin)->postJson("{$review}/return", [])->assertStatus(422);
        $this->withToken($this->admin)->postJson("{$review}/return", ['feedback' => 'Faltan los totales.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'RETURNED')
            ->assertJsonPath('data.grade_percent', null);

        // Devuelta no se califica ni se devuelve otra vez
        $this->withToken($this->admin)->postJson("{$review}/grade", ['grade_percent' => 90])->assertStatus(422);
        $this->withToken($this->admin)->postJson("{$review}/return", ['feedback' => 'Otra vez.'])->assertStatus(422);
        $this->withToken($this->admin)->getJson($this->api("/courses/{$this->course}/submissions?status=RETURNED"))->assertJsonPath('meta.total', 1);

        $this->withToken($this->token)->getJson($this->me("/activities/{$this->task}"))
            ->assertJsonPath('data.state.can_submit', true)
            ->assertJsonPath('data.state.submission.status', 'RETURNED')
            ->assertJsonPath('data.state.submission.feedback', 'Faltan los totales.');

        $this->withToken($this->token)->post($endpoint, ['body' => 'Con totales.'], self::JSON)
            ->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
        $this->withToken($this->admin)->postJson("{$review}/grade", ['grade_percent' => 90])->assertOk()->assertJsonPath('data.status', 'GRADED');

        $this->assertTrue(AuditEvent::query()->where('action', 'submissions.returned')->exists());
    }

    public function test_reviewer_sees_each_attempt_and_grants_extra_attempts(): void
    {
        foreach ([1, 2] as $ignored) {
            [$attempt, $ids] = $this->startAttempt();
            $this->withToken($this->token)->postJson($this->api("/me/attempts/{$attempt}/submit"), ['answers' => [$ids['TRUE_FALSE'] => true]])->assertOk();
        }

        $this->withToken($this->token)->postJson($this->me("/activities/{$this->quiz}/attempts"))->assertStatus(409);

        $enrollment = $this->withToken($this->admin)->getJson($this->api("/courses/{$this->course}/tracking"))->json('data.students.0.enrollment_id');
        $detail = $this->api("/courses/{$this->course}/enrollments/{$enrollment}/activities/{$this->quiz}");

        // Quien revisa ve lo que marcó el alumno y la respuesta correcta
        $this->withToken($this->admin)->getJson("{$detail}/attempts")
            ->assertOk()
            ->assertJsonPath('data.student.name', 'Ana Alumna')
            ->assertJsonPath('data.max_attempts', 2)
            ->assertJsonPath('data.attempts_left', 0)
            ->assertJsonCount(2, 'data.attempts')
            ->assertJsonPath('data.attempts.0.score_percent', 25)
            ->assertJsonPath('data.attempts.0.answers_revealed', true);

        $this->withToken($this->admin)->postJson("{$detail}/extra-attempts", ['attempts' => 1])->assertStatus(422);
        $this->withToken($this->admin)->postJson("{$detail}/extra-attempts", ['attempts' => 1, 'reason' => 'Se le cortó la luz.'])
            ->assertCreated()->assertJsonPath('data.extra_attempts', 1);

        $this->withToken($this->admin)->getJson("{$detail}/attempts")
            ->assertJsonPath('data.attempts_left', 1)
            ->assertJsonPath('data.grants.0.reason', 'Se le cortó la luz.');

        // El alumno recupera un intento, y solo uno
        $this->withToken($this->token)->getJson($this->me('/activities'))
            ->assertJsonPath("data.{$this->quiz}.attempts_left", 1)
            ->assertJsonPath("data.{$this->quiz}.extra_attempts", 1);
        [$third, $ids] = $this->startAttempt();
        $this->withToken($this->token)->postJson($this->api("/me/attempts/{$third}/submit"), ['answers' => [$ids['SINGLE'] => 'b', $ids['TRUE_FALSE'] => true]])
            ->assertOk()->assertJsonPath('data.passed', true);
        $this->withToken($this->token)->postJson($this->me("/activities/{$this->quiz}/attempts"))->assertStatus(409);

        // Ni el alumno ni un profesor de otro curso llegan a esto
        $teacher = $this->createUser($this->tenant, RoleCatalog::TEACHER);
        $this->withToken($this->login('acme', $teacher->email))->postJson("{$detail}/extra-attempts", ['attempts' => 1, 'reason' => 'x'])->assertNotFound();
        $this->withToken($this->token)->getJson("{$detail}/attempts")->assertForbidden();

        $this->assertTrue(AuditEvent::query()->where('action', 'attempts.granted')->exists());
    }

    public function test_final_grade_is_the_weighted_average_of_quizzes_and_tasks(): void
    {
        $task = ['title' => 'Presupuesto', 'description' => 'Arma un presupuesto.', 'allow_text' => true, 'allow_file' => true];
        $taskUrl = $this->api("/courses/{$this->course}/activities/{$this->task}");

        // La tarea pesa el triple que el cuestionario
        $this->withToken($this->admin)->putJson($taskUrl, $task + ['weight' => 101])->assertStatus(422);
        $this->withToken($this->admin)->putJson($taskUrl, $task + ['weight' => 3])->assertOk()->assertJsonPath('data.weight', 3);
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();

        [$attempt, $ids] = $this->startAttempt();
        $this->withToken($this->token)->postJson($this->api("/me/attempts/{$attempt}/submit"), ['answers' => [$ids['SINGLE'] => 'b', $ids['TRUE_FALSE'] => true]])->assertOk();
        $this->withToken($this->token)->post($this->me("/activities/{$this->task}/submission"), ['body' => 'Entrega.'], self::JSON)->assertOk();

        // Lo que aún no tiene nota cuenta como cero: (1 × 100 + 3 × 0) / 4
        $this->withToken($this->token)->getJson($this->me('/grades'))
            ->assertOk()
            ->assertJsonPath('data.final_percent', 25)
            // La encuesta no lleva nota
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.1.weight', 3)
            ->assertJsonPath('data.items.1.status', 'SUBMITTED');

        $submission = $this->withToken($this->admin)->getJson($this->api("/courses/{$this->course}/submissions"))->json('data.0.id');
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/submissions/{$submission}/grade"), ['grade_percent' => 60])->assertOk();

        // (1 × 100 + 3 × 60) / 4
        $this->withToken($this->token)->getJson($this->me('/grades'))->assertJsonPath('data.final_percent', 70);
        $this->withToken($this->admin)->getJson($this->api("/courses/{$this->course}/tracking"))
            ->assertOk()
            ->assertJsonPath('data.students.0.final_percent', 70)
            ->assertJsonPath('data.activities.1.weight', 3)
            ->assertJsonPath('data.activities.2.weight', null);

        $export = $this->withToken($this->admin)->get($this->api("/courses/{$this->course}/gradebook/export"))
            ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=notas.xlsx');

        // El Excel trae las notas en la escala de la academia (0 a 20)
        $reader = new Reader;
        $reader->open($export->baseResponse->getFile()->getPathname());
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        $this->assertSame(['Alumno', 'Correo', 'Documento', 'Evaluación (peso 1)', 'Presupuesto (peso 3)', 'Nota final (0 a 20)'], $rows[0]);
        $this->assertSame('Ana Alumna', $rows[1][0]);
        $this->assertEquals([20, 12, 14], array_slice($rows[1], 3));
        $this->withToken($this->token)->get($this->api("/courses/{$this->course}/gradebook/export"), self::JSON)->assertForbidden();

        // Con peso cero la tarea deja de contar
        $this->withToken($this->admin)->putJson($taskUrl, $task + ['weight' => 0])->assertOk();
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();
        $this->withToken($this->token)->getJson($this->me('/grades'))->assertJsonPath('data.final_percent', 100);
    }

    public function test_results_are_kept_when_the_course_changes_and_isolated_between_tenants(): void
    {
        [$attempt, $ids] = $this->startAttempt();

        // Se retira una pregunta y se publica mientras el alumno tiene el intento abierto
        $this->withToken($this->admin)->putJson($this->api("/courses/{$this->course}/activities/{$this->quiz}/questions"), ['items' => [['question_id' => $ids['TRUE_FALSE']]]])->assertOk();
        $this->withToken($this->admin)->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();

        // El intento se califica con las preguntas con las que se abrió
        $this->withToken($this->token)->postJson($this->api("/me/attempts/{$attempt}/submit"), ['answers' => [$ids['SINGLE'] => 'b', $ids['TRUE_FALSE'] => true]])
            ->assertOk()->assertJsonPath('data.score_percent', 100)->assertJsonCount(2, 'data.questions');

        $context = app(TenantContext::class);
        $beta = $this->provisionTenant('beta', 'admin@beta.com');
        $betaAdmin = $this->login('beta', 'admin@beta.com');

        $this->assertSame(0, $context->runForTenant($beta->id, fn () => QuizAttempt::query()->count()));
        $this->assertSame(1, $context->runForTenant($this->tenant->id, fn () => QuizAttempt::query()->count()));
        $this->withToken($betaAdmin)->getJson($this->tenantUrl('beta', "/courses/{$this->course}/tracking"))->assertNotFound();
        $this->withToken($betaAdmin)->getJson($this->tenantUrl('beta', "/me/attempts/{$attempt}"))->assertNotFound();
    }
}
