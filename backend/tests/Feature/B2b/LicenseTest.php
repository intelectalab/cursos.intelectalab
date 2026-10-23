<?php

namespace Tests\Feature\B2b;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\B2b\Domain\Models\License;
use App\Modules\Identity\Application\SyncAccessCatalog;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S8 entrega 3: venta pagada → licencias → asignación → matrícula (FP-04, RN-010),
 * y el portal del Responsable de empresa.
 */
class LicenseTest extends TestCase
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

    /**
     * Curso publicado con dos lecciones y 90 días de acceso.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{course: string, resources: list<string>}
     */
    private function publishedCourse(array $attributes = []): array
    {
        $admin = fn () => $this->withToken($this->admin);

        $course = $admin()->postJson($this->api('/courses'), $attributes + [
            'title' => 'Power BI desde cero', 'language' => 'es', 'price' => 150, 'access_days' => 90, 'issues_certificate' => true,
        ])->assertCreated()->json('data.id');
        $section = $admin()->postJson($this->api("/courses/{$course}/sections"), ['title' => 'Módulo 1'])->json('data.id');

        $resources = [];
        foreach (['Bienvenida', 'Cierre'] as $title) {
            $resources[] = $admin()->postJson($this->api("/courses/{$course}/sections/{$section}/resources"), ['type' => 'TEXT', 'title' => $title, 'body' => 'Texto.'])->json('data.id');
        }

        $admin()->postJson($this->api("/courses/{$course}/publish"))->assertOk();

        return compact('course', 'resources');
    }

    private function company(string $name = 'Minera Andes S.A.'): string
    {
        return $this->withToken($this->admin)->postJson($this->api('/crm/companies'), ['name' => $name])->assertCreated()->json('data.id');
    }

    /**
     * Cotización aceptada: devuelve la orden pendiente de pago.
     */
    private function sell(string $company, string $course, int $licenses): string
    {
        $quotation = $this->withToken($this->admin)->postJson($this->api('/quotations'), [
            'company_id' => $company,
            'items' => [['course_id' => $course, 'quantity' => $licenses, 'unit_price' => 100]],
        ])->assertCreated()->json('data.id');

        $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/send"))->assertOk();

        return $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/accept"))->assertOk()->json('data.order.id');
    }

    private function pay(string $order): void
    {
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/confirm-payment"), ['method' => 'TRANSFER'])->assertOk();
    }

    /**
     * El personal registra a una persona en la empresa y devuelve su id de usuario.
     */
    private function member(string $company, string $email, bool $manager = false): string
    {
        $members = $this->withToken($this->admin)->postJson($this->api("/corporate-accounts/{$company}/members"), [
            'first_name' => ucfirst(strtok($email, '@')), 'last_name' => 'Andes', 'email' => $email, 'password' => self::PASSWORD, 'is_manager' => $manager,
        ])->assertCreated()->json('data.members');

        return collect($members)->firstWhere('email', $email)['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function account(string $company): array
    {
        return $this->withToken($this->admin)->getJson($this->api("/corporate-accounts/{$company}"))->assertOk()->json('data');
    }

    public function test_a_paid_company_sale_delivers_licenses_and_assigning_one_enrolls_the_worker(): void
    {
        ['course' => $course] = $this->publishedCourse();
        $company = $this->company();
        $order = $this->sell($company, $course, 3);

        // Sin pago todavía no hay licencias
        $this->assertSame([], $this->account($company)['courses']);

        $this->pay($order);

        $pool = $this->account($company)['courses'][0];
        $this->assertSame([3, 0, 3], [$pool['total'], $pool['assigned'], $pool['available']]);
        $this->assertSame(now()->addMonths(12)->toDateString(), substr($pool['assign_before'], 0, 10));

        $this->member($company, 'rita@andes.pe', manager: true);
        $worker = $this->member($company, 'tomas@andes.pe');

        // El responsable asigna desde su portal
        $manager = $this->login('acme', 'rita@andes.pe');
        $pool = $this->withToken($manager)->postJson($this->api("/company/courses/{$course}/assign"), ['student_ids' => [$worker]])
            ->assertCreated()
            ->assertJsonPath('data.company.name', 'Minera Andes S.A.')
            ->json('data.courses.0');

        $this->assertSame([3, 1, 2], [$pool['total'], $pool['assigned'], $pool['available']]);
        $this->assertSame('Tomas Andes', $pool['assignments'][0]['student']['name']);
        $this->assertSame(0, $pool['assignments'][0]['progress']);
        $this->assertTrue($pool['assignments'][0]['can_revoke']);
        // La matrícula usa los días de acceso del curso, contados desde la asignación
        $this->assertSame(now()->addDays(90)->toDateString(), substr($pool['assignments'][0]['access_until'], 0, 10));

        // El trabajador ya tiene el curso (TC-B2B-001)
        $this->withToken($this->login('acme', 'tomas@andes.pe'))->getJson($this->api('/me/courses'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.course_id', $course);

        app(TenantContext::class)->runForTenant($this->tenant->id, function () {
            $this->assertSame(1, AuditEvent::query()->where('action', 'licenses.issued')->count());
            $this->assertSame(1, AuditEvent::query()->where('action', 'licenses.assigned')->count());
        });
    }

    public function test_a_used_license_cannot_be_assigned_again(): void
    {
        ['course' => $course] = $this->publishedCourse();
        $company = $this->company();
        $this->pay($this->sell($company, $course, 1));
        $first = $this->member($company, 'tomas@andes.pe');
        $second = $this->member($company, 'ines@andes.pe');
        $outsider = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);

        $license = app(TenantContext::class)->runForTenant($this->tenant->id, fn () => License::query()->value('id'));
        $assign = fn (string $student) => $this->withToken($this->admin)->postJson($this->api("/licenses/{$license}/assign"), ['student_id' => $student]);

        // Solo trabajadores de la empresa
        $assign($outsider->id)->assertStatus(422);

        $assign($first)->assertCreated()->assertJsonPath('data.courses.0.available', 0);

        // TC-B2B-002
        $assign($second)->assertStatus(409)->assertJsonPath('code', 'LICENSE_NOT_AVAILABLE');
        $this->withToken($this->admin)->postJson($this->api("/corporate-accounts/{$company}/courses/{$course}/assign"), ['student_ids' => [$second]])
            ->assertStatus(409)->assertJsonPath('code', 'LICENSE_NOT_AVAILABLE');
    }

    public function test_assigning_more_workers_than_licenses_assigns_none(): void
    {
        ['course' => $course] = $this->publishedCourse();
        $company = $this->company();
        $this->pay($this->sell($company, $course, 2));
        $workers = [$this->member($company, 'tomas@andes.pe'), $this->member($company, 'ines@andes.pe'), $this->member($company, 'raul@andes.pe')];

        $this->withToken($this->admin)->postJson($this->api("/corporate-accounts/{$company}/courses/{$course}/assign"), ['student_ids' => $workers])
            ->assertStatus(409)->assertJsonPath('details.available', 2);
        $this->assertSame(0, $this->account($company)['courses'][0]['assigned']);

        // Quien ya está matriculado en el curso no consume una licencia
        $this->withToken($this->admin)->postJson($this->api("/courses/{$course}/enrollments"), ['student_ids' => [$workers[0]]])->assertCreated();
        $this->withToken($this->admin)->postJson($this->api("/corporate-accounts/{$company}/courses/{$course}/assign"), ['student_ids' => [$workers[0], $workers[1]]])
            ->assertStatus(409)->assertJsonPath('code', 'ALREADY_ENROLLED');
        $this->assertSame(0, $this->account($company)['courses'][0]['assigned']);
    }

    public function test_a_license_can_be_taken_back_only_before_the_worker_starts(): void
    {
        ['course' => $course, 'resources' => $resources] = $this->publishedCourse();
        $company = $this->company();
        $this->pay($this->sell($company, $course, 1));
        $first = $this->member($company, 'tomas@andes.pe');
        $second = $this->member($company, 'ines@andes.pe');
        $assign = fn (string $student) => $this->withToken($this->admin)
            ->postJson($this->api("/corporate-accounts/{$company}/courses/{$course}/assign"), ['student_ids' => [$student]])
            ->assertCreated()->json('data.courses.0.assignments.0.id');
        $revoke = fn (string $assignment) => $this->withToken($this->admin)->postJson($this->api("/corporate-accounts/{$company}/assignments/{$assignment}/revoke"));

        // No empezó: la licencia vuelve a estar disponible y pierde el curso
        $revoke($assign($first))->assertOk()->assertJsonPath('data.courses.0.available', 1);
        $this->withToken($this->login('acme', 'tomas@andes.pe'))->getJson($this->api('/me/courses'))->assertJsonCount(0, 'data');

        // La misma licencia sirve para otra persona, que sí empieza el curso
        $assignment = $assign($second);
        $worker = $this->login('acme', 'ines@andes.pe');
        $this->withToken($worker)->postJson($this->api("/me/courses/{$course}/resources/{$resources[0]}/complete"))->assertOk();

        $revoke($assignment)->assertStatus(409)->assertJsonPath('code', 'LICENSE_IN_USE');

        $pool = $this->account($company)['courses'][0];
        $this->assertSame(50, $pool['assignments'][0]['progress']);
        $this->assertFalse($pool['assignments'][0]['can_revoke']);

        // Al terminar, la empresa ve el curso completado y el certificado
        $this->withToken($worker)->postJson($this->api("/me/courses/{$course}/resources/{$resources[1]}/complete"))->assertOk();
        $done = $this->account($company)['courses'][0]['assignments'][0];
        $this->assertSame(100, $done['progress']);
        $this->assertNotNull($done['completed_at']);
        $this->assertNotNull($done['certificate_code']);
    }

    public function test_licenses_can_be_released_before_payment_with_a_reason_and_are_voided_if_the_sale_is_cancelled(): void
    {
        ['course' => $course] = $this->publishedCourse();
        $company = $this->company();
        $order = $this->sell($company, $course, 3);
        $release = fn (string $token, array $data) => $this->withToken($token)->postJson($this->api("/orders/{$order}/release-licenses"), $data);

        // El vendedor no puede; hace falta el permiso de finanzas
        $this->createUser($this->tenant, RoleCatalog::SALES, ['email' => 'vera@acme.com']);
        $release($this->login('acme', 'vera@acme.com'), ['reason' => 'Paga a 30 días'])->assertStatus(403);

        $release($this->admin, [])->assertStatus(422);
        $release($this->admin, ['reason' => 'Cliente con orden de compra, paga a 30 días'])
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.licenses_release_reason', 'Cliente con orden de compra, paga a 30 días');
        $release($this->admin, ['reason' => 'Otra vez'])->assertStatus(409);

        $this->assertSame(3, $this->account($company)['courses'][0]['available']);

        // Usan una y la venta se cancela: se anulan las que quedaban, la usada se conserva
        $worker = $this->member($company, 'tomas@andes.pe');
        $this->withToken($this->admin)->postJson($this->api("/corporate-accounts/{$company}/courses/{$course}/assign"), ['student_ids' => [$worker]])->assertCreated();
        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/cancel"))->assertOk();

        $pool = $this->account($company)['courses'][0];
        $this->assertSame([1, 1, 0], [$pool['total'], $pool['assigned'], $pool['available']]);

        // Pagar después de adelantar no entrega de nuevo
        $second = $this->sell($company, $course, 2);
        $this->withToken($this->admin)->postJson($this->api("/orders/{$second}/release-licenses"), ['reason' => 'Paga a 30 días'])->assertOk();
        $this->pay($second);
        $this->assertSame(2, $this->account($company)['courses'][0]['available']);

        app(TenantContext::class)->runForTenant($this->tenant->id, fn () => $this->assertSame(2, AuditEvent::query()->where('action', 'licenses.released_unpaid')->count()));
    }

    public function test_only_company_sales_release_licenses(): void
    {
        ['course' => $course] = $this->publishedCourse();
        $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);
        $order = $this->withToken($this->login('acme', 'ana@acme.com'))->postJson($this->api('/orders'), ['course_id' => $course])->assertCreated()->json('data.id');

        $this->withToken($this->admin)->postJson($this->api("/orders/{$order}/release-licenses"), ['reason' => 'No aplica'])
            ->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_CORPORATE');
    }

    public function test_the_company_manager_only_reaches_their_own_company(): void
    {
        ['course' => $course] = $this->publishedCourse();
        $andes = $this->company();
        $textiles = $this->company('Textiles del Sur');
        $this->pay($this->sell($andes, $course, 1));
        $this->pay($this->sell($textiles, $course, 1));

        $this->member($andes, 'rita@andes.pe', manager: true);
        $this->member($andes, 'tomas@andes.pe');
        $foreignWorker = $this->member($textiles, 'omar@textiles.pe');
        $foreignAssignment = $this->withToken($this->admin)
            ->postJson($this->api("/corporate-accounts/{$textiles}/courses/{$course}/assign"), ['student_ids' => [$foreignWorker]])
            ->json('data.courses.0.assignments.0.id');

        $manager = $this->login('acme', 'rita@andes.pe');

        $own = $this->withToken($manager)->getJson($this->api('/company'))->assertOk()->json('data');
        $this->assertSame('Minera Andes S.A.', $own['company']['name']);
        $this->assertEqualsCanonicalizing(['rita@andes.pe', 'tomas@andes.pe'], array_column($own['members'], 'email'));
        $this->assertCount(1, $own['orders']);

        // Ni asigna a gente de otra empresa ni le retira sus licencias
        $this->withToken($manager)->postJson($this->api("/company/courses/{$course}/assign"), ['student_ids' => [$foreignWorker]])->assertStatus(422);
        $this->withToken($manager)->postJson($this->api("/company/assignments/{$foreignAssignment}/revoke"))->assertStatus(404);

        // No entra a las pantallas del personal
        $this->withToken($manager)->getJson($this->api("/corporate-accounts/{$textiles}"))->assertStatus(403);
        $this->withToken($manager)->getJson($this->api('/crm/companies'))->assertStatus(403);
        $this->withToken($manager)->getJson($this->api('/quotations'))->assertStatus(403);
        $this->withToken($manager)->getJson($this->api('/users'))->assertStatus(403);

        // Un trabajador no administra la empresa
        $this->withToken($this->login('acme', 'tomas@andes.pe'))->getJson($this->api('/company'))->assertStatus(403);

        // El rol sin estar vinculado a una empresa no da acceso a ninguna
        $this->createUser($this->tenant, RoleCatalog::COMPANY, ['email' => 'suelto@acme.com']);
        $this->withToken($this->login('acme', 'suelto@acme.com'))->getJson($this->api('/company'))
            ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_the_manager_registers_workers_but_cannot_pull_in_existing_accounts(): void
    {
        $company = $this->company();
        $other = $this->company('Textiles del Sur');
        $this->member($company, 'rita@andes.pe', manager: true);
        $this->member($other, 'omar@textiles.pe');
        $existing = $this->createUser($this->tenant, RoleCatalog::STUDENT, ['email' => 'ana@acme.com']);

        $manager = $this->login('acme', 'rita@andes.pe');
        $add = fn (string $token, string $path, array $data) => $this->withToken($token)->postJson($this->api($path), $data + [
            'first_name' => 'Tomás', 'last_name' => 'Quispe', 'password' => self::PASSWORD,
        ]);

        // Aunque lo pida, el responsable no crea otros responsables
        $members = $add($manager, '/company/members', ['email' => 'Tomas@Andes.pe', 'is_manager' => true])->assertCreated()->json('data.members');
        $this->assertFalse(collect($members)->firstWhere('email', 'tomas@andes.pe')['is_manager']);
        $this->withToken($this->login('acme', 'tomas@andes.pe'))->getJson($this->api('/me/courses'))->assertOk();

        $add($manager, '/company/members', ['email' => 'tomas@andes.pe'])->assertStatus(422);
        $add($manager, '/company/members', ['email' => 'ana@acme.com'])->assertStatus(422);
        // Sin contraseña inicial, el trabajador nuevo recibe una invitación para crear la suya
        $add($manager, '/company/members', ['email' => 'nuevo@andes.pe', 'password' => null])->assertCreated();
        $this->assertNotNull(app(TenantContext::class)->runForTenant($this->tenant->id, fn () => User::query()->where('email', 'nuevo@andes.pe')->value('invited_at')));

        // El personal sí vincula una cuenta que ya existía, pero no la de otra empresa
        $members = $add($this->admin, "/corporate-accounts/{$company}/members", ['email' => 'ana@acme.com', 'password' => null])->assertCreated()->json('data.members');
        $this->assertSame($existing->id, collect($members)->firstWhere('email', 'ana@acme.com')['id']);
        $add($this->admin, "/corporate-accounts/{$company}/members", ['email' => 'omar@textiles.pe'])->assertStatus(422);
    }

    public function test_the_manager_answers_quotations_from_the_portal(): void
    {
        ['course' => $course] = $this->publishedCourse();
        $company = $this->company();
        $this->member($company, 'rita@andes.pe', manager: true);
        $manager = $this->login('acme', 'rita@andes.pe');

        $create = fn (string $for) => $this->withToken($this->admin)->postJson($this->api('/quotations'), [
            'company_id' => $for, 'items' => [['course_id' => $course, 'quantity' => 10, 'unit_price' => 100]],
        ])->json('data.id');
        $send = fn (string $quotation) => $this->withToken($this->admin)->postJson($this->api("/quotations/{$quotation}/send"))->assertOk();

        $draft = $create($company);
        $sent = $create($company);
        $send($sent);
        $toReject = $create($company);
        $send($toReject);
        $foreign = $create($this->company('Textiles del Sur'));
        $send($foreign);

        // Ve las enviadas a su empresa; ni borradores ni las de otra
        $list = $this->withToken($manager)->getJson($this->api('/company/quotations'))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$sent, $toReject], array_column($list, 'id'));
        $this->withToken($manager)->getJson($this->api("/company/quotations/{$draft}"))->assertStatus(404);
        $this->withToken($manager)->postJson($this->api("/company/quotations/{$foreign}/accept"))->assertStatus(404);
        $this->withToken($manager)->getJson($this->api('/company'))->assertJsonPath('data.pending_quotations', 2);

        $this->withToken($manager)->getJson($this->api("/company/quotations/{$sent}"))
            ->assertOk()->assertJsonPath('data.total_amount', '1000.00')->assertJsonMissingPath('data.versions');

        $this->withToken($manager)->postJson($this->api("/company/quotations/{$sent}/accept"))
            ->assertOk()->assertJsonPath('data.status', 'ACCEPTED')->assertJsonPath('data.accepted_via', 'PORTAL')->assertJsonPath('data.order.status', 'PENDING');

        $this->withToken($manager)->postJson($this->api("/company/quotations/{$toReject}/reject"), [])->assertStatus(422);
        $this->withToken($manager)->postJson($this->api("/company/quotations/{$toReject}/reject"), ['reason' => 'Fuera de presupuesto'])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED');

        $this->withToken($manager)->getJson($this->api('/company'))->assertJsonPath('data.pending_quotations', 0)->assertJsonCount(1, 'data.orders');
    }

    public function test_corporate_accounts_respect_roles_and_organizations(): void
    {
        ['course' => $course] = $this->publishedCourse();
        $company = $this->company();
        $this->pay($this->sell($company, $course, 1));
        $worker = $this->member($company, 'tomas@andes.pe');
        $license = app(TenantContext::class)->runForTenant($this->tenant->id, fn () => License::query()->value('id'));

        // Ventas cotiza pero no administra licencias; B2B autorizado sí
        $this->createUser($this->tenant, RoleCatalog::SALES, ['email' => 'vera@acme.com']);
        $this->createUser($this->tenant, RoleCatalog::B2B, ['email' => 'beto@acme.com']);
        $sales = $this->login('acme', 'vera@acme.com');
        $this->withToken($sales)->getJson($this->api("/corporate-accounts/{$company}"))->assertStatus(403);
        $this->withToken($sales)->postJson($this->api("/licenses/{$license}/assign"), ['student_id' => $worker])->assertStatus(403);

        $b2b = $this->login('acme', 'beto@acme.com');
        $this->withToken($b2b)->getJson($this->api("/corporate-accounts/{$company}"))->assertOk();

        $this->provisionTenant('globex', 'admin@globex.com');
        $globex = $this->login('globex', 'admin@globex.com');
        $url = fn (string $path) => $this->tenantUrl('globex', $path);

        $this->withToken($globex)->getJson($url("/corporate-accounts/{$company}"))->assertStatus(404);
        $this->withToken($globex)->postJson($url("/licenses/{$license}/assign"), ['student_id' => $worker])->assertStatus(404);
        $this->withToken($globex)->getJson($url('/company'))->assertStatus(403);

        $this->withToken($b2b)->postJson($this->api("/licenses/{$license}/assign"), ['student_id' => $worker])->assertCreated();
    }

    public function test_syncing_the_catalog_gives_existing_organizations_the_new_base_role(): void
    {
        $context = app(TenantContext::class);

        // Una organización creada antes de que existiera el rol
        $context->runForTenant($this->tenant->id, fn () => Role::query()->where('code', RoleCatalog::COMPANY)->delete());

        app(SyncAccessCatalog::class)->handle();
        app(SyncAccessCatalog::class)->handle();

        $context->runForTenant($this->tenant->id, function () {
            $role = Role::query()->where('code', RoleCatalog::COMPANY)->with('permissions')->sole();

            $this->assertTrue($role->is_system);
            $this->assertContains('company.portal', $role->permissions->pluck('code')->all());
        });
    }
}
