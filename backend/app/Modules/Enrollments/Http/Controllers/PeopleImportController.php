<?php

namespace App\Modules\Enrollments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Enrollments\Application\PeopleImporter;
use App\Shared\Import\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Importar personas desde una hoja, con su matrícula y su grupo.
 */
class PeopleImportController extends Controller
{
    public function template(Request $request, PeopleImporter $importer): BinaryFileResponse
    {
        return $importer->by($request->user())->template();
    }

    /**
     * Revisa el archivo y dice qué pasaría con cada fila, sin guardar nada.
     */
    public function preview(Request $request, PeopleImporter $importer): JsonResponse
    {
        return $importer->by($request->user())->preview($request->validate(['file' => Spreadsheet::rules()])['file']);
    }

    public function store(Request $request, PeopleImporter $importer): JsonResponse
    {
        return $importer->by($request->user())->import($request->validate(['file' => Spreadsheet::rules()])['file']);
    }
}
