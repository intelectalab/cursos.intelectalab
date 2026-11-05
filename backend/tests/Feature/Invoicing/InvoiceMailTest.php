<?php

namespace Tests\Feature\Invoicing;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Mail\TenantNoticeMail;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * El comprobante le llega al comprador (S15, entrega 2): al quedar aceptado
 * por SUNAT sale un correo de la academia con el PDF adjunto, el personal lo
 * puede reenviar y el comprador baja el XML y la constancia de SUNAT.
 */
class InvoiceMailTest extends TestCase
{
    use RefreshDatabase;

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
            'SendInvoice' => ['estado_documento' => '102', 'sunat_description' => 'Aceptada', 'errors' => ''],
            'GetEstatusInvoice' => ['estado_documento' => '', 'errors' => 'El documento consultado no existe: CPE'],
        ];
        // GetInvoice devuelve solo el archivo que se pide; el XML y la constancia vienen en ZIP
        Http::fake(function (ClientRequest $request) {
            $method = basename($request->url());
            $data = $request->data();
            $response = $method === 'GetInvoice' ? array_filter([
                'pdf_bytes' => ($data['RETORNA_PDF'] ?? '') === 'true' ? base64_encode('%PDF-1.4 '.$data['NUM_SERIE_CPE']) : null,
                'xml_enviado' => ($data['RETORNA_XML_ENVIO'] ?? '') === 'true' ? rtrim(base64_encode('PK xml del comprobante'), '=') : null,
                'cdr_sunat' => ($data['RETORNA_XML_CDR'] ?? '') === 'true' ? base64_encode('PK constancia de SUNAT') : null,
                'errors' => '',
            ]) : ($this->responses[$method] ?? []);

            return Http::response("\xEF\xBB\xBF".json_encode($response));
        });

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => 'Ana', 'last_name' => 'Torres', 'email' => 'ana@acme.com', 'doc_type' => 'DNI', 'doc_number' => '40506089']);

        $admin = fn () => $this->withToken($this->admin);
        $this->course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 150])->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$this->course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$this->course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Texto.'])->assertCreated();
        $admin()->postJson($this->api("/courses/{$this->course}/publish"))->assertOk();
        $admin()->putJson($this->api('/settings/invoicing'), [
            'enabled' => true, 'service_url' => 'https://demo.mifact.net.pe/api/invoiceService.svc', 'ruc' => '20100100100',
            'legal_name' => 'Academia Acme S.A.C.', 'address' => 'Av. Arequipa 123, Lima', 'ubigeo' => '150101', 'establishment_code' => '0000',
            'boleta_series' => 'B001', 'factura_series' => 'F001', 'igv_mode' => 'INCLUDED', 'token' => 'token-de-la-academia',
        ])->assertOk();
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function paidOrder(): string
    {
        $this->flushHeaders();
        $order = $this->withToken($this->login('acme', 'ana@acme.com'))->postJson($this->api('/orders'), ['course_id' => $this->course])->assertCreated()->json('data.id');
        $this->flushHeaders();
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'TRANSFER'])->assertOk();

        return $order;
    }

    private function invoiceMails(): int
    {
        return Mail::sent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo('ana@acme.com') && str_contains($mail->title, ' electrónica '))->count();
    }

    private function invoice(string $series): Invoice
    {
        return app(TenantContext::class)->runForTenant($this->tenant->id, fn () => Invoice::query()->where('series', $series)->sole());
    }

    public function test_the_buyer_gets_the_accepted_boleta_by_email_with_the_pdf_attached(): void
    {
        $order = $this->paidOrder();

        Mail::assertSent(TenantNoticeMail::class, function (TenantNoticeMail $mail) use ($order) {
            return $mail->hasTo('ana@acme.com')
                && $mail->title === 'Tu boleta electrónica B001-00000001'
                && str_contains(implode(' ', $mail->lines), 'por S/ 150.00, de la orden N.º 00001')
                && str_ends_with((string) $mail->action['url'], "/my-orders/{$order}")
                && $mail->files[0]['name'] === 'B001-00000001.pdf'
                && base64_decode($mail->files[0]['data']) === '%PDF-1.4 B001';
        });
        $this->assertNotNull($this->invoice('B001')->emailed_at);

        // Otra consulta de SUNAT no lo vuelve a enviar
        $this->artisan('invoices:check')->assertSuccessful();
        $this->assertSame(1, $this->invoiceMails());
    }

    public function test_the_credit_note_of_a_refund_is_emailed_too(): void
    {
        $order = $this->paidOrder();
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/refund"), ['reason' => 'El alumno se retiró', 'reference' => 'Transferencia 1'], ['Idempotency-Key' => 'devolucion-1'])->assertOk();

        Mail::assertSent(TenantNoticeMail::class, fn (TenantNoticeMail $mail) => $mail->hasTo('ana@acme.com')
            && $mail->title === 'Tu nota de crédito electrónica BC01-00000001'
            && $mail->files[0]['name'] === 'BC01-00000001.pdf');
        $this->assertSame(2, $this->invoiceMails());
    }

    public function test_the_staff_resends_the_invoice_and_the_buyer_downloads_its_xml_and_cdr(): void
    {
        $order = $this->paidOrder();
        $boleta = $this->invoice('B001');

        $this->withToken($this->admin)->postJson($this->api("/invoices/{$boleta->id}/resend"))->assertOk()->assertJsonPath('data.sent_to', 'ana@acme.com');
        $this->assertSame(2, $this->invoiceMails());
        $this->withToken($this->admin)->getJson($this->api("/orders/{$order}/invoices"))->assertJsonPath('data.0.emailed_at', fn ($value) => $value !== null);

        $this->flushHeaders();
        $buyer = $this->login('acme', 'ana@acme.com');
        $xml = $this->withToken($buyer)->get($this->api("/me/invoices/{$boleta->id}/xml"))->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertHeader('Content-Disposition', 'attachment; filename="20100100100-03-B001-00000001.zip"');
        // El base64 sin relleno también se lee
        $this->assertSame('PK xml del comprobante', $xml->getContent());
        $cdr = $this->withToken($buyer)->get($this->api("/me/invoices/{$boleta->id}/cdr"))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="R-20100100100-03-B001-00000001.zip"');
        $this->assertSame('PK constancia de SUNAT', $cdr->getContent());
        $this->withToken($buyer)->postJson($this->api("/invoices/{$boleta->id}/resend"))->assertForbidden();

        // Otra persona de la academia no sabe que existe
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'luis@acme.com']);
        $this->flushHeaders();
        $this->withToken($this->login('acme', 'luis@acme.com'))->get($this->api("/me/invoices/{$boleta->id}/xml"), ['Accept' => 'application/json'])->assertNotFound();
    }
}
