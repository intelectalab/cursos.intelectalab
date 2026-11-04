<?php

namespace Tests\Feature\Invoicing;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Notas de crédito, anulación y listado de comprobantes (S15, entrega 1):
 * devolver un pago emite sola la nota de crédito; un comprobante con error
 * se anula dentro del plazo de SUNAT y, pasado el plazo, se corrige con una
 * nota de crédito.
 */
class CreditNotesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://demo.mifact.net.pe/api/invoiceService.svc';

    private Tenant $tenant;

    private string $admin;

    private string $course;

    /** Lo que responde Mifact a cada método */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->responses = [
            'SendInvoice' => ['estado_documento' => '102', 'sunat_description' => 'Aceptada', 'codigo_hash' => 'hash', 'errors' => ''],
            'GetEstatusInvoice' => ['estado_documento' => '', 'errors' => 'El documento consultado no existe: CPE'],
            'LowInvoice' => ['estado_documento' => '108', 'errors' => ''],
        ];
        Http::fake(fn (ClientRequest $request) => Http::response("\xEF\xBB\xBF".json_encode($this->responses[basename($request->url())] ?? [])));

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => 'Ana', 'last_name' => 'Torres', 'email' => 'ana@acme.com', 'doc_type' => 'DNI', 'doc_number' => '40506089']);
        $this->course = $this->publishedCourse();
        $this->connect();
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function publishedCourse(): string
    {
        $admin = fn () => $this->withToken($this->admin);
        $course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 150])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Texto.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return $course;
    }

    private function connect(): void
    {
        $this->withToken($this->admin)->putJson($this->api('/settings/invoicing'), [
            'enabled' => true,
            'service_url' => self::URL,
            'ruc' => '20100100100',
            'legal_name' => 'Academia Acme S.A.C.',
            'address' => 'Av. Arequipa 123, Lima',
            'ubigeo' => '150101',
            'establishment_code' => '0000',
            'boleta_series' => 'B001',
            'factura_series' => 'F001',
            'igv_mode' => 'INCLUDED',
            'token' => 'token-de-la-academia',
        ])->assertOk()->assertJsonPath('data.credit_boleta_series', 'BC01');
    }

    /** Una compra de Ana pagada por transferencia */
    private function paidOrder(): string
    {
        $this->flushHeaders();
        $order = $this->withToken($this->login('acme', 'ana@acme.com'))->postJson($this->api('/orders'), ['course_id' => $this->course])->assertCreated()->json('data.id');
        $this->flushHeaders();
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'TRANSFER'])->assertOk();

        return $order;
    }

    private function refund(string $order): void
    {
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/refund"), ['reason' => 'El alumno se retiró del curso', 'reference' => 'Transferencia 123'], ['Idempotency-Key' => "devolucion-{$order}"])
            ->assertOk();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sentTo(string $method): array
    {
        return Http::recorded(fn (ClientRequest $request) => str_ends_with($request->url(), "/{$method}"))
            ->map(fn (array $pair) => $pair[0]->data())->values()->all();
    }

    private function invoices(): Collection
    {
        return app(TenantContext::class)->runForTenant($this->tenant->id, fn () => Invoice::query()->orderBy('created_at')->get());
    }

    public function test_a_refund_issues_a_credit_note_that_points_to_the_boleta(): void
    {
        $order = $this->paidOrder();
        $this->refund($order);

        [$boleta, $note] = $this->invoices()->all();
        $this->assertSame(Invoice::TYPE_CREDIT_NOTE, $note->document_type);
        $this->assertSame('BC01-00000001', $note->code());
        $this->assertSame(Invoice::STATUS_ACCEPTED, $note->status);
        $this->assertSame('150.00', $note->total_amount);

        $sent = $this->sentTo('SendInvoice')[1];
        $this->assertSame('07', $sent['COD_TIP_CPE']);
        $this->assertSame('06', $sent['COD_TIP_NC']);
        $this->assertSame('El alumno se retiró del curso', $sent['TXT_DESC_MTVO']);
        $this->assertSame(['COD_TIP_DOC_REF' => '03', 'NUM_SERIE_CPE_REF' => 'B001', 'NUM_CORRE_CPE_REF' => '00000001', 'FEC_DOC_REF' => $boleta->issue_date->toDateString()], $sent['docs_referenciado'][0]);
        $this->assertSame('40506089', $sent['NUM_NIF_RECP']);

        // La boleta queda corregida: ya no se anula ni lleva otra nota
        $this->withToken($this->admin)->getJson($this->api("/orders/{$order}/invoices"))
            ->assertJsonPath('data.0.credited', true)
            ->assertJsonPath('data.0.can_void', false)
            ->assertJsonPath('data.0.can_credit', false)
            ->assertJsonPath('data.1.type', 'NOTA_CREDITO')
            ->assertJsonPath('data.1.reference_code', 'B001-00000001');
        $this->withToken($this->admin)->postJson($this->api("/invoices/{$boleta->id}/void"), ['reason' => 'Error en los datos'])->assertStatus(409);
    }

    public function test_a_refund_before_the_boleta_went_out_cancels_it_instead_of_issuing_a_note(): void
    {
        $this->responses['SendInvoice'] = ['estado_documento' => '', 'errors' => 'Servicio no disponible'];
        $order = $this->paidOrder();
        $this->assertSame(Invoice::STATUS_ERROR, $this->invoices()->sole()->status);

        $this->refund($order);

        $this->assertSame(Invoice::STATUS_CANCELLED, $this->invoices()->sole()->status);
        $this->responses['SendInvoice'] = ['estado_documento' => '102', 'errors' => ''];
        $this->travel(5)->minutes();
        $this->artisan('invoices:check')->assertSuccessful();
        $this->assertCount(1, $this->sentTo('SendInvoice'));
        $this->assertSame(Invoice::STATUS_CANCELLED, $this->invoices()->sole()->status);
    }

    public function test_the_credit_note_waits_until_sunat_accepts_the_boleta(): void
    {
        $this->responses['SendInvoice'] = ['estado_documento' => '101', 'errors' => ''];
        $order = $this->paidOrder();
        $this->refund($order);

        // La nota no sale mientras la boleta sigue en SUNAT
        $this->assertCount(1, $this->sentTo('SendInvoice'));
        $this->assertSame(Invoice::STATUS_PENDING, $this->invoices()->last()->status);

        $this->responses['GetEstatusInvoice'] = ['estado_documento' => '102', 'errors' => ''];
        $this->responses['SendInvoice'] = ['estado_documento' => '102', 'errors' => ''];
        $this->travel(5)->minutes();
        $this->artisan('invoices:check')->assertSuccessful();
        $this->travel(5)->minutes();
        $this->artisan('invoices:check')->assertSuccessful();

        $this->assertSame([Invoice::STATUS_ACCEPTED, Invoice::STATUS_ACCEPTED], $this->invoices()->pluck('status')->all());
        $this->assertSame('07', $this->sentTo('SendInvoice')[1]['COD_TIP_CPE']);
    }

    public function test_a_boleta_with_an_error_is_voided_within_the_deadline_and_replaced_with_a_new_number(): void
    {
        $order = $this->paidOrder();
        $boleta = $this->invoices()->sole();

        $this->withToken($this->admin)->postJson($this->api("/invoices/{$boleta->id}/void"), ['reason' => ''])->assertStatus(422);
        $this->withToken($this->admin)->postJson($this->api("/invoices/{$boleta->id}/void"), ['reason' => 'Error en el nombre del cliente'])
            ->assertOk()
            ->assertJsonPath('data.status', 'VOIDING')
            ->assertJsonPath('data.can_void', false);
        $low = $this->sentTo('LowInvoice')[0];
        $this->assertSame(['03', 'B001', '00000001', 'Error en el nombre del cliente'], [$low['COD_TIP_CPE'], $low['NUM_SERIE_CPE'], $low['NUM_CORRE_CPE'], $low['TXT_DESC_MTVO']]);

        // SUNAT confirma la baja al final del día
        $this->responses['GetEstatusInvoice'] = ['estado_documento' => '105', 'errors' => ''];
        $this->travel(5)->minutes();
        $this->artisan('invoices:check')->assertSuccessful();
        $this->assertSame(Invoice::STATUS_VOIDED, $this->invoices()->sole()->status);

        // Se emite otro comprobante para el mismo pago
        $this->withToken($this->admin)->postJson($this->api("/invoices/{$boleta->id}/retry"))->assertOk()
            ->assertJsonPath('data.code', 'B001-00000002')
            ->assertJsonPath('data.status', 'ACCEPTED');
        $this->withToken($this->admin)->postJson($this->api("/invoices/{$boleta->id}/retry"))->assertStatus(409);
        $this->assertSame($order, $this->invoices()->last()->order_id);
    }

    public function test_when_sunat_refuses_the_void_the_boleta_stays_valid(): void
    {
        $this->paidOrder();
        $boleta = $this->invoices()->sole();
        $this->withToken($this->admin)->postJson($this->api("/invoices/{$boleta->id}/void"), ['reason' => 'Error en los datos'])->assertOk();

        $this->responses['GetEstatusInvoice'] = ['estado_documento' => '102', 'errors' => ''];
        $this->travel(5)->minutes();
        $this->artisan('invoices:check')->assertSuccessful();

        $boleta->refresh();
        $this->assertSame(Invoice::STATUS_ACCEPTED, $boleta->status);
        $this->assertStringContainsString('nota de crédito', $boleta->provider_message);
    }

    public function test_after_the_deadline_the_boleta_is_corrected_with_a_credit_note_instead(): void
    {
        $order = $this->paidOrder();
        $boleta = $this->invoices()->sole();
        $this->travel(Invoice::VOID_DAYS + 1)->days();
        // La sesión de antes ya venció
        $this->flushHeaders();
        $this->admin = $this->login('acme', 'admin@acme.com');

        $this->withToken($this->admin)->getJson($this->api("/orders/{$order}/invoices"))
            ->assertJsonPath('data.0.can_void', false)
            ->assertJsonPath('data.0.can_credit', true);
        $this->withToken($this->admin)->postJson($this->api("/invoices/{$boleta->id}/void"), ['reason' => 'Error en los datos'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Pasó el plazo de SUNAT para anularlo (7 días desde la emisión). Corrígelo con una nota de crédito.');
        $this->assertSame([], $this->sentTo('LowInvoice'));

        $this->withToken($this->admin)->postJson($this->api("/invoices/{$boleta->id}/credit-note"), ['reason' => 'Error en el nombre del cliente'])
            ->assertOk()
            ->assertJsonPath('data.code', 'BC01-00000001')
            ->assertJsonPath('data.status', 'ACCEPTED');
        $this->assertSame('01', $this->sentTo('SendInvoice')[1]['COD_TIP_NC']);
        $this->withToken($this->admin)->postJson($this->api("/invoices/{$boleta->id}/credit-note"), ['reason' => 'Otra vez'])->assertStatus(409);
    }

    public function test_the_invoice_list_filters_counts_and_downloads(): void
    {
        $refunded = $this->paidOrder();
        $this->refund($refunded);
        $this->responses['SendInvoice'] = ['estado_documento' => '104', 'sunat_description' => 'Rechazado', 'errors' => ''];
        $this->paidOrder();

        $this->withToken($this->admin)->getJson($this->api('/invoices'))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.counts.ALL', 3)
            ->assertJsonPath('meta.counts.VALID', 2)
            ->assertJsonPath('meta.counts.PROBLEM', 1);
        $this->withToken($this->admin)->getJson($this->api('/invoices?type=NOTA_CREDITO'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference_code', 'B001-00000001');
        $this->withToken($this->admin)->getJson($this->api('/invoices?state=PROBLEM'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'B001-00000002');
        $this->withToken($this->admin)->getJson($this->api('/invoices?search=B001-2'))->assertJsonCount(1, 'data');
        $this->withToken($this->admin)->getJson($this->api('/invoices?search=40506089'))->assertJsonCount(3, 'data');
        $this->withToken($this->admin)->get($this->api('/invoices/export?format=csv'))->assertOk()->assertDownload();

        // Un alumno no ve el listado
        $this->flushHeaders();
        $this->withToken($this->login('acme', 'ana@acme.com'))->getJson($this->api('/invoices'))->assertForbidden();
    }
}
