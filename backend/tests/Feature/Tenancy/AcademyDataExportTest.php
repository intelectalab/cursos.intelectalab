<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;
use ZipArchive;

/**
 * «Descargar los datos de mi academia»: sus datos en Excel y sus archivos en
 * un ZIP, solo para quien administra la configuración y solo de su academia.
 */
class AcademyDataExportTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function test_the_admin_downloads_a_zip_with_the_data_and_the_files_of_the_academy(): void
    {
        Storage::fake(config('files.disk'));
        $tenant = $this->provisionTenant('acme', 'admin@acme.com');
        $this->provisionTenant('beta', 'admin@beta.com');
        $this->createUser($tenant, RoleCatalog::STUDENT, ['first_name' => 'Ana', 'email' => 'ana@acme.com']);
        $token = $this->login('acme', 'admin@acme.com');

        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, base64_decode(self::PNG));
        $this->withToken($token)->post($this->tenantUrl('acme', '/settings/logo'), ['file' => new UploadedFile($path, 'logo.png', null, null, true)], ['Accept' => 'application/json'])->assertOk();

        $response = $this->withToken($token)->get($this->tenantUrl('acme', '/settings/data-export'))->assertOk();
        $this->assertStringStartsWith('attachment; filename=datos-acme-', (string) $response->headers->get('Content-Disposition'));

        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $names = array_map(fn (int $index) => $zip->getNameIndex($index), range(0, $zip->numFiles - 1));
        $this->assertContains('datos.xlsx', $names);
        $this->assertContains('LEEME.txt', $names);
        $this->assertNotEmpty(array_filter($names, fn (string $name) => str_starts_with($name, 'archivos/tenant_logo/') && str_ends_with($name, 'logo.png')));

        // Las personas de la academia, y no las de otra
        $workbook = tempnam(sys_get_temp_dir(), 'xls').'.xlsx';
        file_put_contents($workbook, $zip->getFromName('datos.xlsx'));
        $zip->close();
        $reader = new Reader;
        $reader->open($workbook);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheets[$sheet->getName()] = array_map(fn ($row) => $row->toArray(), iterator_to_array($sheet->getRowIterator(), false));
        }
        $reader->close();

        $this->assertSame(['Personas', 'Cursos', 'Matrículas', 'Órdenes', 'Pagos', 'Certificados'], array_keys($sheets));
        $emails = array_column($sheets['Personas'], 2);
        $this->assertContains('ana@acme.com', $emails);
        $this->assertContains('admin@acme.com', $emails);
        $this->assertNotContains('admin@beta.com', $emails);

        // Un alumno no puede bajar los datos de la academia
        $this->flushHeaders();
        $this->withToken($this->login('acme', 'ana@acme.com'))->get($this->tenantUrl('acme', '/settings/data-export'))->assertForbidden();
    }
}
