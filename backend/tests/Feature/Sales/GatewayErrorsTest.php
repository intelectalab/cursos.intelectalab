<?php

namespace Tests\Feature\Sales;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Sales\Application\Payments\Online\GatewayErrors;
use App\Modules\Tenancy\Domain\Models\Tenant;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cuando una pasarela falla, el mensaje dice qué pasó y qué hacer: no un
 * «inténtalo de nuevo» que esconda la causa. Quien paga recibe un mensaje
 * sencillo; quien administra la cuenta, el detalle.
 */
class GatewayErrorsTest extends TestCase
{
    use RefreshDatabase;

    private const MP = 'https://api.mercadopago.com';

    /**
     * @param  array<string, mixed>  $body
     */
    private function response(int $status, array $body): Response
    {
        return new Response(new PsrResponse($status, ['Content-Type' => 'application/json'], (string) json_encode($body)));
    }

    public function test_a_connection_failure_says_which_kind_it_was(): void
    {
        $message = fn (string $cause) => GatewayErrors::connection('Mercado Pago', new ConnectionException($cause))->getMessage();

        $this->assertStringContainsString('tardó demasiado', $message('cURL error 28: Operation timed out after 15001 milliseconds'));
        $this->assertStringContainsString('certificados del servidor', $message('cURL error 60: SSL certificate problem: unable to get local issuer certificate'));
        $this->assertStringContainsString('no tiene salida a internet', $message('cURL error 6: Could not resolve host: api.mercadopago.com'));
        $this->assertStringContainsString('no respondió', $message('algo que no conocemos'));

        // El 60 (certificados) no se confunde con el 6 (sin salida), ni el 77 con el 7
        $this->assertStringNotContainsString('salida a internet', $message('cURL error 60: SSL certificate problem'));
        $this->assertStringContainsString('certificados del servidor', $message('cURL error 77: error setting certificate verify locations'));
    }

    public function test_mercado_pago_rejections_explain_the_cause_and_unknown_ones_are_quoted(): void
    {
        $message = fn (int $status, array $body, bool $refund = false) => GatewayErrors::mercadoPago($this->response($status, $body), $refund)->getMessage();

        $this->assertStringContainsString('Conecta la cuenta otra vez', $message(401, ['message' => 'invalid access token']));
        $this->assertStringContainsString('Devuelve el dinero desde tu cuenta de Mercado Pago', $message(401, ['message' => 'Unauthorized use of live credentials']));
        // Así responde Mercado Pago a una clave que no existe: no con un 401
        $this->assertStringContainsString('conecta la cuenta otra vez', $message(403, ['message' => 'At least one policy returned UNAUTHORIZED.', 'code' => 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES']));
        $this->assertStringContainsString('problema en este momento (error 503', $message(503, []));
        $this->assertStringContainsString('pasó el plazo', $message(400, ['message' => 'The payment is too old to be refunded'], true));
        $this->assertStringContainsString('no tiene saldo disponible', $message(400, ['message' => 'Not enough available money'], true));
        $this->assertStringContainsString('no acepta la moneda', $message(400, ['message' => 'currency_id invalid']));

        // Lo que no se reconoce se muestra tal cual, y la causa que da Mercado Pago gana al mensaje general
        $this->assertStringContainsString('respondió: «Algo que nunca habíamos visto»', $message(400, ['message' => 'Algo que nunca habíamos visto']));
        $this->assertStringContainsString('«Detalle de la causa»', $message(400, ['message' => 'bad_request', 'cause' => [['code' => 9999, 'description' => 'Detalle de la causa']]]));
        $this->assertStringContainsString('no explicó por qué', $message(400, []));

        $error = GatewayErrors::mercadoPago($this->response(400, ['message' => 'Algo raro']));
        $this->assertSame(['gateway_status' => 400, 'gateway_reason' => 'Algo raro'], $error->details);
    }

    public function test_paypal_rejections_are_read_from_its_issue_code(): void
    {
        $message = fn (int $status, array $body, bool $refund = false) => GatewayErrors::payPal($this->response($status, $body), $refund)->getMessage();
        $issue = fn (string $code, string $description = '') => ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => $code, 'description' => $description]]];

        $this->assertStringContainsString('Conecta la cuenta otra vez', $message(401, ['error' => 'invalid_client', 'error_description' => 'Client Authentication failed']));
        $this->assertStringContainsString('restringida la cuenta que cobra', $message(422, $issue('PAYEE_ACCOUNT_RESTRICTED')));
        $this->assertStringContainsString('ya fue devuelto por completo', $message(422, $issue('CAPTURE_FULLY_REFUNDED'), true));
        $this->assertStringContainsString('no tiene saldo disponible', $message(422, $issue('REFUND_FAILED_INSUFFICIENT_FUNDS'), true));
        $this->assertStringContainsString('rechazó el medio de pago del comprador', $message(422, $issue('INSTRUMENT_DECLINED')));
        $this->assertStringContainsString('no puede cobrar en esa moneda', $message(422, $issue('CURRENCY_NOT_SUPPORTED', 'Currency code is not currently supported.')));
        $this->assertStringContainsString('respondió: «ALGO_NUEVO: Texto de PayPal»', $message(422, $issue('ALGO_NUEVO', 'Texto de PayPal')));
    }

    public function test_the_buyer_gets_a_plain_message_and_the_staff_gets_the_cause(): void
    {
        Http::preventStrayRequests();

        /** @var Tenant $tenant */
        $tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $admin = $this->login('acme', 'admin@acme.com');
        $api = fn (string $path) => $this->tenantUrl('acme', $path);
        $mercadoPago = function (array $responses): void {
            Http::swap(new HttpFactory);
            Http::preventStrayRequests();
            Http::fake(collect($responses)->mapWithKeys(fn ($response, string $path) => [self::MP.$path => $response])->all());
        };

        $course = $this->withToken($admin)->postJson($api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 120])->assertCreated()->json('data.id');
        $section = $this->withToken($admin)->postJson($api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $this->withToken($admin)->postJson($api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Hola.'])->assertCreated();
        $this->withToken($admin)->postJson($api("/courses/{$course}/publish"))->assertOk();

        $mercadoPago(['/users/me' => Http::response(['id' => 99, 'nickname' => 'ACADEMIAACME', 'site_id' => 'MPE'])]);
        $this->withToken($admin)->putJson($api('/settings/payment-gateways/mercadopago'), ['access_token' => 'APP_USR-clave-de-la-academia'])->assertOk();

        $this->createUser($tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $buyer = $this->login('acme', 'ana@acme.com');
        $order = $this->withToken($buyer)->postJson($api('/orders'), ['course_id' => $course])->assertCreated()->json('data.id');
        $pay = fn () => $this->withToken($buyer)->postJson($api("/me/orders/{$order}/pay"), ['gateway' => 'MERCADOPAGO', 'return_url' => "https://acme.localhost/my-orders/{$order}"]);

        // La clave de la academia dejó de valer: al comprador no se le habla de claves, y se le da un código para avisar
        $mercadoPago(['/checkout/preferences' => Http::response(['message' => 'At least one policy returned UNAUTHORIZED.', 'code' => 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES'], 403)]);
        $response = $pay()->assertStatus(502)->assertJsonPath('code', 'GATEWAY_ERROR');
        $this->assertStringContainsString('No se te cobró nada', (string) $response->json('message'));
        $this->assertStringNotContainsString('clave', (string) $response->json('message'));
        $this->assertNotEmpty($response->json('trace_id'));

        // El comprador sí llegó a pagar; al consultar, el personal ve la causa completa
        $mercadoPago(['/checkout/preferences' => Http::response(['id' => 'PREF-1', 'init_point' => 'https://www.mercadopago.com.pe/checkout?pref_id=PREF-1'], 201)]);
        $pay()->assertCreated();
        $mercadoPago(['/v1/payments/search*' => Http::response(['message' => 'At least one policy returned UNAUTHORIZED.', 'code' => 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES'], 403)]);

        $staff = $this->withToken($admin)->postJson($api("/orders/{$order}/recheck-payment"))->assertStatus(502)->json('message');
        $this->assertStringContainsString('conecta la cuenta otra vez', (string) $staff);

        $checking = $this->withToken($buyer)->postJson($api("/me/orders/{$order}/pay/sync"))->assertStatus(502)->json('message');
        $this->assertStringContainsString('Si ya pagaste, no vuelvas a pagar', (string) $checking);

        // Todo quedó anotado para el personal, con la causa completa aunque al comprador se le dijera otra cosa
        $errors = $this->withToken($admin)->getJson($api('/settings/payment-gateways/errors'))->assertOk()->json('data');
        $this->assertSame(['SYNC', 'CHECKOUT'], array_column($errors, 'operation'));
        $this->assertSame('Orden 00001', $errors[0]['subject']);
        $this->assertSame(403, $errors[0]['gateway_status']);
        $this->assertStringContainsString('conecta la cuenta otra vez', $errors[0]['message']);
        $this->assertNotEmpty($errors[0]['code']);
        // El mismo error dos veces seguidas (el personal y el comprador) se cuenta, no se repite
        $this->assertSame(2, $errors[0]['occurrences']);
        $this->assertSame(1, $errors[1]['occurrences']);

        // El comprador no los ve, y otra organización tampoco
        $this->withToken($buyer)->getJson($api('/settings/payment-gateways/errors'))->assertForbidden();
        $this->provisionTenant('otra', 'admin@otra.com');
        $this->withToken($this->login('otra', 'admin@otra.com'))->getJson($this->tenantUrl('otra', '/settings/payment-gateways/errors'))->assertOk()->assertJsonCount(0, 'data');
    }
}
