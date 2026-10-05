<?php

namespace App\Modules\Certificates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Certificates\Application\CertificatePdf;
use App\Modules\Certificates\Application\CertificateService;
use App\Modules\Certificates\Domain\Models\Certificate;
use App\Modules\Certificates\Http\CertificatePresenter;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Mis certificados" del alumno (US-CERT-001). Solo ve los vigentes:
 * uno anulado deja de existir para él.
 */
class MyCertificateController extends Controller
{
    public function index(Request $request, CertificateService $certificates): JsonResponse
    {
        // Emite los que le correspondan por cursos que completó antes
        $certificates->syncFor($request->user());

        $own = $this->own($request)->orderByDesc('issued_at')->get();

        return ApiResponse::success($own->map(fn (Certificate $certificate) => CertificatePresenter::summary($certificate))->values());
    }

    public function pdf(Request $request, string $certificate, CertificatePdf $pdf): Response
    {
        return CertificateController::download($request, $this->own($request)->findOrFail($certificate), $pdf);
    }

    private function own(Request $request)
    {
        return Certificate::query()
            ->where('student_id', $request->user()->id)
            ->where('status', Certificate::STATUS_ISSUED);
    }
}
