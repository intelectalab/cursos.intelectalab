<?php

namespace App\Shared\Import;

use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

/**
 * Lectura de las hojas que suben las academias y escritura de sus plantillas.
 * La primera fila de la hoja son los títulos de columna.
 */
class Spreadsheet
{
    public const EXTENSIONS = ['xlsx', 'csv'];

    public const MAX_KB = 2048;

    public const MAX_ROWS = 500;

    /**
     * Reglas de validación del archivo subido.
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return ['required', 'file', 'max:'.self::MAX_KB, 'extensions:'.implode(',', self::EXTENSIONS)];
    }

    /**
     * Filas de la primera hoja, cada una como título de columna => valor en
     * texto. Las filas vacías se saltan; `line` es el número de fila en la hoja.
     *
     * @param  array<string, string>  $columns  clave interna => título esperado
     * @return list<array{line: int, values: array<string, string>}>
     */
    public static function read(UploadedFile $file, array $columns): array
    {
        $reader = strtolower($file->getClientOriginalExtension()) === 'csv'
            ? new CsvReader(new CsvOptions(FIELD_DELIMITER: self::delimiter($file), ENCODING: self::encoding($file)))
            : new XlsxReader;

        try {
            $reader->open($file->getRealPath());
        } catch (Throwable) {
            throw new ApiException(ErrorCode::ValidationError, 'No se pudo abrir el archivo. Debe ser un Excel (.xlsx) o un CSV.', 422);
        }

        $rows = [];
        $map = null;
        $line = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = array_map(self::text(...), $row->toArray());

                    if (implode('', $cells) === '') {
                        continue;
                    }

                    if ($map === null) {
                        $map = self::mapHeaders($cells, $columns);

                        continue;
                    }

                    if (count($rows) >= self::MAX_ROWS) {
                        throw new ApiException(
                            ErrorCode::ValidationError,
                            'El archivo tiene más de '.self::MAX_ROWS.' filas. Divídelo en varios archivos.',
                            422
                        );
                    }

                    $values = [];
                    foreach ($map as $key => $index) {
                        $values[$key] = $cells[$index] ?? '';
                    }
                    $rows[] = ['line' => $line, 'values' => $values];
                }

                break; // solo la primera hoja
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ApiException(ErrorCode::ValidationError, 'El archivo está dañado o no tiene el formato de la plantilla.', 422);
        } finally {
            $reader->close();
        }

        if ($map === null) {
            throw new ApiException(ErrorCode::ValidationError, 'El archivo está vacío. Usa la plantilla y llena al menos una fila.', 422);
        }

        return $rows;
    }

    /**
     * Plantilla en Excel: títulos, una fila de ejemplo y una hoja de instrucciones.
     *
     * @param  array<string, array{label: string, required?: bool, hint: string, example: string}>  $columns
     * @return string ruta del archivo temporal generado
     */
    public static function template(array $columns, string $sheetName): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $bold = new Style(fontBold: true);

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(mb_substr($sheetName, 0, 31));
        $writer->addRow(Row::fromValuesWithStyle(array_column($columns, 'label'), $bold));
        $writer->addRow(Row::fromValues(array_column($columns, 'example')));

        $writer->addNewSheetAndMakeItCurrent()->setName('Instrucciones');
        $writer->addRow(Row::fromValuesWithStyle(['Columna', '¿Obligatoria?', 'Qué poner'], $bold));
        foreach ($columns as $column) {
            $writer->addRow(Row::fromValues([$column['label'], ($column['required'] ?? false) ? 'Sí' : 'No', $column['hint']]));
        }
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues(['Borra la fila de ejemplo antes de subir el archivo. No cambies los títulos de la primera fila. Máximo '.self::MAX_ROWS.' filas por archivo.']));
        $writer->close();

        return $path;
    }

    /**
     * Hoja de Excel con una fila de títulos y los datos recibidos.
     *
     * @param  list<string>  $headers
     * @param  iterable<list<string|int|float|null>>  $rows
     * @return string ruta del archivo temporal generado
     */
    public static function write(string $sheetName, array $headers, iterable $rows): string
    {
        return self::workbook([$sheetName => ['headers' => $headers, 'rows' => $rows]]);
    }

    /**
     * Libro de Excel con varias hojas, cada una con su fila de títulos.
     *
     * @param  array<string, array{headers: list<string>, rows: iterable<list<string|int|float|null>>}>  $sheets  nombre de la hoja => contenido
     * @return string ruta del archivo temporal generado
     */
    public static function workbook(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xls').'.xlsx';
        $bold = new Style(fontBold: true);

        $writer = new Writer;
        $writer->openToFile($path);

        $first = true;
        foreach ($sheets as $name => $sheet) {
            $current = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $current->setName(mb_substr($name, 0, 31));
            $first = false;

            $writer->addRow(Row::fromValuesWithStyle($sheet['headers'], $bold));
            foreach ($sheet['rows'] as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }
        $writer->close();

        return $path;
    }

    /**
     * Ubica cada columna esperada por su título, sin importar el orden, las
     * mayúsculas ni las tildes.
     *
     * @param  list<string>  $headers
     * @param  array<string, string>  $columns
     * @return array<string, int>
     */
    private static function mapHeaders(array $headers, array $columns): array
    {
        $found = array_flip(array_map(self::normalize(...), $headers));
        $map = [];
        $missing = [];

        foreach ($columns as $key => $label) {
            $index = $found[self::normalize($label)] ?? null;

            if ($index === null) {
                $missing[] = $label;
            } else {
                $map[$key] = $index;
            }
        }

        // Basta con que falte una columna para que el archivo no sea de esta plantilla
        if ($missing !== []) {
            throw new ApiException(
                ErrorCode::ValidationError,
                'El archivo no coincide con la plantilla. Faltan las columnas: '.implode(', ', $missing).'.',
                422
            );
        }

        return $map;
    }

    /**
     * Excel guarda los CSV con coma o con punto y coma según la configuración
     * regional del equipo: se usa el que más aparece en la fila de títulos.
     */
    private static function delimiter(UploadedFile $file): string
    {
        $firstLine = (string) fgets(fopen($file->getRealPath(), 'r'));

        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }

    /**
     * Un CSV guardado desde Excel sin la opción UTF-8 llega en Windows-1252.
     */
    private static function encoding(UploadedFile $file): string
    {
        return mb_check_encoding((string) file_get_contents($file->getRealPath()), 'UTF-8') ? 'UTF-8' : 'Windows-1252';
    }

    private static function normalize(string $header): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(Str::ascii($header))) ?? '';
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? 'SI' : 'NO',
            // Un número de documento escrito como número no debe salir con decimales
            is_float($value) && floor($value) === $value => (string) (int) $value,
            is_scalar($value) => trim((string) $value),
            default => '',
        };
    }
}
