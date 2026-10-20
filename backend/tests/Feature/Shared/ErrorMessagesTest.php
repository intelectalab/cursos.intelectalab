<?php

namespace Tests\Feature\Shared;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiExceptionRenderer;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tests\TestCase;

/**
 * Todo error de la API llega con un mensaje propio en español: los textos
 * escritos en abort(), los choques de la base de datos y los del framework.
 */
class ErrorMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_message_written_in_abort_reaches_the_user(): void
    {
        $tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $admin = $this->login('acme', 'admin@acme.com');
        $roleId = $this->withToken($admin)->postJson($this->tenantUrl('acme', '/roles'), ['name' => 'Solo ventas', 'permissions' => ['reports.read', 'orders.read']])->json('data.id');
        $user = app(TenantContext::class)->runForTenant($tenant->id, function () use ($roleId) {
            $user = User::factory()->create(['password' => self::PASSWORD]);
            $user->roles()->attach($roleId);

            return $user;
        });

        $this->withToken($this->login('acme', $user->email))->getJson($this->tenantUrl('acme', '/dashboard/academic'))
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN')
            ->assertJsonPath('message', 'Tu rol no puede ver esta parte del Panel.');
    }

    public function test_framework_errors_never_show_their_english_text(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');

        $this->getJson($this->tenantUrl('acme', '/no-existe'))->assertNotFound()->assertJsonPath('message', 'El recurso solicitado no existe.');
        $this->getJson($this->tenantUrl('acme', '/users'))->assertUnauthorized()->assertJsonPath('message', 'Tu sesión terminó o no has iniciado sesión. Vuelve a entrar.');

        $denied = ApiExceptionRenderer::render(new AccessDeniedHttpException('This action is unauthorized.'));
        $this->assertSame('No tienes permiso para hacer esto.', $denied->getData()->message);

        $bad = ApiExceptionRenderer::render(new BadRequestHttpException('Invalid Host "x".'));
        $this->assertSame('BAD_REQUEST', $bad->getData()->code);
        $this->assertStringNotContainsString('Invalid', $bad->getData()->message);
    }

    public function test_database_clashes_are_explained(): void
    {
        $duplicated = ApiExceptionRenderer::render(new UniqueConstraintViolationException('pgsql', 'insert …', [], $this->pdo('23505')));
        $this->assertSame(409, $duplicated->getStatusCode());
        $this->assertSame('RECORD_EXISTS', $duplicated->getData()->code);

        $inUse = ApiExceptionRenderer::render(new QueryException('pgsql', 'delete …', [], $this->pdo('23503')));
        $this->assertSame(409, $inUse->getStatusCode());
        $this->assertSame('RECORD_IN_USE', $inUse->getData()->code);
        $this->assertSame('No se puede quitar porque otros datos lo están usando.', $inUse->getData()->message);
    }

    private function pdo(string $sqlState): PDOException
    {
        $error = new PDOException("SQLSTATE[{$sqlState}]");
        // PostgreSQL entrega el SQLSTATE como código de la excepción
        (new \ReflectionProperty(\Exception::class, 'code'))->setValue($error, $sqlState);
        $error->errorInfo = [$sqlState, 7, 'detalle'];

        return $error;
    }
}
