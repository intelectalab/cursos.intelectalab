<?php

namespace Tests\Feature\Support;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Support\Domain\Models\Ticket;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * S10 entrega 1: tickets de soporte. Crear → asignar → responder → resolver →
 * cerrar o reabrir (FP-17).
 */
class TicketTest extends TestCase
{
    use RefreshDatabase;

    private const JSON = ['Accept' => 'application/json'];

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private Tenant $tenant;

    private string $admin;

    private User $student;

    private string $token;

    private string $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('files.disk'));

        $this->tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->admin = $this->login('acme', 'admin@acme.com');
        $this->student = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com', 'first_name' => 'Ana', 'last_name' => 'Torres']);
        $this->token = $this->login('acme', 'ana@acme.com');
        $this->createUser($this->tenant, RoleCatalog::SUPPORT, ['email' => 'sol@acme.com', 'first_name' => 'Sol', 'last_name' => 'Soporte']);
        $this->agent = $this->login('acme', 'sol@acme.com');
    }

    private function api(string $path): string
    {
        return $this->tenantUrl('acme', $path);
    }

    private function upload(string $name, string $content = self::PDF): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ticket(array $attributes = [], ?string $token = null): string
    {
        return $this->withToken($token ?? $this->token)->postJson($this->api('/tickets'), $attributes + [
            'subject' => 'No puedo entrar al curso', 'category' => 'ACCESS', 'body' => 'Me sale que no tengo matrícula.',
        ])->assertCreated()->json('data.id');
    }

    private function staffReply(string $ticket, array $data = ['body' => 'Ya lo revisamos.'], ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->agent)->postJson($this->api("/tickets/{$ticket}/messages"), $data);
    }

    public function test_a_ticket_goes_from_open_to_closed_through_a_conversation(): void
    {
        $ticket = $this->withToken($this->token)->postJson($this->api('/tickets'), [
            'subject' => 'No puedo entrar al curso', 'category' => 'ACCESS', 'body' => 'Me sale que no tengo matrícula.',
        ])->assertCreated()
            ->assertJsonPath('data.number', 1)
            ->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.priority', 'NORMAL')
            ->assertJsonPath('data.awaits_staff', true)
            ->assertJsonPath('data.messages.0.author', 'Ana Torres')
            ->json('data.id');

        // Soporte la ve en su bandeja, esperando respuesta
        $this->withToken($this->agent)->getJson($this->api('/tickets?status=PENDING'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $ticket)
            ->assertJsonPath('meta.counts.awaiting', 1)
            ->assertJsonPath('meta.counts.unassigned', 1)
            // Las tarjetas: cuántos hay en cada vista
            ->assertJsonPath('meta.states.ALL', 1)
            ->assertJsonPath('meta.states.AWAITING', 1)
            ->assertJsonPath('meta.states.UNASSIGNED', 1)
            ->assertJsonPath('meta.states.RESOLVED', 0);

        // La primera respuesta la pone en atención y se la asigna a quien responde
        $this->staffReply($ticket)
            ->assertCreated()
            ->assertJsonPath('data.status', 'IN_PROGRESS')
            ->assertJsonPath('data.assignee.name', 'Sol Soporte')
            ->assertJsonPath('data.awaits_staff', false)
            ->assertJsonPath('data.messages.1.is_staff', true);

        $this->withToken($this->agent)->postJson($this->api("/tickets/{$ticket}/status"), ['status' => 'RESOLVED'])
            ->assertOk()->assertJsonPath('data.status', 'RESOLVED');

        // Si quien consulta responde un ticket resuelto, se reabre solo
        $this->withToken($this->token)->postJson($this->api("/me/tickets/{$ticket}/messages"), ['body' => 'Sigue sin funcionar.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'IN_PROGRESS')
            ->assertJsonPath('data.resolved_at', null)
            ->assertJsonPath('data.awaits_staff', true)
            ->assertJsonCount(3, 'data.messages');

        $this->withToken($this->agent)->postJson($this->api("/tickets/{$ticket}/status"), ['status' => 'CLOSED'])
            ->assertOk()->assertJsonPath('data.status', 'CLOSED');

        // Cerrado ya no admite mensajes ni cambios, de ningún lado
        $this->withToken($this->token)->postJson($this->api("/me/tickets/{$ticket}/messages"), ['body' => '¿Hola?'])
            ->assertStatus(409)->assertJsonPath('code', 'TICKET_CLOSED');
        $this->staffReply($ticket)->assertStatus(409);
        $this->withToken($this->agent)->postJson($this->api("/tickets/{$ticket}/status"), ['status' => 'OPEN'])->assertStatus(409);

        app(TenantContext::class)->runForTenant($this->tenant->id, function () {
            $this->assertSame(1, AuditEvent::query()->where('action', 'tickets.created')->count());
            $this->assertSame(1, AuditEvent::query()->where('action', 'tickets.reopened')->count());
            $this->assertSame(2, AuditEvent::query()->where('action', 'tickets.status_changed')->count());
        });
    }

    public function test_internal_notes_stay_with_the_staff(): void
    {
        $ticket = $this->ticket();

        // Una nota interna no cuenta como respuesta: ni asigna ni cambia el estado
        $this->staffReply($ticket, ['body' => 'Parece un pago sin confirmar.', 'is_internal' => true])
            ->assertCreated()
            ->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.assignee', null)
            ->assertJsonPath('data.awaits_staff', true)
            ->assertJsonPath('data.messages.1.is_internal', true);

        $this->withToken($this->token)->getJson($this->api("/me/tickets/{$ticket}"))
            ->assertOk()->assertJsonCount(1, 'data.messages');
    }

    public function test_attachments_are_validated_and_only_reach_the_people_in_the_ticket(): void
    {
        $post = fn (array $files) => $this->withToken($this->token)->post($this->api('/tickets'), [
            'subject' => 'Error al pagar', 'category' => 'PAYMENT', 'body' => 'Adjunto la captura.', 'files' => $files,
        ], self::JSON);

        // Un archivo disfrazado, un tipo no permitido y más de tres adjuntos se rechazan
        $post([$this->upload('captura.pdf', '<?php echo 1;')])->assertStatus(422);
        $post([$this->upload('programa.exe', 'MZ')])->assertStatus(422);
        $post([$this->upload('a.pdf'), $this->upload('b.pdf'), $this->upload('c.pdf'), $this->upload('d.pdf')])->assertStatus(422);

        $data = $post([$this->upload('comprobante.pdf')])->assertCreated()->json('data');
        $attachment = $data['messages'][0]['attachments'][0];
        $this->assertSame('comprobante.pdf', $attachment['name']);

        // El personal adjunta algo en una nota interna
        $note = $this->withToken($this->agent)->post($this->api("/tickets/{$data['id']}/messages"), [
            'body' => 'Extracto del banco.', 'is_internal' => '1', 'files' => [$this->upload('extracto.pdf')],
        ], self::JSON)->assertCreated()->json('data.messages.1.attachments.0.id');

        $download = fn (string $token, string $id) => $this->withToken($token)->get($this->api("/tickets/attachments/{$id}"), self::JSON);

        $download($this->token, $attachment['id'])->assertOk();
        $download($this->agent, $attachment['id'])->assertOk();
        $download($this->agent, $note)->assertOk();
        // Quien consulta no descarga lo de una nota interna, ni otro alumno lo ajeno
        $download($this->token, $note)->assertStatus(404);
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'beto@acme.com']);
        $download($this->login('acme', 'beto@acme.com'), $attachment['id'])->assertStatus(404);
    }

    public function test_a_ticket_can_only_refer_to_the_requesters_own_course_or_order(): void
    {
        $admin = fn () => $this->withToken($this->admin);
        $course = $admin()->postJson($this->api('/courses'), ['title' => 'Excel para finanzas', 'language' => 'es', 'price' => 50])->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');
        $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => 'Bienvenida', 'body' => 'Texto.']);
        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'beto@acme.com']);
        $other = $this->login('acme', 'beto@acme.com');
        $order = $this->withToken($other)->postJson($this->api('/orders'), ['course_id' => $course])->assertCreated()->json('data.id');
        $admin()->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'YAPE'])->assertOk();

        $open = fn (string $token, array $data) => $this->withToken($token)->postJson($this->api('/tickets'), $data + [
            'subject' => 'Consulta sobre mi compra', 'category' => 'PAYMENT', 'body' => 'Tengo una duda.',
        ]);

        // Ana no compró ni lleva ese curso
        $open($this->token, ['course_id' => $course])->assertStatus(422);
        $open($this->token, ['order_id' => $order])->assertStatus(422);

        $open($other, ['course_id' => $course, 'order_id' => $order])
            ->assertCreated()
            ->assertJsonPath('data.course.title', 'Excel para finanzas')
            ->assertJsonPath('data.order.number', 1);

        $options = $this->withToken($other)->getJson($this->api('/me/tickets/options'))->assertOk()->json('data');
        $this->assertSame([$course], array_column($options['courses'], 'id'));
        $this->assertSame([$order], array_column($options['orders'], 'id'));
        $this->assertSame([], $this->withToken($this->token)->getJson($this->api('/me/tickets/options'))->json('data.courses'));
    }

    public function test_staff_assign_prioritize_and_filter_the_inbox(): void
    {
        $first = $this->ticket();
        $second = $this->ticket(['subject' => 'No llega mi certificado', 'category' => 'CERTIFICATE']);
        $admin = app(TenantContext::class)->runForTenant($this->tenant->id, fn () => User::query()->where('email', 'admin@acme.com')->value('id'));

        // Solo se asigna a quien atiende tickets
        $this->withToken($this->agent)->putJson($this->api("/tickets/{$first}"), ['assignee_id' => $this->student->id])->assertStatus(422);
        $this->withToken($this->agent)->putJson($this->api("/tickets/{$first}"), ['assignee_id' => $admin, 'priority' => 'HIGH'])
            ->assertOk()->assertJsonPath('data.priority', 'HIGH')->assertJsonPath('data.assignee.name', 'Ada Admin');

        $list = fn (string $query) => array_column($this->withToken($this->agent)->getJson($this->api("/tickets?{$query}"))->assertOk()->json('data'), 'id');

        $this->assertSame([$second], $list('assignee=none'));
        $this->assertSame([], $list('assignee=me'));
        $this->assertSame([$first], $list("assignee={$admin}"));
        $this->assertSame([$second], $list('category=CERTIFICATE'));
        $this->assertSame([$second], $list('search=certificado'));
        $this->assertSame([$first, $second], $list('search=ana@acme.com'));
        $this->assertSame([$second], $list('search=2'));

        // Respondido el primero, el que sigue esperando sube al inicio
        $this->staffReply($first);
        $this->assertSame([$second, $first], $list('status=PENDING'));

        $agents = $this->withToken($this->agent)->getJson($this->api('/tickets/agents'))->json('data');
        $this->assertEqualsCanonicalizing(['Ada Admin', 'Sol Soporte'], array_column($agents, 'name'));
    }

    public function test_the_requester_can_close_a_ticket_and_resolved_ones_close_after_a_week(): void
    {
        $mine = $this->ticket();
        $this->withToken($this->token)->postJson($this->api("/me/tickets/{$mine}/close"))->assertOk()->assertJsonPath('data.status', 'CLOSED');

        $resolved = $this->ticket(['subject' => 'Duda sobre el temario']);
        $this->staffReply($resolved);
        $this->withToken($this->agent)->postJson($this->api("/tickets/{$resolved}/status"), ['status' => 'RESOLVED'])->assertOk();
        $pending = $this->ticket(['subject' => 'Otra consulta']);

        $this->travel(6)->days();
        $this->artisan('tickets:close-resolved')->assertSuccessful();
        $this->assertSame('RESOLVED', $this->statusOf($resolved));

        $this->travel(2)->days();
        $this->artisan('tickets:close-resolved')->assertSuccessful();
        $this->assertSame('CLOSED', $this->statusOf($resolved));
        // Uno sin resolver no se cierra por antigüedad
        $this->assertSame('OPEN', $this->statusOf($pending));
    }

    private function statusOf(string $ticket): string
    {
        return app(TenantContext::class)->runForTenant($this->tenant->id, fn () => Ticket::query()->findOrFail($ticket)->status);
    }

    public function test_tickets_respect_roles_and_organizations(): void
    {
        $ticket = $this->ticket();
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'beto@acme.com']);
        $this->createUser($this->tenant, RoleCatalog::TEACHER, ['email' => 'tere@acme.com']);
        $this->createUser($this->tenant, RoleCatalog::COMPANY, ['email' => 'rita@andes.pe']);
        $other = $this->login('acme', 'beto@acme.com');

        // Otro alumno no ve el ticket ni entra a la bandeja del personal
        $this->withToken($other)->getJson($this->api("/me/tickets/{$ticket}"))->assertStatus(404);
        $this->withToken($other)->postJson($this->api("/me/tickets/{$ticket}/messages"), ['body' => 'Hola'])->assertStatus(404);
        $this->withToken($other)->getJson($this->api('/me/tickets'))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($other)->getJson($this->api('/tickets'))->assertStatus(403);
        $this->withToken($this->token)->getJson($this->api("/tickets/{$ticket}"))->assertStatus(403);
        $this->withToken($this->token)->postJson($this->api("/tickets/{$ticket}/status"), ['status' => 'CLOSED'])->assertStatus(403);

        // El profesor no tiene soporte en su rol; el responsable de empresa sí abre tickets
        $this->withToken($this->login('acme', 'tere@acme.com'))->postJson($this->api('/tickets'), [])->assertStatus(403);
        $this->ticket(['subject' => 'Consulta de licencias'], $this->login('acme', 'rita@andes.pe'));

        $this->provisionTenant('globex', 'admin@globex.com');
        $globex = $this->login('globex', 'admin@globex.com');
        $this->withToken($globex)->getJson($this->tenantUrl('globex', '/tickets'))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($globex)->getJson($this->tenantUrl('globex', "/tickets/{$ticket}"))->assertStatus(404);
        $this->withToken($globex)->postJson($this->tenantUrl('globex', "/tickets/{$ticket}/messages"), ['body' => 'Hola'])->assertStatus(404);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson($this->api('/tickets'), ['subject' => 'Sin sesión', 'category' => 'OTHER', 'body' => 'Hola'])->assertStatus(401);
    }
}
