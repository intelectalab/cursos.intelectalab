<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Domain\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Respaldos semanales: el administrador de la plataforma los ve y los
 * descarga desde la consola; nadie más.
 */
class PlatformBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/respaldos');
        File::ensureDirectoryExists($this->directory);
        File::put("{$this->directory}/erp-semana-2026-10-18.tar.gz", 'copia anterior');
        File::put("{$this->directory}/erp-semana-2026-10-25.tar.gz", 'copia reciente');
        File::put("{$this->directory}/otro-archivo.txt", 'no es un respaldo');
        config(['tenancy.backups_path' => $this->directory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function platformToken(): string
    {
        PlatformAdmin::create(['name' => 'Root', 'email' => 'root@plataforma.test', 'password' => self::PASSWORD]);

        return $this->postJson($this->centralUrl('/platform/auth/login'), ['email' => 'root@plataforma.test', 'password' => self::PASSWORD])->json('data.token');
    }

    public function test_the_platform_admin_lists_and_downloads_the_weekly_backups(): void
    {
        $token = $this->platformToken();

        $this->withToken($token)->getJson($this->centralUrl('/platform/backups'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'erp-semana-2026-10-25.tar.gz')
            ->assertJsonPath('meta.configured', true);

        $response = $this->withToken($token)->get($this->centralUrl('/platform/backups/erp-semana-2026-10-25.tar.gz'));
        $response->assertOk()->assertDownload('erp-semana-2026-10-25.tar.gz');

        // Solo los respaldos: ni otros archivos ni rutas fuera de la carpeta
        $this->withToken($token)->getJson($this->centralUrl('/platform/backups/otro-archivo.txt'))->assertNotFound();
        $this->withToken($token)->getJson($this->centralUrl('/platform/backups/erp-semana-2026-01-01.tar.gz'))->assertNotFound();
    }

    public function test_academy_users_cannot_reach_the_backups(): void
    {
        $this->provisionTenant('acme', 'admin@acme.com');
        $token = $this->login('acme', 'admin@acme.com');

        $this->flushHeaders()->withToken($token)->getJson($this->centralUrl('/platform/backups'))->assertStatus(401);
        // Desde la dirección de una academia, la consola no existe
        $this->getJson($this->tenantUrl('acme', '/platform/backups'))->assertNotFound();
    }
}
