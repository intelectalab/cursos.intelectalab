<?php

namespace App\Shared\Export;

use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Crm\Domain\Models\Company;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Files\Domain\Models\File;
use App\Modules\Identity\Domain\Models\Group;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Package;
use App\Modules\Tenancy\Application\TenantAppearance;
use App\Modules\Tenancy\Application\TenantLogoService;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Modules\Tenancy\Domain\BrandColor;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Dompdf\Dompdf;
use Dompdf\Options as PdfOptions;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Arma el archivo de una descarga con la identidad de la academia:
 *
 * - Excel y ODS: nombre de la academia, título, fecha y filtros arriba;
 *   encabezados con su color, filas alternadas, importes con formato de
 *   dinero, fila de totales, columnas a su medida, encabezado fijo y filtros.
 * - PDF: lo mismo en una hoja horizontal con su logo (hasta 1 000 filas).
 * - CSV: solo los datos, para llevarlos a otro sistema.
 */
class ReportFile
{
    public const FORMATS = ['xlsx', 'pdf', 'csv', 'ods'];

    /** Más filas que esto en un PDF ya no se leen: se pide Excel o CSV */
    public const PDF_MAX_ROWS = 1000;

    /** Columnas con importes: llevan formato de dinero y se suman al final */
    private const MONEY = '/total|subtotal|descuento|cobrado|por cobrar|importe|monto|ingreso|precio|pagado|venta/i';

    /** Filtros de la pantalla que se escriben en el archivo, con su nombre en español */
    private const FILTER_LABELS = [
        'search' => 'Búsqueda', 'status' => 'Estado', 'state' => 'Situación', 'filter' => 'Situación', 'sale' => 'Origen',
        'course_id' => 'Curso', 'package_id' => 'Paquete', 'group_id' => 'Grupo', 'company_id' => 'Empresa', 'role_id' => 'Rol',
        'teacher_id' => 'Docente', 'seller_id' => 'Vendió', 'method' => 'Medio de pago', 'debt' => 'Con cuotas vencidas',
        'category' => 'Categoría', 'type' => 'Tipo', 'from' => 'Desde', 'to' => 'Hasta', 'expires_from' => 'Vence desde', 'expires_to' => 'Vence hasta',
        'period' => 'Periodo', 'compare' => 'Comparar',
    ];

    /** Valores de los filtros en palabras (los códigos que usan las pantallas) */
    private const VALUE_LABELS = [
        'PENDING' => 'Pendiente', 'PARTIAL' => 'En cuotas', 'PAID' => 'Pagada', 'CANCELLED' => 'Cancelada', 'EXPIRED' => 'Vencida', 'REFUNDED' => 'Reembolsada',
        'ACTIVE' => 'Activa', 'INACTIVE' => 'Inactiva', 'ENROLLED' => 'Con cursos', 'NOT_ENROLLED' => 'Sin cursos', 'COMPLETED' => 'Completada',
        'ASSISTED' => 'Venta asistida', 'ONLINE' => 'Compra del alumno',
        'CASH' => 'Efectivo', 'TRANSFER' => 'Transferencia', 'YAPE' => 'Yape', 'CARD' => 'Tarjeta', 'WALLET' => 'Saldo en la pasarela', 'PAYPAL' => 'PayPal', 'OTHER' => 'Otro',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly FileStorage $storage,
    ) {}

    public static function requestedFormat(): string
    {
        $format = strtolower((string) request()->query('format', 'xlsx'));

        if (! in_array($format, self::FORMATS, true)) {
            throw new ApiException(ErrorCode::ValidationError, 'Formato de descarga no válido.', 422, ['format' => ['Elige Excel, PDF, CSV u ODS.']]);
        }

        return $format;
    }

    /**
     * @param  array<string, array{headers: list<string>, rows: iterable<list<string|int|float|null>>}>  $sheets
     */
    public function download(string $name, array $sheets, string $format): BinaryFileResponse
    {
        // Los datos se leen una sola vez: con un generador no se puede volver atrás
        $sheets = array_map(fn (array $sheet) => ['headers' => $sheet['headers'], 'rows' => is_array($sheet['rows']) ? array_values($sheet['rows']) : iterator_to_array($sheet['rows'], false)], $sheets);

        $path = match ($format) {
            'pdf' => $this->pdf($sheets),
            'csv' => $this->csv($sheets),
            default => $this->spreadsheet($sheets, $format),
        };

        $extension = $format;

        return response()->download($path, "{$name}.{$extension}")->deleteFileAfterSend();
    }

    /**
     * @param  array<string, array{headers: list<string>, rows: list<list<string|int|float|null>>}>  $sheets
     */
    private function spreadsheet(array $sheets, string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rep').'.'.$format;
        $writer = $format === 'ods' ? new OdsWriter : new XlsxWriter;
        $writer->openToFile($path);

        $brand = ltrim($this->brand(), '#');
        $light = ltrim(self::tint($this->brand(), 0.93), '#');
        $line = new Border(new BorderPart(BorderName::BOTTOM, 'D9DEE7', BorderWidth::THIN));
        $meta = $this->meta();

        $first = true;
        foreach ($sheets as $title => $sheet) {
            $current = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $current->setName(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $title), 0, 31));
            $first = false;

            $headers = $sheet['headers'];
            $money = $this->moneyColumns($headers, $sheet['rows']);
            $columns = count($headers);

            // Cabecera del reporte: academia, título, fecha y filtros
            $writer->addRow(Row::fromValuesWithStyle([$meta['organization']], new Style(fontBold: true, fontSize: 14, fontColor: $brand)));
            $writer->addRow(Row::fromValuesWithStyle([$title], new Style(fontBold: true, fontSize: 12)));
            $writer->addRow(Row::fromValuesWithStyle(['Generado el '.$meta['generated'].($meta['filters'] ? ' · '.$meta['filters'] : '')], new Style(fontSize: 9, fontColor: '64748B')));
            $writer->addRow(Row::fromValues(['']));
            $headerRow = 5;

            $writer->addRow(Row::fromValuesWithStyle($headers, new Style(fontBold: true, fontColor: 'FFFFFF', backgroundColor: $brand, shouldWrapText: true)));

            $totals = array_fill(0, $columns, 0.0);
            foreach ($sheet['rows'] as $index => $row) {
                $background = $index % 2 === 1 ? $light : null;
                $styles = [];
                foreach (array_keys($headers) as $column) {
                    $isMoney = in_array($column, $money, true);
                    $styles[$column] = new Style(
                        backgroundColor: $background,
                        border: $line,
                        format: $isMoney ? '#,##0.00' : null,
                        cellAlignment: $isMoney ? CellAlignment::RIGHT : null,
                    );
                    if ($isMoney && is_numeric($row[$column] ?? null)) {
                        $totals[$column] += (float) $row[$column];
                    }
                }
                $writer->addRow(Row::fromValuesWithStyles(array_slice(array_pad($row, $columns, null), 0, $columns), $styles));
            }

            $lastRow = $headerRow + count($sheet['rows']);
            if ($money !== [] && $sheet['rows'] !== []) {
                $values = array_map(fn (int $column) => in_array($column, $money, true) ? round($totals[$column], 2) : null, array_keys($headers));
                $values[0] = in_array(0, $money, true) ? $values[0] : 'Total';
                $total = new Style(fontBold: true, backgroundColor: ltrim(self::tint($this->brand(), 0.82), '#'), format: '#,##0.00');
                $writer->addRow(Row::fromValuesWithStyle($values, $total));
            }

            foreach ($this->widths($headers, $sheet['rows']) as $column => $width) {
                $current->setColumnWidth($width, $column + 1);
            }

            if ($writer instanceof XlsxWriter) {
                // Encabezado fijo al bajar y filtros de Excel en los encabezados
                $current->setSheetView(new SheetView(freezeRow: $headerRow + 1));
                if ($sheet['rows'] !== []) {
                    $current->setAutoFilter(new AutoFilter(0, $headerRow, max(0, $columns - 1), $lastRow));
                }
            }
        }

        $writer->close();

        return $path;
    }

    /**
     * @param  array<string, array{headers: list<string>, rows: list<list<string|int|float|null>>}>  $sheets
     */
    private function csv(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rep').'.csv';
        $writer = new CsvWriter;
        $writer->openToFile($path);

        foreach ($sheets as $title => $sheet) {
            // Varias hojas en un CSV: una tras otra, con su título
            if (count($sheets) > 1) {
                $writer->addRow(Row::fromValues([$title]));
            }
            $writer->addRow(Row::fromValues($sheet['headers']));
            foreach ($sheet['rows'] as $row) {
                $writer->addRow(Row::fromValues($row));
            }
            if (count($sheets) > 1) {
                $writer->addRow(Row::fromValues(['']));
            }
        }

        $writer->close();

        return $path;
    }

    /**
     * @param  array<string, array{headers: list<string>, rows: list<list<string|int|float|null>>}>  $sheets
     */
    private function pdf(array $sheets): string
    {
        $count = array_sum(array_map(fn (array $sheet) => count($sheet['rows']), $sheets));
        if ($count > self::PDF_MAX_ROWS) {
            throw new ApiException(
                ErrorCode::ValidationError,
                'Son '.number_format($count, 0, '.', ' ').' filas: un PDF de más de '.number_format(self::PDF_MAX_ROWS, 0, '.', ' ').' no se puede leer bien. Descárgalo en Excel o CSV, o acota los filtros.',
                422
            );
        }

        $sections = [];
        foreach ($sheets as $title => $sheet) {
            $money = $this->moneyColumns($sheet['headers'], $sheet['rows']);
            $totals = [];
            foreach ($money as $column) {
                $totals[$column] = round(array_sum(array_map(fn (array $row) => is_numeric($row[$column] ?? null) ? (float) $row[$column] : 0, $sheet['rows'])), 2);
            }
            $sections[] = ['title' => $title, 'headers' => $sheet['headers'], 'rows' => $sheet['rows'], 'money' => $money, 'totals' => $totals];
        }

        $html = view('exports.report', $this->meta() + [
            'sections' => $sections,
            'brand' => $this->brand(),
            'light' => self::tint($this->brand(), 0.93),
            'logo' => $this->logo(),
        ])->render();

        $options = new PdfOptions;
        // Sin recursos externos: el logo va incrustado
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('Helvetica');
        $options->setFontCache(storage_path('framework/cache'));
        $options->setFontDir(storage_path('framework/cache'));
        $options->setTempDir(storage_path('framework/cache'));

        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        $path = tempnam(sys_get_temp_dir(), 'rep').'.pdf';
        file_put_contents($path, (string) $pdf->output());

        return $path;
    }

    /**
     * Columnas de importes: el título lo dice y los valores son números.
     *
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     * @return list<int>
     */
    private function moneyColumns(array $headers, array $rows): array
    {
        $columns = [];
        foreach ($headers as $index => $header) {
            if (preg_match(self::MONEY, $header) !== 1) {
                continue;
            }
            $values = array_filter(array_column($rows, $index), fn ($value) => $value !== null && $value !== '');
            if ($values !== [] && collect($values)->every(fn ($value) => is_int($value) || is_float($value))) {
                $columns[] = $index;
            }
        }

        return $columns;
    }

    /**
     * Ancho de cada columna según su contenido, entre 8 y 60 caracteres.
     *
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     * @return array<int, float>
     */
    private function widths(array $headers, array $rows): array
    {
        $widths = [];
        foreach ($headers as $column => $header) {
            $longest = mb_strlen($header);
            foreach (array_slice($rows, 0, 500) as $row) {
                $longest = max($longest, mb_strlen((string) ($row[$column] ?? '')));
            }
            $widths[$column] = (float) min(60, max(8, $longest + 2));
        }

        return $widths;
    }

    /**
     * Academia, fecha y filtros usados, para la cabecera del archivo.
     *
     * @return array{organization: string, generated: string, filters: string}
     */
    private function meta(): array
    {
        $tenant = $this->context->hasTenant() ? Tenant::query()->find($this->context->tenantId()) : null;
        $timezone = $tenant ? app(TenantSettingsService::class)->timezone() : 'America/Lima';

        return [
            'organization' => $tenant?->name ?? 'IntelectaLab',
            'generated' => now($timezone)->format('d/m/Y H:i'),
            'filters' => $this->filters(),
        ];
    }

    private function filters(): string
    {
        $names = [
            'course_id' => fn (string $id) => Course::query()->whereKey($id)->value('title'),
            'package_id' => fn (string $id) => Package::query()->whereKey($id)->value('title'),
            'group_id' => fn (string $id) => Group::query()->whereKey($id)->value('name'),
            'company_id' => fn (string $id) => Company::query()->whereKey($id)->value('name'),
            'role_id' => fn (string $id) => Role::query()->whereKey($id)->value('name'),
            'teacher_id' => fn (string $id) => User::query()->whereKey($id)->get(['first_name', 'last_name'])->map(fn (User $user) => trim("{$user->first_name} {$user->last_name}"))->first(),
            'seller_id' => fn (string $id) => User::query()->whereKey($id)->get(['first_name', 'last_name'])->map(fn (User $user) => trim("{$user->first_name} {$user->last_name}"))->first(),
        ];

        return collect(request()->query())
            ->only(array_keys(self::FILTER_LABELS))
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->map(function (string $value, string $key) use ($names) {
                $shown = isset($names[$key]) && preg_match('/^[0-9a-f-]{36}$/i', $value) === 1 ? (($names[$key])($value) ?? $value) : $value;

                return self::FILTER_LABELS[$key].': '.($key === 'debt' ? 'sí' : (self::VALUE_LABELS[$shown] ?? $shown));
            })
            ->implode(' · ');
    }

    private function brand(): string
    {
        return $this->context->hasTenant() ? app(TenantAppearance::class)->primaryColor() : BrandColor::DEFAULT_PRIMARY;
    }

    /**
     * El logo de la academia incrustado (el PDF no busca nada afuera).
     */
    private function logo(): ?string
    {
        if (! $this->context->hasTenant()) {
            return null;
        }

        $file = File::query()->where('purpose', TenantLogoService::PURPOSE)->latest('id')->first();
        if (! $file || ! $this->storage->exists($file) || ! in_array($file->mime_type, ['image/png', 'image/jpeg'], true)) {
            return null;
        }

        return 'data:'.$file->mime_type.';base64,'.base64_encode((string) $this->storage->contents($file));
    }

    /**
     * El color mezclado con blanco: 0.93 = casi blanco con un toque del color.
     */
    private static function tint(string $hex, float $white): string
    {
        $channels = array_map(fn (int $start) => hexdec(substr($hex, $start, 2)), [1, 3, 5]);

        return sprintf('#%02x%02x%02x', ...array_map(fn (int $channel) => (int) round($channel + (255 - $channel) * $white), $channels));
    }
}
