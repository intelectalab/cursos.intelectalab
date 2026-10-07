<?php

namespace Tests\Feature\Imports;

use App\Modules\Academic\Domain\Models\Question;
use App\Modules\Crm\Domain\Models\Lead;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Group;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Revisión de jefatura, entrega 2: grupos de personas e importaciones desde
 * una hoja de cálculo (personas con su matrícula, preguntas y prospectos).
 */
class ImportTest extends TestCase
{
    use RefreshDatabase;

    private const PEOPLE = ['Nombres', 'Apellidos', 'Correo', 'Tipo de documento', 'Número de documento', 'Teléfono', 'Curso', 'Grupo', 'Fin del acceso'];

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

    /**
     * Excel real con una fila de títulos y las filas dadas.
     *
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private function sheet(array $headers, array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'archivo.xlsx', null, null, true);
    }

    private function publishedCourse(string $title = 'Excel para finanzas'): string
    {
        $admin = fn () => $this->withToken($this->admin);

        $course = $admin()->postJson($this->api('/courses'), ['title' => $title, 'language' => 'es'])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return $course;
    }

    public function test_the_template_downloads_as_a_spreadsheet_with_the_expected_columns(): void
    {
        $response = $this->withToken($this->admin)->get($this->api('/imports/people/template'))->assertOk();

        $reader = new Reader;
        $reader->open($response->baseResponse->getFile()->getPathname());
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();

        $this->assertSame(self::PEOPLE, $sheets['Personas y matrículas'][0]);
        $this->assertSame('ana.torres@correo.com', $sheets['Personas y matrículas'][1][2]);
        $this->assertSame(['Columna', '¿Obligatoria?', 'Qué poner'], $sheets['Instrucciones'][0]);
    }

    public function test_people_are_previewed_without_saving_and_then_imported_with_enrollment_and_group(): void
    {
        $this->publishedCourse();
        $existing = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'beto@acme.com']);
        $file = fn () => $this->sheet(self::PEOPLE, [
            // Numérico en la hoja, como lo deja Excel al escribir un DNI
            ['Ana', 'Torres', 'ANA@acme.com', 'DNI', 45678912, '999 888 777', 'excel para finanzas', 'Promoción 2026', ''],
            ['Beto', 'Ríos', 'beto@acme.com', '', '11112222', '', 'Excel para finanzas', 'Promoción 2026', ''],
            ['Caro', 'Luna', 'correo-malo', 'DNI', '123', '', '', '', ''],
            ['Dani', 'Paz', 'dani@acme.com', 'DNI', '33334444', '', 'Curso que no existe', '', ''],
            ['Ana', 'Repetida', 'ana@acme.com', 'DNI', '55556666', '', '', '', ''],
        ]);

        // La revisión dice qué pasaría y no guarda nada
        $this->withToken($this->admin)->post($this->api('/imports/people/preview'), ['file' => $file()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.ok', 2)
            ->assertJsonPath('meta.errors', 3)
            ->assertJsonPath('data.0.status', 'OK')
            ->assertJsonPath('data.0.line', 2)
            ->assertJsonPath('data.2.status', 'ERROR')
            ->assertJsonPath('data.3.messages.0', 'No hay un curso publicado llamado «Curso que no existe».')
            ->assertJsonPath('data.4.messages.0', 'El correo se repite en la fila 2.');

        $this->assertNull(User::query()->withoutGlobalScopes()->where('email', 'ana@acme.com')->first());
        $this->assertSame(0, Group::query()->withoutGlobalScopes()->count());

        $this->withToken($this->admin)->post($this->api('/imports/people'), ['file' => $file()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('meta.ok', 2)
            ->assertJsonPath('meta.errors', 3);

        $ana = User::query()->withoutGlobalScopes()->where('email', 'ana@acme.com')->firstOrFail();
        $this->assertTrue($ana->must_change_password);
        $this->assertSame('45678912', $ana->doc_number);
        $this->assertSame(2, Enrollment::query()->withoutGlobalScopes()->count());
        $this->assertSame(2, Group::query()->withoutGlobalScopes()->firstOrFail()->members()->withoutGlobalScopes()->count());
        // A quien ya tenía cuenta no se le tocan los datos ni la contraseña
        $this->assertFalse($existing->fresh()->must_change_password);
        $this->assertNull(User::query()->withoutGlobalScopes()->where('email', 'dani@acme.com')->first());

        // Repetir el mismo archivo no duplica nada
        $this->withToken($this->admin)->post($this->api('/imports/people'), ['file' => $file()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('meta.ok', 0)
            ->assertJsonPath('meta.skipped', 2);
    }

    public function test_an_imported_person_must_change_the_temporary_password_before_anything_else(): void
    {
        $course = $this->publishedCourse();
        $this->withToken($this->admin)->post($this->api('/imports/people'), ['file' => $this->sheet(self::PEOPLE, [
            ['Ana', 'Torres', 'ana@acme.com', 'DNI', '45678912', '', 'Excel para finanzas', '', ''],
        ])], ['Accept' => 'application/json'])->assertCreated();

        $token = $this->postJson($this->api('/auth/login'), ['email' => 'ana@acme.com', 'password' => '45678912'])
            ->assertOk()
            ->assertJsonPath('data.user.must_change_password', true)
            ->json('data.token');

        $this->withToken($token)->getJson($this->api('/me/courses'))->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->withToken($token)->getJson($this->api('/auth/me'))->assertOk();

        $this->withToken($token)->putJson($this->api('/auth/password'), [
            'current_password' => '45678912', 'password' => 'una-clave-nueva-1', 'password_confirmation' => 'una-clave-nueva-1',
        ])->assertOk();

        $this->withToken($token)->getJson($this->api('/auth/me'))->assertJsonPath('data.user.must_change_password', false);
        $this->withToken($token)->getJson($this->api("/me/courses/{$course}"))->assertOk();
    }

    public function test_a_file_that_is_not_the_template_is_rejected_and_imports_require_permission(): void
    {
        $this->withToken($this->admin)->post($this->api('/imports/people/preview'), ['file' => $this->sheet(['Nombre', 'Email'], [['Ana', 'ana@acme.com']])], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        $text = tempnam(sys_get_temp_dir(), 'imp').'.pdf';
        file_put_contents($text, '%PDF-1.4');
        $this->withToken($this->admin)->post($this->api('/imports/people/preview'), ['file' => new UploadedFile($text, 'lista.pdf', null, null, true)], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $student = $this->login('acme', $this->createUser($this->tenant, RoleCatalog::STUDENT)->email);
        $this->withToken($student)->get($this->api('/imports/people/template'), ['Accept' => 'application/json'])->assertForbidden();
        $this->withToken($student)->post($this->api('/crm/leads/import'), ['file' => $this->sheet(['Nombres'], [['Ana']])], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_questions_are_imported_into_a_bank_by_type(): void
    {
        $bank = $this->withToken($this->admin)->postJson($this->api('/question-banks'), ['name' => 'Excel'])->assertCreated()->json('data.id');
        $headers = ['Tipo', 'Enunciado', 'Opción 1', 'Opción 2', 'Opción 3', 'Opción 4', 'Opción 5', 'Respuesta correcta', 'Margen', 'Explicación'];

        $this->withToken($this->admin)->post($this->api("/question-banks/{$bank}/import"), ['file' => $this->sheet($headers, [
            ['Opción única', '¿Qué función busca un valor?', 'SUMA', 'BUSCARV', 'CONTAR', '', '', 2, '', 'BUSCARV busca en la primera columna.'],
            ['opcion multiple', '¿Cuáles son funciones de texto?', 'IZQUIERDA', 'SUMA', 'CONCAT', '', '', '1, 3', '', ''],
            ['Verdadero o falso', 'Excel es una hoja de cálculo.', '', '', '', '', '', 'V', '', ''],
            ['Respuesta corta', '¿Qué tecla confirma una fórmula?', '', '', '', '', '', 'Enter|Intro', '', ''],
            ['Numérica', '¿Cuánto es 10 / 4?', '', '', '', '', '', '2,5', '0,1', ''],
            ['Opción única', 'Sin respuesta válida', 'A', 'B', '', '', '', 4, '', ''],
            ['Emparejar', 'No se importa', '', '', '', '', '', 'x', '', ''],
        ])], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('meta.ok', 5)
            ->assertJsonPath('meta.errors', 2);

        $questions = Question::query()->withoutGlobalScopes()->orderBy('created_at')->orderBy('id')->get()->keyBy('type');
        $this->assertCount(5, $questions);
        $this->assertSame([false, true, false], array_column($questions['SINGLE']->config['options'], 'correct'));
        $this->assertSame([true, false, true], array_column($questions['MULTIPLE']->config['options'], 'correct'));
        $this->assertTrue($questions['TRUE_FALSE']->config['answer']);
        $this->assertSame(['Enter', 'Intro'], $questions['SHORT_TEXT']->config['answers']);
        $this->assertSame([2.5, 0.1], [(float) $questions['NUMERIC']->config['answer'], (float) $questions['NUMERIC']->config['tolerance']]);
    }

    public function test_leads_are_imported_once_and_duplicates_are_skipped(): void
    {
        $headers = ['Nombres', 'Apellidos', 'Empresa', 'Cargo', 'Correo', 'Teléfono', 'Origen', 'Interés'];
        $file = fn () => $this->sheet($headers, [
            ['Carlos', 'Mendoza', 'Minera Andes', 'Jefe', 'CMendoza@andes.pe', '', 'Evento', 'Capacitar a 20 personas'],
            ['Lucía', '', '', '', '', '987654321', '', ''],
            ['Sin', 'Contacto', '', '', '', '', 'Web', ''],
            ['Otro', 'Carlos', '', '', 'cmendoza@andes.pe', '', 'Llamada', ''],
        ]);

        $this->withToken($this->admin)->post($this->api('/crm/leads/import'), ['file' => $file()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('meta.ok', 2)
            ->assertJsonPath('meta.errors', 1)
            ->assertJsonPath('meta.skipped', 1);

        $lead = Lead::query()->withoutGlobalScopes()->where('email', 'cmendoza@andes.pe')->firstOrFail();
        $this->assertSame(['EVENT', 'NEW'], [$lead->source, $lead->status]);
        $this->assertSame('OTHER', Lead::query()->withoutGlobalScopes()->where('first_name', 'Lucía')->value('source'));

        // Al repetir el archivo, el que tiene correo ya existe y no se duplica
        $this->withToken($this->admin)->post($this->api('/crm/leads/import'), ['file' => $file()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.0.status', 'SKIP');
    }

    public function test_a_csv_saved_by_excel_with_semicolons_and_latin_encoding_is_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        // Como lo guarda Excel en un equipo en español: punto y coma, Windows-1252 y otro orden de columnas
        file_put_contents($path, mb_convert_encoding(
            'Correo;Nombres;Apellidos;Empresa;Cargo;Teléfono;Origen;Interés
jose@andes.pe;José;Núñez;Minera Andes;;;Recomendación;Capacitación
',
            'Windows-1252',
            'UTF-8'
        ));

        $this->withToken($this->admin)->post($this->api('/crm/leads/import'), ['file' => new UploadedFile($path, 'prospectos.csv', null, null, true)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('meta.ok', 1)
            ->assertJsonPath('data.0.label', 'José Núñez');

        $lead = Lead::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(['José', 'Núñez', 'REFERRAL'], [$lead->first_name, $lead->last_name, $lead->source]);
    }

    public function test_groups_are_managed_and_a_whole_group_is_enrolled_at_once(): void
    {
        $course = $this->publishedCourse();
        $people = collect(range(1, 3))->map(fn () => $this->createUser($this->tenant, RoleCatalog::STUDENT))->all();
        $inactive = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['status' => 'INACTIVE']);

        $group = $this->withToken($this->admin)->postJson($this->api('/groups'), ['name' => 'Área de ventas', 'description' => 'Equipo comercial'])
            ->assertCreated()->json('data.id');
        $this->withToken($this->admin)->postJson($this->api('/groups'), ['name' => 'Área de ventas'])->assertStatus(422);

        $this->withToken($this->admin)->postJson($this->api("/groups/{$group}/members"), ['user_ids' => [...array_map(fn (User $user) => $user->id, $people), $inactive->id]])
            ->assertOk()
            ->assertJsonPath('meta.added', 4)
            ->assertJsonPath('data.members_count', 4);
        $this->withToken($this->admin)->deleteJson($this->api("/groups/{$group}/members/{$people[2]->id}"))->assertOk()->assertJsonPath('data.members_count', 3);
        $this->withToken($this->admin)->getJson($this->api('/groups'))->assertOk()->assertJsonPath('data.0.members_count', 3);

        // Uno de los miembros ya es docente del curso: al matricular el grupo, no entra como alumno
        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/participants"), ['role' => 'TEACHER', 'user_ids' => [$people[0]->id]])->assertCreated();
        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/participants"), ['role' => 'STUDENT', 'group_ids' => [$group]])
            ->assertCreated()
            ->assertJsonPath('data.added', 1);
        $this->assertSame([$people[1]->id], Enrollment::query()->withoutGlobalScopes()->pluck('student_id')->all());

        // Matricular otra vez el mismo grupo no hace nada, y sin personas ni grupos se rechaza
        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/participants"), ['role' => 'STUDENT', 'group_ids' => [$group]])
            ->assertCreated()->assertJsonPath('data.added', 0);
        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/participants"), ['role' => 'STUDENT'])->assertStatus(422);

        // Otra organización no ve ni usa el grupo; un alumno no los administra
        $this->provisionTenant('beta', 'admin@beta.com');
        $beta = $this->login('beta', 'admin@beta.com');
        $this->withToken($beta)->getJson($this->tenantUrl('beta', '/groups'))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($beta)->getJson($this->tenantUrl('beta', "/groups/{$group}"))->assertNotFound();
        $this->withToken($this->login('acme', $people[1]->email))->postJson($this->api('/groups'), ['name' => 'No autorizado'])->assertForbidden();

        // Borrar el grupo no toca a las personas ni sus matrículas
        $this->withToken($this->admin)->deleteJson($this->api("/groups/{$group}"))->assertOk();
        $this->assertSame(1, Enrollment::query()->withoutGlobalScopes()->count());
    }
}
