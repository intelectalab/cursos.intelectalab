<?php

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * RF-REP-001: los listados y el tablero se descargan en Excel con los mismos
 * filtros de la pantalla.
 */
class ExcelExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-02 15:00:00');
        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    /**
     * Hojas del Excel descargado: nombre => filas.
     *
     * @return array<string, list<list<mixed>>>
     */
    private function sheets(TestResponse $response): array
    {
        $response->assertOk();

        $reader = new Reader;
        $reader->open($response->baseResponse->getFile()->getPathname());

        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = array_map(fn ($row) => $row->toArray(), iterator_to_array($sheet->getRowIterator(), false));
            // Arriba van la academia, el título y la fecha (la fila en blanco no se lee); al final, los totales
            $rows = array_slice($rows, 3);
            if ($rows !== [] && end($rows)[0] === 'Total') {
                array_pop($rows);
            }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();

        return $sheets;
    }

    /**
     * Ana compra y paga «Excel» (S/ 100); Luis deja su orden sin pagar.
     */
    private function sales(): void
    {
        $admin = fn () => $this->withToken($this->admin);

        $course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel', 'price' => 100, 'language' => 'es'])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Texto.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        foreach (['ana@acme.com' => true, 'luis@acme.com' => false] as $email => $pays) {
            $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => $email, 'first_name' => ucfirst(strtok($email, '@')), 'last_name' => 'Prueba']);
            $order = $this->withToken($this->login('acme', $email))->postJson($this->api('/orders'), ['course_id' => $course])->assertCreated()->json('data.id');

            if ($pays) {
                $admin()->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'YAPE', 'reference' => 'OP-1'])->assertOk();
            }
        }
    }

    public function test_orders_students_and_enrollments_are_exported_with_the_filters_of_the_screen(): void
    {
        $this->sales();
        $admin = fn () => $this->withToken($this->admin);

        // Órdenes: todas, y solo las pagadas
        $orders = $this->sheets($admin()->get($this->api('/orders/export')))['Órdenes de compra'];
        $this->assertSame('N.º', $orders[0][0]);
        $this->assertCount(3, $orders);

        $paid = $this->sheets($admin()->get($this->api('/orders/export?status=PAID')))['Órdenes de compra'];
        $this->assertCount(2, $paid);
        $row = array_combine($paid[0], $paid[1]);
        $this->assertSame('Pagada', $row['Estado']);
        $this->assertSame('ana@acme.com', $row['Correo']);
        $this->assertSame('Excel', $row['Detalle']);
        $this->assertEquals(100, $row['Total']);
        $this->assertSame('Yape', $row['Medio de pago']);
        $this->assertSame('OP-1', $row['N.º de operación']);
        // La hora sale en la de la organización (Lima), no en UTC
        $this->assertSame('2026-03-02 10:00', $row['Fecha de pago']);

        // Alumnos: la búsqueda acota el archivo
        $students = $this->sheets($admin()->get($this->api('/students/export?search=luis')))['Alumnos'];
        $this->assertCount(2, $students);
        $this->assertSame('luis@acme.com', array_combine($students[0], $students[1])['Correo']);

        $enrolled = $this->sheets($admin()->get($this->api('/students/export?filter=ENROLLED')))['Alumnos'];
        $this->assertSame(['ana@acme.com'], array_column(array_slice($enrolled, 1), 2));

        // Matrículas: solo Ana quedó matriculada
        $enrollments = $this->sheets($admin()->get($this->api('/enrollments/export')))['Matrículas'];
        $this->assertCount(2, $enrollments);
        $row = array_combine($enrollments[0], $enrollments[1]);
        $this->assertSame('Excel', $row['Curso']);
        $this->assertSame('Activa', $row['Situación']);
        $this->assertSame('Compra', $row['Origen']);
        $this->assertEquals(0, $row['Avance (%)']);
    }

    public function test_the_dashboard_is_exported_with_one_sheet_per_block(): void
    {
        $this->sales();

        $sheets = $this->sheets($this->withToken($this->admin)->get($this->api('/reports/overview/export?from=2026-03-01&to=2026-03-31')));

        $this->assertSame(['Resumen', 'Hoy', 'Por día', 'Cursos más vendidos', 'Finalización por curso'], array_keys($sheets));
        $this->assertSame('Ingresos cobrados (PEN)', $sheets['Resumen'][1][0]);
        $this->assertEquals(100, $sheets['Resumen'][1][1]);
        $this->assertEquals(1, $sheets['Hoy'][1][1]);
        $this->assertCount(32, $sheets['Por día']);
        $this->assertSame('Excel', $sheets['Cursos más vendidos'][1][0]);
    }

    public function test_leads_are_exported_and_exports_need_the_permission_of_their_screen(): void
    {
        $admin = fn () => $this->withToken($this->admin);
        $admin()->postJson($this->api('/crm/leads'), ['first_name' => 'Carla', 'last_name' => 'Ríos', 'company_name' => 'Minera Andes', 'email' => 'carla@andes.pe', 'source' => 'EVENT'])->assertCreated();
        $admin()->postJson($this->api('/crm/leads'), ['first_name' => 'Pedro', 'email' => 'pedro@otra.pe', 'source' => 'WEB'])->assertCreated();

        $leads = $this->sheets($admin()->get($this->api('/crm/leads/export?search=andes')))['Prospectos'];
        $this->assertCount(2, $leads);
        $row = array_combine($leads[0], $leads[1]);
        $this->assertSame('Minera Andes', $row['Empresa']);
        $this->assertSame('Evento', $row['Origen']);
        $this->assertSame('Nuevo', $row['Estado']);

        // Un alumno no descarga nada de esto
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $student = $this->login('acme', 'ana@acme.com');

        foreach (['/orders/export', '/students/export', '/enrollments/export', '/crm/leads/export', '/reports/overview/export'] as $path) {
            $this->withToken($student)->get($this->api($path), ['Accept' => 'application/json'])->assertForbidden();
        }
    }

    public function test_the_file_carries_the_academy_title_filters_totals_and_other_formats(): void
    {
        $this->sales();
        $admin = fn () => $this->withToken($this->admin);

        $response = $admin()->get($this->api('/orders/export?status=PAID'))->assertOk();
        $reader = new Reader;
        $reader->open($response->baseResponse->getFile()->getPathname());
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = array_map(fn ($row) => $row->toArray(), iterator_to_array($sheet->getRowIterator(), false));
        }
        $reader->close();

        // Cabecera con la academia, el título y el filtro usado; al final, el total de lo vendido
        $this->assertSame($this->tenant->name, $rows[0][0]);
        $this->assertSame('Órdenes de compra', $rows[1][0]);
        $this->assertStringContainsString('Estado: Pagada', $rows[2][0]);
        $total = end($rows);
        $this->assertSame('Total', $total[0]);
        $this->assertEquals(100, $total[array_search('Total', $rows[3], true)]);

        // PDF, CSV y ODS
        $pdf = $admin()->get($this->api('/orders/export?format=pdf'))->assertOk();
        $this->assertStringStartsWith('%PDF', file_get_contents($pdf->baseResponse->getFile()->getPathname()));
        $this->assertStringEndsWith('.pdf', (string) $pdf->headers->get('Content-Disposition'));

        $csv = file_get_contents($admin()->get($this->api('/students/export?format=csv'))->assertOk()->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('Nombres,Apellidos,Correo', $csv);

        $this->assertStringEndsWith('.ods', (string) $admin()->get($this->api('/enrollments/export?format=ods'))->assertOk()->headers->get('Content-Disposition'));
        $admin()->get($this->api('/orders/export?format=doc'))->assertStatus(422);
    }
}
