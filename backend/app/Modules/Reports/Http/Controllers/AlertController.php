<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Application\PendingSummary;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lo que espera a una persona del personal, en cifras, para la campana de
 * avisos y los pendientes de Inicio (ver PendingSummary).
 */
class AlertController extends Controller
{
    public function summary(Request $request, PendingSummary $pending): JsonResponse
    {
        return ApiResponse::success($pending->for($request->user()));
    }
}
