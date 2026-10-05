<?php

namespace App\Modules\Certificates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Certificates\Application\CertificateService;
use App\Modules\Certificates\Http\CertificatePresenter;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Verificación pública de un certificado por su código o QR (TC-CERT-002).
 * No requiere sesión y solo muestra lo que el propio certificado dice.
 */
class VerificationController extends Controller
{
    public function show(Request $request, string $code, CertificateService $certificates): JsonResponse
    {
        $certificate = $certificates->verify($code, $request->ip(), $request->userAgent());

        if (! $certificate) {
            throw new ApiException(ErrorCode::NotFound, 'No existe un certificado con ese código.', 404);
        }

        return ApiResponse::success(CertificatePresenter::verification($certificate));
    }
}
