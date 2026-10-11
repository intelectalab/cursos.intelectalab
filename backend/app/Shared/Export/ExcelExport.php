<?php

namespace App\Shared\Export;

use App\Shared\Import\Spreadsheet;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Descarga en Excel de un listado, con los mismos filtros que la pantalla.
 * Las fechas salen en la hora de la organización.
 */
class ExcelExport
{
    /** Tope de filas por archivo: más que eso se pide acotar con los filtros */
    public const MAX_ROWS = 5000;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  ya filtrada y ordenada
     * @param  list<string>  $headers
     * @param  callable(TModel): list<string|int|float|null>  $row
     */
    public static function download(string $name, Builder $query, array $headers, callable $row): BinaryFileResponse
    {
        $rows = (function () use ($query, $row) {
            foreach ($query->limit(self::MAX_ROWS)->get() as $record) {
                yield $row($record);
            }
        })();

        return self::send($name, Spreadsheet::write(mb_substr($name, 0, 31), $headers, $rows));
    }

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
