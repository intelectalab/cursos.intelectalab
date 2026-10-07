<?php

namespace App\Shared\Import;

use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Importación desde una hoja de cálculo en dos pasos: primero se revisa el
 * archivo y se muestra qué pasaría con cada fila; después, con el mismo
 * archivo, se guarda. Las filas con error no se guardan y no frenan a las demás.
 */
abstract class Importer
{
    public const STATUS_OK = 'OK';

    public const STATUS_SKIP = 'SKIP';

    public const STATUS_ERROR = 'ERROR';

    /**
     * Columnas de la plantilla.
     *
     * @return array<string, array{label: string, required?: bool, hint: string, example: string}>
     */
    abstract public function columns(): array;

    /**
     * Nombre de la hoja y del archivo de la plantilla.
     */
    abstract public function name(): string;

    /**
     * Guarda cada fila válida y devuelve qué pasó con cada una. Una fila que
     * falla no debe dejar la transacción rota: se guarda dentro de su propio
     * `DB::transaction()`.
     *
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @return list<array{line: int, status: string, label: string, messages: list<string>}>
     */
    abstract protected function process(array $rows): array;

    public function template(): BinaryFileResponse
    {
        return response()
            ->download(Spreadsheet::template($this->columns(), $this->name()), 'plantilla-'.str($this->name())->slug().'.xlsx')
            ->deleteFileAfterSend();
    }

    /**
     * Qué pasaría al importar el archivo, sin guardar nada.
     */
    public function preview(UploadedFile $file): JsonResponse
    {
        // Se ejecuta de verdad y se deshace: así la revisión ve lo mismo que verá la importación
        DB::beginTransaction();

        try {
            $results = $this->process($this->rows($file));
        } finally {
            DB::rollBack();
        }

        return $this->respond($results);
    }

    public function import(UploadedFile $file): JsonResponse
    {
        $results = DB::transaction(fn () => $this->process($this->rows($file)));

        return $this->respond($results, 201);
    }

    /**
     * @return list<array{line: int, values: array<string, string>}>
     */
    private function rows(UploadedFile $file): array
    {
        return Spreadsheet::read($file, array_map(fn (array $column) => $column['label'], $this->columns()));
    }

    /**
     * @param  list<array{line: int, status: string, label: string, messages: list<string>}>  $results
     */
    private function respond(array $results, int $status = 200): JsonResponse
    {
        $count = fn (string $state) => count(array_filter($results, fn (array $row) => $row['status'] === $state));

        return ApiResponse::success($results, [
            'total' => count($results),
            'ok' => $count(self::STATUS_OK),
            'skipped' => $count(self::STATUS_SKIP),
            'errors' => $count(self::STATUS_ERROR),
        ], $status);
    }

    /**
     * @param  list<string>  $messages
     * @return array{line: int, status: string, label: string, messages: list<string>}
     */
    protected function result(int $line, string $status, string $label, array $messages = []): array
    {
        return ['line' => $line, 'status' => $status, 'label' => $label, 'messages' => $messages];
    }
}
