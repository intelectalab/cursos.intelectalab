<?php

namespace Tests\Feature\Platform;

use App\Modules\Billing\Mail\PlanOrderMail;
use App\Modules\Platform\Application\BackupLog;
use App\Modules\Platform\Application\PlatformBackup;
use App\Modules\Platform\Domain\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
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

    public function test_the_console_shows_the_history_and_the_state_of_the_backups(): void
    {
        $log = app(BackupLog::class);
        $log->append(['kind' => 'DAILY', 'started_at' => now()->subDays(3)->toIso8601String(), 'finished_at' => now()->subDays(3)->toIso8601String(), 'ok' => true, 'size' => 1024]);
        $log->append(['kind' => 'DAILY', 'started_at' => now()->subDay()->toIso8601String(), 'finished_at' => now()->subDay()->toIso8601String(), 'ok' => false, 'error' => 'pg_dump no respondió']);

        $this->withToken($this->platformToken())->getJson($this->centralUrl('/platform/backups'))
            ->assertOk()
            ->assertJsonPath('meta.history.0.ok', false)
            ->assertJsonPath('meta.history.0.error', 'pg_dump no respondió')
            ->assertJsonPath('meta.history.1.ok', true)
            // El último bueno es de hace 3 días: más de 48 horas
            ->assertJsonPath('meta.status.healthy', false)
            ->assertJsonPath('meta.status.request', null);
    }

    public function test_backup_now_is_requested_once_and_the_scheduler_makes_it(): void
    {
        // Las órdenes del sistema se simulan; el último tar deja el archivo final como en el servidor
        Process::fake(function ($process) {
            $final = collect((array) $process->command)->first(fn ($part) => str_starts_with((string) $part, '--file=') && str_contains((string) $part, 'erp-manual-'));
            if ($final) {
                File::put(substr($final, 7), 'respaldo');
            }

            return Process::result();
        });
        $token = $this->platformToken();

        $this->withToken($token)->postJson($this->centralUrl('/platform/backups'))->assertStatus(202)->assertJsonPath('data.request', 'PENDING');
        $this->withToken($token)->postJson($this->centralUrl('/platform/backups'))->assertStatus(409);

        $this->artisan('platform-backups:run')->assertSuccessful();

        Process::assertRan(fn ($process) => is_array($process->command) && $process->command[0] === 'pg_dump' && in_array('--enable-row-security', $process->command, true));
        $entry = app(BackupLog::class)->recent(1)[0];
        $this->assertSame('MANUAL', $entry['kind']);
        $this->assertTrue($entry['ok'], (string) $entry['error']);
        $this->assertSame('root@plataforma.test', $entry['by']);
        $this->assertNull(app(PlatformBackup::class)->state());

        // Sin pedido, el programador no hace nada: la bitácora no suma otro respaldo
        $this->artisan('platform-backups:run')->expectsOutput('No hay respaldos pedidos.')->assertSuccessful();
        $this->assertCount(1, app(BackupLog::class)->recent());
    }

    public function test_a_failed_backup_is_logged_and_the_platform_is_warned(): void
    {
        Mail::fake();
        // Se mira la orden y no su texto: en Linux cada parte va entre comillas ('pg_dump' ...) y un patrón no coincidiría
        Process::preventStrayProcesses();
        Process::fake(fn ($process) => ((array) $process->command)[0] === 'pg_dump'
            ? Process::result(errorOutput: 'connection refused', exitCode: 1)
            : Process::result());
        $this->platformToken();

        app(PlatformBackup::class)->request('root@plataforma.test');
        $this->artisan('platform-backups:run')->assertFailed();

        $entry = app(BackupLog::class)->recent(1)[0];
        $this->assertFalse($entry['ok']);
        $this->assertStringContainsString('connection refused', $entry['error']);
        Mail::assertSent(PlanOrderMail::class, fn (PlanOrderMail $mail) => $mail->hasTo('root@plataforma.test') && $mail->title === 'Revisa los respaldos de la plataforma');
    }

    public function test_the_morning_check_warns_when_there_is_no_recent_good_backup(): void
    {
        Mail::fake();
        $this->platformToken();

        app(BackupLog::class)->append(['kind' => 'DAILY', 'started_at' => now()->subHours(3)->toIso8601String(), 'finished_at' => now()->subHours(3)->toIso8601String(), 'ok' => true, 'size' => 10]);
        $this->artisan('platform-backups:check')->assertSuccessful();
        Mail::assertNothingSent();

        $this->travel(3)->days();
        $this->artisan('platform-backups:check')->assertSuccessful();
        Mail::assertSent(PlanOrderMail::class);
    }

    public function test_manual_backups_can_be_downloaded(): void
    {
        File::put("{$this->directory}/erp-manual-2026-10-28-1130.tar.gz", 'manual');

        $this->withToken($this->platformToken())->get($this->centralUrl('/platform/backups/erp-manual-2026-10-28-1130.tar.gz'))
            ->assertOk()->assertDownload('erp-manual-2026-10-28-1130.tar.gz');
    }
}
