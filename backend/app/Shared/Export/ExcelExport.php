<?php

namespace App\Shared\Export;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Descarga de un listado, con los mismos filtros que la pantalla, en el
 * formato que pida la pantalla (?format=xlsx|pdf|csv|ods; Excel por
 * defecto). Las fechas salen en la hora de la organización. El diseño de
 * cada formato está en ReportFile.
 */
class ExcelExport
{
    /** Tope de filas por archivo: más que eso se pide acotar con los filtros */
    public const MAX_ROWS = 5000;

    /** Título del reporte dentro del archivo, según el nombre de la descarga */
    private const TITLES = ['ordenes' => 'Órdenes de compra', 'alumnos' => 'Alumnos', 'prospectos' => 'Prospectos'];

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  ya filtrada y ordenada
     * @param  list<string>  $headers
     * @param  callable(TModel): list<string|int|float|null>  $row
     * @param  string|null  $title  nombre del reporte en el archivo; si no, el del archivo
     */
    public static function download(string $name, Builder $query, array $headers, callable $row, ?string $title = null): BinaryFileResponse
    {
        $rows = (function () use ($query, $row) {
            foreach ($query->limit(self::MAX_ROWS)->get() as $record) {
                yield $row($record);
            }
        })();

        return self::respond($name, [$title ?? self::TITLES[$name] ?? ucfirst($name) => ['headers' => $headers, 'rows' => $rows]]);
    }

    /**
     * Uno o varios listados (hojas) en el formato pedido.
     *
     * @param  array<string, array{headers: list<string>, rows: iterable<list<string|int|float|null>>}>  $sheets  título => contenido
     */
    public static function respond(string $name, array $sheets): BinaryFileResponse
    {
        return app(ReportFile::class)->download($name, $sheets, ReportFile::requestedFormat());
    }

    /**
     * Un archivo de Excel ya armado (el libro de notas).
     */
    public static function send(string $name, string $path): BinaryFileResponse
    {
        return response()->download($path, "{$name}.xlsx")->deleteFileAfterSend();
    }

    public static function date(?CarbonInterface $value, string $timezone): ?string
    {
        return $value?->copy()->setTimezone($timezone)->format('Y-m-d');
    }

    public static function dateTime(?CarbonInterface $value, string $timezone): ?string
    {
        return $value?->copy()->setTimezone($timezone)->format('Y-m-d H:i');
    }
}
