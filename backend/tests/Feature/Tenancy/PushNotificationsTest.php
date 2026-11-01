<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\PushGateway;
use App\Modules\Tenancy\Application\TenantNotifier;
use App\Modules\Tenancy\Domain\Models\EmailOptOut;
use App\Modules\Tenancy\Domain\Models\PushSubscription;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Avisos al teléfono: cada persona los activa en sus dispositivos y le llegan
 * los mismos avisos que por correo, con las mismas bajas. Cuenta y pagos
 * siguen solo por correo.
 */
class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'https://fcm.googleapis.com/fcm/send/dispositivo-de-ana';

    private Tenant $tenant;

    private User $student;

    /** @var list<array{endpoints: list<string>, payload: array<string, string>}> */
    private array $sent = [];

    /** @var list<string> */
    private array $expired = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.webpush.public_key' => 'llave-publica-de-prueba', 'services.webpush.private_key' => 'llave-privada-de-prueba']);
        Mail::fake();

        $this->app->instance(PushGateway::class, new class($this->sent, $this->expired) implements PushGateway
        {
            public function __construct(private array &$sent, private array &$expired) {}

            public function send(iterable $subscriptions, string $payload): array
            {
                $endpoints = collect($subscriptions)->pluck('endpoint')->all();
                $this->sent[] = ['endpoints' => $endpoints, 'payload' => json_decode($payload, true)];

                return ['delivered' => array_values(array_diff($endpoints, $this->expired)), 'expired' => array_values(array_intersect($endpoints, $this->expired))];
            }
        });

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->student = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['first_name' => 'Ana', 'email' => 'ana@acme.com']);
    }

    private function subscribe(string $endpoint = self::DEVICE, string $email = 'ana@acme.com'): void
    {
        $this->flushHeaders();
        $this->withToken($this->login('acme', $email))->postJson($this->tenantUrl('acme', '/auth/push/subscriptions'), [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'BOr-llave-del-dispositivo', 'auth' => 'secreto-del-dispositivo'],
        ])->assertCreated();
    }

    private function notify(User $user, string $key, array $values): void
    {
        app(TenantContext::class)->runForTenant($this->tenant->id, fn () => app(TenantNotifier::class)->notify($user, $key, $values, '/my-courses'));
    }

    public function test_each_person_turns_the_notices_on_and_off_per_device(): void
    {
        $token = $this->login('acme', 'ana@acme.com');

        $this->withToken($token)->getJson($this->tenantUrl('acme', '/auth/push'))
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.public_key', 'llave-publica-de-prueba')
            ->assertJsonPath('data.devices', 0);

        $this->subscribe();
        $this->subscribe('https://web.push.apple.com/otro-dispositivo');
        // El mismo dispositivo otra vez no se duplica
        $this->subscribe();
        $this->withToken($token)->getJson($this->tenantUrl('acme', '/auth/push'))->assertJsonPath('data.devices', 2);

        $this->withToken($token)->deleteJson($this->tenantUrl('acme', '/auth/push/subscriptions'), ['endpoint' => self::DEVICE])
            ->assertOk()
            ->assertJsonPath('data.devices', 1);
    }

    public function test_a_notice_that_can_be_turned_off_also_reaches_the_phone_with_the_same_opt_outs(): void
    {
        $this->subscribe();

        $this->notify($this->student, 'anuncio_curso', ['curso' => 'Excel para finanzas', 'titulo' => 'Cambio de horario', 'mensaje' => 'La clase será el viernes.']);

        $this->assertCount(1, $this->sent);
        $this->assertSame([self::DEVICE], $this->sent[0]['endpoints']);
        $this->assertSame('Cambio de horario · Excel para finanzas', $this->sent[0]['payload']['title']);
        $this->assertSame('anuncio_curso', $this->sent[0]['payload']['tag']);
        $this->assertStringEndsWith('/my-courses', $this->sent[0]['payload']['url']);

        // Se dio de baja de los anuncios: ni correo ni teléfono
        app(TenantContext::class)->runForTenant($this->tenant->id, fn () => EmailOptOut::query()->create(['email' => 'ana@acme.com', 'category' => 'announcements', 'source' => EmailOptOut::SOURCE_ACCOUNT]));
        $this->notify($this->student, 'anuncio_curso', ['curso' => 'Excel para finanzas', 'titulo' => 'Otro anuncio', 'mensaje' => 'Texto.']);

        $this->assertCount(1, $this->sent);
    }

    public function test_account_notices_stay_by_email_only(): void
    {
        $this->subscribe();

        $this->notify($this->student, 'cuenta_nueva', ['correo' => 'ana@acme.com']);

        $this->assertSame([], $this->sent);
    }

    public function test_a_device_that_no_longer_exists_is_forgotten(): void
    {
        $this->subscribe();
        $this->subscribe('https://web.push.apple.com/telefono-desinstalado');
        $this->expired = ['https://web.push.apple.com/telefono-desinstalado'];

        $this->notify($this->student, 'anuncio_curso', ['curso' => 'Excel', 'titulo' => 'Aviso', 'mensaje' => 'Texto.']);

        $remaining = app(TenantContext::class)->runForTenant($this->tenant->id, fn () => PushSubscription::query()->get());
        $this->assertCount(1, $remaining);
        $this->assertSame(self::DEVICE, $remaining->first()->endpoint);
        $this->assertNotNull($remaining->first()->last_used_at);
    }

    public function test_a_shared_device_belongs_to_whoever_turned_it_on_last(): void
    {
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'luis@acme.com']);
        $this->subscribe();
        $this->subscribe(self::DEVICE, 'luis@acme.com');

        $this->notify($this->student, 'anuncio_curso', ['curso' => 'Excel', 'titulo' => 'Aviso', 'mensaje' => 'Texto.']);

        $this->assertSame([], $this->sent);
    }

    public function test_the_test_notice_needs_a_device_and_the_server_keys(): void
    {
        $token = $this->login('acme', 'ana@acme.com');

        $this->withToken($token)->postJson($this->tenantUrl('acme', '/auth/push/test'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Activa primero los avisos en este dispositivo.');

        $this->subscribe();
        $this->withToken($token)->postJson($this->tenantUrl('acme', '/auth/push/test'))->assertOk();
        $this->assertSame('prueba', $this->sent[0]['payload']['tag']);

        // Sin llaves en el servidor no se pueden activar
        config(['services.webpush.private_key' => null]);
        $this->withToken($token)->getJson($this->tenantUrl('acme', '/auth/push'))->assertJsonPath('data.enabled', false)->assertJsonPath('data.public_key', null);
        $this->withToken($token)->postJson($this->tenantUrl('acme', '/auth/push/subscriptions'), [
            'endpoint' => self::DEVICE, 'keys' => ['p256dh' => 'x', 'auth' => 'y'],
        ])->assertStatus(409);
    }

    public function test_the_device_address_must_be_a_secure_push_service(): void
    {
        $this->withToken($this->login('acme', 'ana@acme.com'))->postJson($this->tenantUrl('acme', '/auth/push/subscriptions'), [
            'endpoint' => 'http://inseguro.test/x', 'keys' => ['p256dh' => 'x', 'auth' => 'y'],
        ])->assertStatus(422)->assertJsonStructure(['details' => ['endpoint']]);
    }
}
