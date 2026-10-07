<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Application\LeadsImporter;
use App\Shared\Import\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Importar prospectos desde una hoja.
 */
class LeadImportController extends Controller
{
    public function template(Request $request, LeadsImporter $importer): BinaryFileResponse
    {
        return $importer->by($request->user())->template();
    }

    /**
     * Revisa el archivo y dice qué pasaría con cada fila, sin guardar nada.
     */
    public function preview(Request $request, LeadsImporter $importer): JsonResponse
    {
        return $importer->by($request->user())->preview($request->validate(['file' => Spreadsheet::rules()])['file']);
    }

    public function store(Request $request, LeadsImporter $importer): JsonResponse
    {
        return $importer->by($request->user())->import($request->validate(['file' => Spreadsheet::rules()])['file']);
    }
}
