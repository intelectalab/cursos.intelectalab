<?php

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Application\QuestionsImporter;
use App\Modules\Academic\Domain\Models\QuestionBank;
use App\Shared\Import\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Importar preguntas a un banco desde una hoja.
 */
class QuestionImportController extends Controller
{
    public function template(Request $request, QuestionBank $bank, QuestionsImporter $importer): BinaryFileResponse
    {
        return $importer->into($bank)->template();
    }

    /**
     * Revisa el archivo y dice qué pasaría con cada fila, sin guardar nada.
     */
    public function preview(Request $request, QuestionBank $bank, QuestionsImporter $importer): JsonResponse
    {
        return $importer->into($bank)->preview($request->validate(['file' => Spreadsheet::rules()])['file']);
    }

    public function store(Request $request, QuestionBank $bank, QuestionsImporter $importer): JsonResponse
    {
        return $importer->into($bank)->import($request->validate(['file' => Spreadsheet::rules()])['file']);
    }
}
