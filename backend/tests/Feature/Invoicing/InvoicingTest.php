<?php

namespace Tests\Feature\Invoicing;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Comprobantes electrónicos con Mifact (S14): la academia conecta su cuenta
 * y cada pago confirmado lleva su boleta o factura, que queda aceptada por
 * SUNAT o avisa al personal para corregirla.
 */
class InvoicingTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://demo.mifact.net.pe/api/invoiceService.svc';

    private Tenant $tenant;

    private string $admin;

    private User $buyer;

    private string $course;

    /** Lo que responde Mifact a cada método (estado_documento, errors, sunat_description…) */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->responses = [
            'SendInvoice' => ['estado_documento' => '102', 'sunat_description' => 'La Boleta ha sido aceptada', 'codigo_hash' => 'hash-de-prueba', 'errors' => ''],
            'GetEstatusInvoice' => ['estado_documento' => '', 'errors' => 'El documento consultado no existe: CPE'],
            'GetInvoice' => ['pdf_bytes' => base64_encode('%PDF-1.4 comprobante'), 'errors' => ''],
        ];
        Http::fake(function (ClientRequest $request) {
            $method = basename($request->url());
            $response = $this->responses[$method] ?? [];
            // Una lista = respuestas en orden (la última se repite)
            if (array_is_list($response) && $response !== []) {
                $response = count($response) > 1 ? array_shift($this->responses[$method]) : $response[0];
            }

            // Mifact antepone la marca BOM a su JSON
            return Http::response("\xEF\xBB\xBF".json_encode($response));
        });

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $this->buyer = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => 'Ana', 'last_name' => 'Torres', 'email' => 'ana@acme.com', 'doc_type' => 'DNI', 'doc_number' => '40506089']);
        $this->course = $this->publishedCourse(150);
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function publishedCourse(float $price): string
    {
        $admin = fn () => $this->withToken($this->admin);
        $course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => $price])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Texto.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return $course;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function connect(array $changes = []): TestResponse
    {
        return $this->withToken($this->admin)->putJson($this->api('/settings/invoicing'), $changes + [
            'enabled' => true,
            'service_url' => self::URL,
            'ruc' => '20100100100',
            'legal_name' => 'Academia Acme S.A.C.',
            'trade_name' => 'Acme',
            'address' => 'Av. Arequipa 123, Lima',
            'ubigeo' => '150101',
            'establishment_code' => '0000',
            'boleta_series' => 'B001',
            'factura_series' => 'F001',
            'igv_mode' => 'INCLUDED',
            'token' => 'token-de-la-academia',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $billing
     */
    private function order(?array $billing = null, ?string $course = null): string
    {
        $this->flushHeaders();

        return $this->withToken($this->login('acme', 'ana@acme.com'))->postJson($this->api('/orders'), array_filter(['course_id' => $course ?? $this->course, 'billing' => $billing]))
            ->assertCreated()->json('data.id');
    }

    private function confirm(string $order): void
    {
        $this->flushHeaders();
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'TRANSFER'])->assertOk();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sent(): array
    {
        return Http::recorded(fn (ClientRequest $request) => str_ends_with($request->url(), '/SendInvoice'))
            ->map(fn (array $pair) => $pair[0]->data())->values()->all();
    }

    private function invoices(): Collection
    {
        return app(TenantContext::class)->runForTenant($this->tenant->id, fn () => Invoice::query()->orderBy('created_at')->get());
    }

    public function test_the_academy_connects_mifact_after_checking_the_token_and_the_token_never_comes_back(): void
    {
        // Mifact responde que el token no corresponde: no se guarda
        $this->responses['GetEstatusInvoice'] = ['estado_documento' => '', 'errors' => 'Error : TOKEN INCORRECTO PARA 20100100100'];
        $this->connect()->assertStatus(422)->assertJsonPath('details.token.0', 'El token no corresponde a ese RUC. Revisa el token que te dio Mifact.');
        $this->withToken($this->admin)->getJson($this->api('/settings/invoicing'))->assertJsonPath('data', null);

        $this->responses['GetEstatusInvoice'] = ['estado_documento' => '', 'errors' => 'El documento consultado no existe'];
        $this->connect()->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.demo', true)
            ->assertJsonPath('data.has_token', true)
            ->assertJsonPath('data.token_hint', '…emia')
            ->assertJsonMissingPath('data.token');

        // Sin token nuevo se conserva el guardado; una dirección que no es de Mifact no se acepta
        $this->connect(['token' => null, 'boleta_series' => 'B002'])->assertOk()->assertJsonPath('data.boleta_series', 'B002');
        $this->connect(['service_url' => 'https://otro-sitio.com/api/'])->assertStatus(422)->assertJsonStructure(['details' => ['service_url']]);
    }

    public function test_a_confirmed_payment_issues_a_boleta_with_the_buyer_document_and_the_igv_included(): void
    {
        $this->connect()->assertOk();

        $this->confirm($this->order());

        $invoice = $this->invoices()->sole();
        $this->assertSame('B001-00000001', $invoice->code());
        $this->assertSame(Invoice::STATUS_ACCEPTED, $invoice->status);
        $this->assertSame('hash-de-prueba', $invoice->hash);

        $document = $this->sent()[0];
        $this->assertSame('token-de-la-academia', $document['TOKEN']);
        $this->assertSame('03', $document['COD_TIP_CPE']);
        $this->assertSame('1', $document['COD_TIP_NIF_RECP']);
        $this->assertSame('40506089', $document['NUM_NIF_RECP']);
        $this->assertSame('Ana Torres', $document['NOM_RZN_SOC_RECP']);
        $this->assertSame(['127.12', '22.88', '150.00'], [$document['MNT_TOT_GRAVADO'], $document['MNT_TOT_TRIB_IGV'], $document['MNT_TOT']]);
        $this->assertSame('10', $document['items'][0]['COD_TIP_AFECT_IGV_ITEM']);
        $this->assertSame('Excel para finanzas', $document['items'][0]['TXT_DESC_ITEM']);
    }

    public function test_the_buyer_asks_for_a_factura_and_an_exonerated_academy_issues_without_igv(): void
    {
        $this->connect(['igv_mode' => 'EXONERATED'])->assertOk();

        $this->flushHeaders();
        $invalid = $this->withToken($this->login('acme', 'ana@acme.com'))->postJson($this->api('/orders'), ['course_id' => $this->course, 'billing' => ['type' => 'FACTURA', 'ruc' => '12345678901']])
            ->assertStatus(422);
        $this->assertSame('El RUC tiene 11 dígitos y empieza con 10, 15, 17 o 20.', $invalid->json('details')['billing.ruc'][0]);
        $this->assertSame('Escribe la razón social para la factura.', $invalid->json('details')['billing.name'][0]);

        $this->confirm($this->order(['type' => 'FACTURA', 'ruc' => '20601234569', 'name' => 'Empresa Cliente S.A.C.', 'address' => 'Av. Lima 456']));

        $this->assertSame('F001-00000001', $this->invoices()->sole()->code());
        $document = $this->sent()[0];
        $this->assertSame(['01', '6', '20601234569', 'Empresa Cliente S.A.C.'], [$document['COD_TIP_CPE'], $document['COD_TIP_NIF_RECP'], $document['NUM_NIF_RECP'], $document['NOM_RZN_SOC_RECP']]);
        $this->assertSame(['150.00', '0.00'], [$document['MNT_TOT_EXONERADO'], $document['MNT_TOT_TRIB_IGV']]);
        $this->assertArrayNotHasKey('MNT_TOT_GRAVADO', $document);
        $this->assertSame('20', $document['items'][0]['COD_TIP_AFECT_IGV_ITEM']);
    }

    public function test_each_installment_gets_its_own_invoice_with_the_next_number(): void
    {
        $this->connect()->assertOk();
        $order = $this->order();
        $this->withToken($this->admin)->putJson($this->api("/orders/{$order}/installments"), ['installments' => [
            ['amount' => 100, 'due_date' => now()->addDay()->toDateString()],
            ['amount' => 50, 'due_date' => now()->addMonth()->toDateString()],
        ]])->assertOk();

        $this->confirm($order);
        $this->confirm($order);

        $invoices = $this->invoices();
        $this->assertSame(['B001-00000001', 'B001-00000002'], $invoices->map->code()->all());
        $this->assertSame(['100.00', '50.00'], $invoices->pluck('total_amount')->all());
        $this->assertSame('Cuota 2 · Excel para finanzas', $invoices[1]->description);
    }

    public function test_a_number_already_used_in_mifact_moves_to_the_next_one_and_the_series_continues_after_the_last_used(): void
    {
        // La academia ya emitió boletas desde el portal de Mifact: sigue desde el último número (y nunca baja)
        $this->connect(['boleta_last_number' => 120])->assertOk()->assertJsonPath('data.boleta_last_number', 120);
        $this->connect(['token' => null, 'boleta_last_number' => 5])->assertOk()->assertJsonPath('data.boleta_last_number', 120);

        // El 121 también estaba usado (no es un envío nuestro: su estado no aparece): pasa al 122
        $this->responses['SendInvoice'] = [
            ['estado_documento' => '', 'errors' => 'Se encontraron errores: Error el documento ya existe en la BD'],
            ['estado_documento' => '102', 'sunat_description' => 'Aceptada', 'errors' => ''],
        ];
        $this->confirm($this->order());

        $invoice = $this->invoices()->sole();
        $this->assertSame('B001-00000122', $invoice->code());
        $this->assertSame(Invoice::STATUS_ACCEPTED, $invoice->status);
    }

    public function test_without_the_invoicing_switched_on_nothing_is_issued(): void
    {
        $this->connect(['enabled' => false])->assertOk();

        $this->confirm($this->order());

        $this->assertCount(0, $this->invoices());
        $this->assertSame([], $this->sent());
    }

    public function test_a_rejected_invoice_warns_the_staff_and_a_retry_issues_a_new_number(): void
    {
        $this->connect()->assertOk();
        $this->responses['SendInvoice'] = ['estado_documento' => '104', 'sunat_description' => 'El número de documento del receptor no es válido', 'errors' => ''];

        $order = $this->order();
        $this->confirm($order);

        $rejected = $this->invoices()->sole();
        $this->assertSame(Invoice::STATUS_REJECTED, $rejected->status);
        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo('admin@acme.com') && str_contains($mail->title, 'B001-00000001'));

        $this->withToken($this->admin)->getJson($this->api("/orders/{$order}/invoices"))
            ->assertJsonPath('data.0.status', 'REJECTED')
            ->assertJsonPath('data.0.can_retry', true)
            ->assertJsonPath('data.0.message', 'El número de documento del receptor no es válido');

        $this->responses['SendInvoice'] = ['estado_documento' => '102', 'sunat_description' => 'Aceptada', 'errors' => ''];
        $this->withToken($this->admin)->postJson($this->api("/invoices/{$rejected->id}/retry"))->assertOk()
            ->assertJsonPath('data.code', 'B001-00000002')
            ->assertJsonPath('data.status', 'ACCEPTED');
    }

    public function test_a_large_boleta_without_document_waits_for_the_staff_to_complete_it(): void
    {
        $this->connect()->assertOk();
        $this->buyer->forceFill(['doc_type' => null, 'doc_number' => null])->save();
        $order = $this->order(null, $this->publishedCourse(800));

        $this->confirm($order);

        // No se envía: SUNAT la rechazaría. Se avisa al personal de inmediato
        $invoice = $this->invoices()->sole();
        $this->assertSame(Invoice::STATUS_ERROR, $invoice->status);
        $this->assertStringStartsWith('Falta el documento del comprador', $invoice->provider_message);
        $this->assertSame([], $this->sent());
        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => str_contains($mail->title, 'B001-00000001'));

        $this->withToken($this->admin)->putJson($this->api("/orders/{$order}/billing"), ['billing' => ['type' => 'BOLETA', 'doc_type' => 'DNI', 'doc_number' => '4050']])
            ->assertStatus(422);
        $this->withToken($this->admin)->putJson($this->api("/orders/{$order}/billing"), ['billing' => ['type' => 'BOLETA', 'doc_type' => 'DNI', 'doc_number' => '40506089']])->assertOk();
        $this->withToken($this->admin)->postJson($this->api("/invoices/{$invoice->id}/retry"))->assertOk()
            ->assertJsonPath('data.code', 'B001-00000001')
            ->assertJsonPath('data.status', 'ACCEPTED');
        $this->assertSame('40506089', $this->sent()[0]['NUM_NIF_RECP']);
    }

    public function test_an_invoice_still_at_sunat_is_checked_again_until_it_is_accepted(): void
    {
        $this->connect()->assertOk();
        $this->responses['SendInvoice'] = ['estado_documento' => '101', 'errors' => ''];
        $this->confirm($this->order());
        $this->assertSame(Invoice::STATUS_PROCESSING, $this->invoices()->sole()->status);

        $this->responses['GetEstatusInvoice'] = ['estado_documento' => '102', 'sunat_description' => 'Aceptado por SUNAT', 'errors' => ''];
        $this->travel(5)->minutes();
        $this->artisan('invoices:check')->assertSuccessful();

        $this->assertSame(Invoice::STATUS_ACCEPTED, $this->invoices()->sole()->status);
    }

    public function test_the_buyer_downloads_only_their_own_invoices(): void
    {
        $this->connect()->assertOk();
        $order = $this->order();
        $this->confirm($order);
        $invoice = $this->invoices()->sole();

        $this->flushHeaders();
        $buyer = $this->login('acme', 'ana@acme.com');
        $this->withToken($buyer)->getJson($this->api("/me/orders/{$order}/invoices"))
            ->assertOk()
            ->assertJsonPath('data.0.code', 'B001-00000001')
            ->assertJsonPath('data.0.has_pdf', true)
            ->assertJsonMissingPath('data.0.message');
        $pdf = $this->withToken($buyer)->get($this->api("/me/invoices/{$invoice->id}/pdf"))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('%PDF-1.4 comprobante', $pdf->getContent());

        // Otra persona de la academia no sabe que existe; otra academia, tampoco
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'luis@acme.com']);
        $this->flushHeaders();
        $this->withToken($this->login('acme', 'luis@acme.com'))->get($this->api("/me/invoices/{$invoice->id}/pdf"), ['Accept' => 'application/json'])->assertNotFound();

        $this->provisionTenant('beta', 'admin@beta.com');
        $this->flushHeaders();
        $this->withToken($this->login('beta', 'admin@beta.com'))->get($this->tenantUrl('beta', "/invoices/{$invoice->id}/pdf"), ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_the_invoice_is_dated_in_the_academy_time_not_the_server_time(): void
    {
        $this->connect()->assertOk();
        $order = $this->order();

        // 8:30 p. m. en Lima ya es el día siguiente en UTC: SUNAT no acepta una fecha futura
        $this->travelTo(now('America/Lima')->setDate(2026, 11, 3)->setTime(20, 30));
        $this->flushHeaders();
        $this->admin = $this->login('acme', 'admin@acme.com');
        $this->confirm($order);

        $this->assertSame('2026-11-03', $this->sent()[0]['FEC_EMIS']);
        $this->assertSame('2026-11-03', $this->invoices()->sole()->issue_date->toDateString());
    }
}
