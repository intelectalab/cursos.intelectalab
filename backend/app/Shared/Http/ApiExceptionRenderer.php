<?php

namespace App\Shared\Http;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiExceptionRenderer
{
    public static function render(Throwable $e): JsonResponse
    {
        if ($e instanceof ApiException) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->status, $e->details);
        }

        if ($e instanceof ValidationException) {
            return ApiResponse::error(
                ErrorCode::ValidationError,
                'Los datos enviados no son válidos.',
                $e->status,
                $e->errors()
            );
        }

        if ($e instanceof AuthenticationException) {
            return ApiResponse::error(ErrorCode::Unauthenticated, 'No autenticado.', 401);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            [$code, $message] = match ($status) {
                403 => [ErrorCode::Forbidden, 'No tiene autorización para realizar esta acción.'],
                404 => [ErrorCode::NotFound, 'El recurso solicitado no existe.'],
                405 => [ErrorCode::MethodNotAllowed, 'Método no permitido.'],
                429 => [ErrorCode::TooManyRequests, 'Demasiadas solicitudes. Intente nuevamente más tarde.'],
                413 => [ErrorCode::HttpError, 'Lo que intentas enviar es demasiado grande para el servidor. Reduce el tamaño del archivo e inténtalo de nuevo.'],
                419 => [ErrorCode::HttpError, 'La página estuvo abierta demasiado tiempo. Recárgala e inténtalo de nuevo.'],
                503 => [ErrorCode::HttpError, 'El sistema está en mantenimiento. Vuelve a intentarlo en unos minutos.'],
                default => [ErrorCode::HttpError, "No se pudo procesar la solicitud (error {$status})."],
            };

            return ApiResponse::error($code, $message, $status);
        }

        return ApiResponse::error(
            ErrorCode::InternalError,
            config('app.debug') ? $e->getMessage() : 'Ocurrió un error inesperado en el sistema. Inténtalo de nuevo; si se repite, avisa a soporte e indica el código de este mensaje.',
            500
        );
    }
}
