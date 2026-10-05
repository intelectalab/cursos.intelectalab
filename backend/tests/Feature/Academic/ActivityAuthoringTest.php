<?php

namespace Tests\Feature\Academic;

use App\Modules\Academic\Domain\QuestionConfig;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityAuthoringTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function createBank(string $name = 'Excel básico'): string
    {
        return $this->withToken($this->admin)->postJson($this->api('/question-banks'), ['name' => $name])
            ->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createQuestion(string $bank, string $type = 'TRUE_FALSE', array $config = ['answer' => true]): string
    {
        return $this->withToken($this->admin)->postJson($this->api("/question-banks/{$bank}/questions"), [
            'type' => $type, 'statement' => 'Excel es una hoja de cálculo.', 'config' => $config,
        ])->assertCreated()->json('data.id');
    }

    /**
     * @return array{0: string, 1: string} curso y sección
     */
    private function createCourse(): array
    {
        $course = $this->withToken($this->admin)->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es'])
            ->assertCreated()->json('data.id');
        $section = $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])
            ->assertCreated()->json('data.id');

        return [$course, $section];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createQuiz(string $course, string $section, array $overrides = []): string
    {
        return $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/sections/{$section}/activities"), $overrides + [
            'type' => 'QUIZ', 'title' => 'Evaluación del módulo', 'passing_percent' => 55, 'max_attempts' => 3,
        ])->assertCreated()->json('data.id');
    }

    public function test_question_banks_are_managed_and_names_are_unique(): void
    {
        $bank = $this->createBank();

        $this->withToken($this->admin)->postJson($this->api('/question-banks'), ['name' => 'Excel básico'])
            ->assertStatus(422)->assertJsonStructure(['details' => ['name']]);
        $this->withToken($this->admin)->putJson($this->api("/question-banks/{$bank}"), ['name' => 'Excel inicial', 'description' => 'Fundamentos'])
            ->assertOk()->assertJsonPath('data.name', 'Excel inicial');

        $this->createQuestion($bank);
        $this->withToken($this->admin)->getJson($this->api('/question-banks'))
            ->assertOk()
            ->assertJsonPath('data.0.questions_count', 1)
            ->assertJsonCount(7, 'meta.question_types');

        $this->withToken($this->admin)->deleteJson($this->api("/question-banks/{$bank}"))->assertOk();
        $this->withToken($this->admin)->getJson($this->api('/question-banks'))->assertJsonCount(0, 'data');
    }

    public function test_every_question_type_is_stored_with_its_answer_key(): void
    {
        $bank = $this->createBank();
        $endpoint = $this->api("/question-banks/{$bank}/questions");

        $valid = [
            'SINGLE' => ['options' => [['text' => 'SUMA', 'correct' => false], ['text' => 'BUSCARV', 'correct' => true]]],
            'MULTIPLE' => ['options' => [['text' => 'A', 'correct' => true], ['text' => 'B', 'correct' => true], ['text' => 'C', 'correct' => false]]],
            'TRUE_FALSE' => ['answer' => false],
            'SHORT_TEXT' => ['answers' => ['BUSCARV', ' VLOOKUP ']],
            'NUMERIC' => ['answer' => 12.5, 'tolerance' => 0.1],
            'MATCHING' => ['pairs' => [['left' => 'Perú', 'right' => 'Lima'], ['left' => 'Chile', 'right' => 'Santiago']]],
            'ORDERING' => ['items' => [['text' => 'Abrir'], ['text' => 'Guardar']]],
        ];

        foreach ($valid as $type => $config) {
            $this->withToken($this->admin)->postJson($endpoint, ['type' => $type, 'statement' => "Pregunta {$type}", 'config' => $config])
                ->assertCreated()
                ->assertJsonPath('data.type', $type);
        }

        $stored = collect($this->withToken($this->admin)->getJson($endpoint)->assertJsonPath('meta.total', 7)->json('data'))->keyBy('type');

        // Cada opción recibe un identificador estable y el texto se limpia
        $this->assertNotEmpty($stored['SINGLE']['config']['options'][0]['id']);
        $this->assertSame(['BUSCARV', 'VLOOKUP'], $stored['SHORT_TEXT']['config']['answers']);
        $this->assertSame(0.1, $stored['NUMERIC']['config']['tolerance']);

        $this->withToken($this->admin)->getJson("{$endpoint}?type=NUMERIC")->assertJsonPath('meta.total', 1);
        $this->withToken($this->admin)->getJson("{$endpoint}?search=matching")->assertJsonPath('meta.total', 1);
    }

    public function test_question_configuration_is_validated_per_type(): void
    {
        $bank = $this->createBank();
        $endpoint = $this->api("/question-banks/{$bank}/questions");

        $invalid = [
            ['ESSAY', ['answer' => 'x']],
            ['SINGLE', ['options' => [['text' => 'A', 'correct' => true]]]],
            ['SINGLE', ['options' => [['text' => 'A', 'correct' => true], ['text' => 'B', 'correct' => true]]]],
            ['SINGLE', ['options' => [['text' => 'A', 'correct' => false], ['text' => 'B', 'correct' => false]]]],
            ['MULTIPLE', ['options' => [['text' => 'A', 'correct' => false], ['text' => 'B', 'correct' => false]]]],
            ['TRUE_FALSE', ['answer' => 'quizás']],
            ['SHORT_TEXT', ['answers' => []]],
            ['NUMERIC', ['answer' => 'doce']],
            ['NUMERIC', ['answer' => 12, 'tolerance' => -1]],
            ['MATCHING', ['pairs' => [['left' => 'Perú', 'right' => 'Lima']]]],
            ['ORDERING', ['items' => [['text' => 'Solo uno']]]],
        ];

        foreach ($invalid as [$type, $config]) {
            $this->withToken($this->admin)->postJson($endpoint, ['type' => $type, 'statement' => 'Pregunta', 'config' => $config])
                ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }

        $this->withToken($this->admin)->getJson($endpoint)->assertJsonPath('meta.total', 0);
    }

    public function test_students_never_receive_the_answer_key(): void
    {
        $single = QuestionConfig::forStudent('SINGLE', ['options' => [['id' => 'a', 'text' => 'SUMA', 'correct' => true], ['id' => 'b', 'text' => 'RESTA', 'correct' => false]]]);
        $matching = QuestionConfig::forStudent('MATCHING', ['pairs' => [['id' => 'p1', 'left' => 'Perú', 'right' => 'Lima'], ['id' => 'p2', 'left' => 'Chile', 'right' => 'Santiago']]]);

        $this->assertSame([['id' => 'a', 'text' => 'SUMA'], ['id' => 'b', 'text' => 'RESTA']], $single['options']);
        $this->assertSame(['Perú', 'Chile'], array_column($matching['left'], 'text'));
        $this->assertEqualsCanonicalizing(['Lima', 'Santiago'], array_column($matching['right'], 'text'));

        foreach (['TRUE_FALSE' => ['answer' => true], 'SHORT_TEXT' => ['answers' => ['x']], 'NUMERIC' => ['answer' => 1, 'tolerance' => 0]] as $type => $config) {
            $this->assertSame([], QuestionConfig::forStudent($type, $config));
        }
    }

    public function test_quiz_is_built_from_bank_questions_in_order(): void
    {
        [$course, $section] = $this->createCourse();
        $bank = $this->createBank();
        $first = $this->createQuestion($bank);
        $second = $this->createQuestion($bank, 'NUMERIC', ['answer' => 4]);
        $quiz = $this->createQuiz($course, $section, ['time_limit_minutes' => 20, 'shuffle' => true]);

        $this->withToken($this->admin)->putJson($this->api("/courses/{$course}/activities/{$quiz}/questions"), [
            'items' => [['question_id' => $second, 'points' => 2], ['question_id' => $first]],
        ])
            ->assertOk()
            ->assertJsonPath('data.questions.0.question_id', $second)
            ->assertJsonPath('data.questions.0.points', 2)
            ->assertJsonPath('data.questions.1.points', 1);

        $this->withToken($this->admin)->putJson($this->api("/courses/{$course}/activities/{$quiz}"), [
            'title' => 'Examen final', 'passing_percent' => 70, 'max_attempts' => null,
        ])->assertOk()->assertJsonPath('data.max_attempts', null)->assertJsonPath('data.time_limit_minutes', null);

        $this->withToken($this->admin)->getJson($this->api("/courses/{$course}/activities"))
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Examen final')
            ->assertJsonPath('data.0.section_id', $section)
            ->assertJsonCount(2, 'data.0.questions')
            ->assertJsonPath('meta.grade_scale', 20);

        // Una pregunta en uso no se puede borrar, ni su banco
        $this->withToken($this->admin)->deleteJson($this->api("/questions/{$first}"))
            ->assertStatus(409)->assertJsonPath('code', 'QUESTION_IN_USE');
        $this->withToken($this->admin)->deleteJson($this->api("/question-banks/{$bank}"))->assertStatus(409);

        $this->withToken($this->admin)->putJson($this->api("/courses/{$course}/activities/{$quiz}/questions"), ['items' => []])->assertOk();
        $this->withToken($this->admin)->deleteJson($this->api("/questions/{$first}"))->assertOk();
    }

    public function test_tasks_and_surveys_are_validated_and_reordered(): void
    {
        [$course, $section] = $this->createCourse();
        $endpoint = $this->api("/courses/{$course}/sections/{$section}/activities");

        $this->withToken($this->admin)->postJson($endpoint, ['type' => 'TASK', 'title' => 'Sin consigna', 'allow_text' => true, 'allow_file' => false])->assertStatus(422);
        $this->withToken($this->admin)->postJson($endpoint, ['type' => 'TASK', 'title' => 'Nada que entregar', 'description' => 'x', 'allow_text' => false, 'allow_file' => false])
            ->assertStatus(422)->assertJsonStructure(['details' => ['allow_file']]);
        $this->withToken($this->admin)->postJson($endpoint, ['type' => 'SURVEY', 'title' => 'Encuesta', 'questions' => [['type' => 'CHOICE', 'text' => 'Sin opciones']]])->assertStatus(422);
        $this->withToken($this->admin)->postJson($endpoint, ['type' => 'FORO', 'title' => 'Otro'])->assertStatus(422);

        $task = $this->withToken($this->admin)->postJson($endpoint, [
            'type' => 'TASK', 'title' => 'Presupuesto', 'description' => 'Arma un presupuesto mensual.', 'allow_text' => false, 'allow_file' => true,
        ])->assertCreated()->assertJsonPath('data.allow_text', false)->json('data.id');

        $survey = $this->withToken($this->admin)->postJson($endpoint, [
            'type' => 'SURVEY', 'title' => 'Satisfacción', 'questions' => [
                ['type' => 'SCALE', 'text' => '¿Qué tan útil fue el módulo?'],
                ['type' => 'CHOICE', 'text' => '¿Cómo llegaste al curso?', 'options' => ['Redes', 'Recomendación'], 'required' => false],
                ['type' => 'TEXT', 'text' => '¿Qué mejorarías?', 'options' => ['no debería guardarse']],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.questions.0.required', true)
            ->assertJsonPath('data.questions.1.options', ['Redes', 'Recomendación'])
            ->assertJsonMissingPath('data.questions.2.options')
            ->json('data.id');

        $this->withToken($this->admin)->putJson("{$endpoint}/order", ['ids' => [$survey, $task]])->assertOk();
        $this->withToken($this->admin)->putJson("{$endpoint}/order", ['ids' => [$survey]])->assertStatus(422);
        $this->withToken($this->admin)->getJson($this->api("/courses/{$course}/activities"))
            ->assertJsonPath('data.0.id', $survey)->assertJsonPath('data.1.id', $task);

        $this->withToken($this->admin)->deleteJson($this->api("/courses/{$course}/activities/{$task}"))->assertOk();
        $this->withToken($this->admin)->getJson($this->api("/courses/{$course}/activities"))->assertJsonCount(1, 'data');
    }

    public function test_published_version_carries_a_frozen_copy_of_the_activities(): void
    {
        [$course, $section] = $this->createCourse();
        $bank = $this->createBank();
        $question = $this->createQuestion($bank);
        $quiz = $this->createQuiz($course, $section);

        // Un cuestionario vacío impide publicar
        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/publish"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'COURSE_NOT_PUBLISHABLE')
            ->assertJsonPath('details.problems.0', 'El cuestionario "Evaluación del módulo" no tiene preguntas.');

        $this->withToken($this->admin)->putJson($this->api("/courses/{$course}/activities/{$quiz}/questions"), ['items' => [['question_id' => $question, 'points' => 3]]])->assertOk();

        // Solo con actividades (sin recursos) el curso ya se puede publicar
        $version = $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/publish"))
            ->assertOk()->assertJsonPath('data.pending_changes', false)->json('data.versions.0.id');

        // Editar la pregunta en el banco deja el curso con cambios pendientes…
        $this->withToken($this->admin)->putJson($this->api("/questions/{$question}"), [
            'statement' => 'Enunciado corregido.', 'config' => ['answer' => false],
        ])->assertOk();
        $this->withToken($this->admin)->getJson($this->api("/courses/{$course}"))->assertJsonPath('data.pending_changes', true);

        // …pero la versión publicada conserva la pregunta tal como se publicó
        $this->withToken($this->admin)->getJson($this->api("/courses/{$course}/versions/{$version}"))
            ->assertOk()
            ->assertJsonPath('data.snapshot.sections.0.activities.0.type', 'QUIZ')
            ->assertJsonPath('data.snapshot.sections.0.activities.0.quiz.passing_percent', 55)
            ->assertJsonPath('data.snapshot.sections.0.activities.0.quiz.questions.0.statement', 'Excel es una hoja de cálculo.')
            ->assertJsonPath('data.snapshot.sections.0.activities.0.quiz.questions.0.config.answer', true)
            ->assertJsonPath('data.snapshot.sections.0.activities.0.quiz.questions.0.points', 3);
    }

    public function test_teacher_manages_activities_only_in_assigned_courses(): void
    {
        [$mine, $mySection] = $this->createCourse();
        [$other, $otherSection] = $this->createCourse();
        $teacher = $this->createUser($this->tenant, RoleCatalog::TEACHER);
        $student = $this->login('acme', $this->createUser($this->tenant, RoleCatalog::STUDENT)->email);
        $token = $this->login('acme', $teacher->email);
        $task = ['type' => 'TASK', 'title' => 'Tarea', 'description' => 'Consigna', 'allow_text' => true, 'allow_file' => false];

        $this->withToken($this->admin)->putJson($this->api("/courses/{$mine}/teachers"), ['user_ids' => [$teacher->id]])->assertOk();

        $this->withToken($token)->postJson($this->api("/courses/{$mine}/sections/{$mySection}/activities"), $task)->assertCreated();
        $this->withToken($token)->getJson($this->api("/courses/{$mine}/activities"))->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson($this->api("/courses/{$mine}"))->assertJsonPath('meta.can_manage_activities', true);

        // El profesor arma sus propios bancos de preguntas
        $this->withToken($token)->postJson($this->api('/question-banks'), ['name' => 'Banco del profesor'])->assertCreated();

        $this->withToken($token)->postJson($this->api("/courses/{$other}/sections/{$otherSection}/activities"), $task)->assertNotFound();
        $this->withToken($token)->getJson($this->api("/courses/{$other}/activities"))->assertNotFound();

        foreach ([['getJson', "/courses/{$mine}/activities"], ['getJson', '/question-banks'], ['postJson', '/question-banks']] as [$method, $path]) {
            $this->withToken($student)->{$method}($this->api($path))->assertForbidden();
        }
        $this->withToken($student)->postJson($this->api("/courses/{$mine}/sections/{$mySection}/activities"), $task)->assertForbidden();
    }

    public function test_academic_content_is_isolated_between_tenants(): void
    {
        [$course, $section] = $this->createCourse();
        $bank = $this->createBank();
        $question = $this->createQuestion($bank);
        $quiz = $this->createQuiz($course, $section);

        $this->provisionTenant('beta', 'admin@beta.com');
        $beta = $this->login('beta', 'admin@beta.com');
        $betaApi = fn (string $path) => $this->tenantUrl('beta', $path);

        $this->withToken($beta)->getJson($betaApi('/question-banks'))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($beta)->getJson($betaApi("/question-banks/{$bank}/questions"))->assertNotFound();
        $this->withToken($beta)->putJson($betaApi("/questions/{$question}"), ['statement' => 'Robada', 'config' => ['answer' => false]])->assertNotFound();
        $this->withToken($beta)->getJson($betaApi("/courses/{$course}/activities"))->assertNotFound();

        // El mismo nombre de banco puede existir en otra organización
        $this->withToken($beta)->postJson($betaApi('/question-banks'), ['name' => 'Excel básico'])->assertCreated();

        // Un cuestionario de beta no puede usar preguntas de acme
        $betaCourse = $this->withToken($beta)->postJson($betaApi('/courses'), ['title' => 'Curso beta', 'language' => 'es'])->json('data.id');
        $betaSection = $this->withToken($beta)->postJson($betaApi("/courses/{$betaCourse}/sections"), ['title' => 'Módulo'])->json('data.id');
        $betaQuiz = $this->withToken($beta)->postJson($betaApi("/courses/{$betaCourse}/sections/{$betaSection}/activities"), [
            'type' => 'QUIZ', 'title' => 'Quiz beta', 'passing_percent' => 55,
        ])->assertCreated()->json('data.id');

        $this->withToken($beta)->putJson($betaApi("/courses/{$betaCourse}/activities/{$betaQuiz}/questions"), ['items' => [['question_id' => $question]]])
            ->assertStatus(422);
        $this->withToken($beta)->putJson($betaApi("/courses/{$betaCourse}/activities/{$quiz}"), ['title' => 'x', 'passing_percent' => 1])->assertNotFound();
    }
}
