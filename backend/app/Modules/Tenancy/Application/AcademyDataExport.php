<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Certificates\Domain\Models\Certificate;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Files\Domain\Models\File;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Export\ExcelExport;
use App\Shared\Export\ReportFile;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * «Descargar los datos de mi academia»: un ZIP con sus datos en Excel (una
 * hoja por tema) y sus archivos (logo, portadas, materiales, entregas,
 * firmas…). Es la copia propia de cada academia y le permite llevarse sus
 * datos. No incluye contraseñas ni claves de las pasarelas, y no se restaura
 * desde el sistema: para eso está el respaldo completo de la plataforma.
 */
class AcademyDataExport
{
    public function __construct(
        private readonly ReportFile $reports,
        private readonly FileStorage $storage,
        private readonly TenantSettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return string ruta del ZIP temporal
     */
    public function build(Tenant $tenant): string
    {
        $timezone = $this->settings->timezone();
        $workbook = $this->reports->xlsxFile($this->sheets($timezone));

        $path = tempnam(sys_get_temp_dir(), 'acad').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($workbook, 'datos.xlsx');
        $zip->addFromString('LEEME.txt', $this->readme($tenant, $timezone));

        // Sus archivos, ordenados por uso; el nombre lleva el id para que dos archivos iguales no se pisen
        $files = 0;
        foreach (File::query()->orderBy('purpose')->orderBy('created_at')->cursor() as $file) {
            if (! $this->storage->exists($file)) {
                continue;
            }
            $name = Str::limit(preg_replace('/[^\pL\pN._ -]+/u', '_', $file->original_name) ?: 'archivo', 80, '');
            $zip->addFromString("archivos/{$file->purpose}/{$file->id}-{$name}", (string) $this->storage->contents($file));
            $files++;
        }

        $zip->close();
        @unlink($workbook);

        $this->audit->record('tenant.data_exported', $tenant, null, ['files' => $files]);

        return $path;
    }

    /**
     * @return array<string, array{headers: list<string>, rows: list<list<string|int|float|null>>}>
     */
    private function sheets(string $timezone): array
    {
        $date = fn ($value) => ExcelExport::date($value, $timezone);
        $dateTime = fn ($value) => ExcelExport::dateTime($value, $timezone);
        $name = fn (?User $user) => $user ? trim("{$user->first_name} {$user->last_name}") : null;
        $limit = ExcelExport::MAX_ROWS * 4;

        $people = User::query()->with('roles')->orderBy('last_name')->orderBy('first_name')->limit($limit)->get();

        return [
            'Personas' => [
                'headers' => ['Nombres', 'Apellidos', 'Correo', 'Teléfono', 'Tipo de documento', 'N.º de documento', 'Roles', 'Estado', 'Registrado el', 'Último acceso'],
                'rows' => $people->map(fn (User $user) => [
                    $user->first_name, $user->last_name, $user->email, $user->phone,
                    $user->doc_number ? $user->doc_type : null, $user->doc_number,
                    $user->roles->pluck('name')->implode(', '),
                    $user->status === User::STATUS_ACTIVE ? 'Activa' : 'Inactiva',
                    $dateTime($user->created_at), $dateTime($user->last_login_at),
                ])->all(),
            ],
            'Cursos' => [
                'headers' => ['Curso', 'Estado', 'Categoría', 'Precio', 'Días de acceso', 'Creado el'],
                'rows' => Course::query()->orderBy('title')->get()->map(fn (Course $course) => [
                    $course->title, $course->status, $course->category, $course->price !== null ? (float) $course->price : null, $course->access_days, $date($course->created_at),
                ])->all(),
            ],
            'Matrículas' => [
                'headers' => ['Alumno', 'Correo', 'Curso', 'Estado', 'Origen', 'Matriculado el', 'Acceso hasta', 'Completado el'],
                'rows' => Enrollment::query()->with(['student', 'course'])->orderBy('enrolled_at')->limit($limit)->get()->map(fn (Enrollment $enrollment) => [
                    $name($enrollment->student), $enrollment->student?->email, $enrollment->course?->title, $enrollment->status, $enrollment->source,
                    $date($enrollment->enrolled_at), $enrollment->ends_at?->toDateString(), $date($enrollment->completed_at),
                ])->all(),
            ],
            'Órdenes' => [
                'headers' => ['N.º', 'Estado', 'Fecha', 'Comprador', 'Correo', 'Detalle', 'Moneda', 'Subtotal', 'Descuento', 'Total', 'Pagada el'],
                'rows' => Order::query()->with(['customer', 'company', 'currency', 'items'])->orderBy('number')->limit($limit)->get()->map(fn (Order $order) => [
                    $order->number, $order->state(), $dateTime($order->created_at),
                    $name($order->customer) ?? $order->company?->name, $order->customer?->email,
                    $order->package_title ?? $order->items->pluck('title')->implode(', '),
                    $order->currency?->code, (float) $order->subtotal_amount, (float) $order->discount_amount, (float) $order->total_amount, $dateTime($order->paid_at),
                ])->all(),
            ],
            'Pagos' => [
                'headers' => ['Orden', 'Importe', 'Medio', 'Pasarela', 'N.º de operación', 'Estado', 'Pagado el', 'Devuelto el'],
                'rows' => Payment::query()->with('order')->orderBy('paid_at')->limit($limit)->get()->map(fn (Payment $payment) => [
                    $payment->order?->number, (float) $payment->amount, $payment->method, $payment->gateway, $payment->reference, $payment->status,
                    $dateTime($payment->paid_at), $dateTime($payment->refunded_at),
                ])->all(),
            ],
            'Certificados' => [
                'headers' => ['Código', 'Alumno', 'Curso', 'Estado', 'Emitido el'],
                'rows' => Certificate::query()->orderBy('issued_at')->limit($limit)->get()->map(fn (Certificate $certificate) => [
                    $certificate->verification_code, $certificate->snapshot['student_name'] ?? null, $certificate->snapshot['course_title'] ?? null,
                    $certificate->status, $date($certificate->issued_at),
                ])->all(),
            ],
        ];
    }

    private function readme(Tenant $tenant, string $timezone): string
    {
        return implode("\n", [
            "Datos de {$tenant->name}",
            'Descargados el '.now($timezone)->format('d/m/Y H:i').' desde Configuración → Mis datos.',
            '',
            'datos.xlsx: una hoja por tema (personas, cursos, matrículas, órdenes, pagos y certificados).',
            'archivos/: los archivos que subió la academia, en una carpeta por uso (logo, portadas, materiales, entregas, firmas…).',
            '',
            'No incluye contraseñas ni las claves de las pasarelas de pago.',
            'Guárdalo en un lugar seguro: tiene datos personales de tus alumnos.',
        ])."\n";
    }
}
