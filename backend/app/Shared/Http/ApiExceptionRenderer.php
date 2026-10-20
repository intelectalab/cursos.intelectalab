<?php

namespace App\Shared\Http;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Toda respuesta de error de la API: un código estable y un mensaje en
 * español, escrito para quien usa el sistema (de «tú», sin jerga).
 */
class ApiExceptionRenderer
{
    /** SQLSTATE de PostgreSQL: se borra o cambia algo que otro registro usa */
    private const FOREIGN_KEY_VIOLATION = '23503';

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
            return ApiResponse::error(ErrorCode::Unauthenticated, 'Tu sesión terminó o no has iniciado sesión. Vuelve a entrar.', 401);
        }

        // Dos personas guardan lo mismo a la vez, o se borra algo que otro registro usa
        if ($e instanceof UniqueConstraintViolationException) {
            return ApiResponse::error(ErrorCode::RecordExists, 'Ese registro ya existe. Revisa la lista: quizá alguien lo creó hace un momento.', 409);
        }

        if ($e instanceof QueryException && $e->getCode() === self::FOREIGN_KEY_VIOLATION) {
            return ApiResponse::error(ErrorCode::RecordInUse, 'No se puede quitar porque otros datos lo están usando.', 409);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            [$code, $message] = match ($status) {
                400 => [ErrorCode::BadRequest, 'El pedido llegó incompleto o con un formato que el sistema no entiende. Recarga la página e inténtalo de nuevo.'],
                403 => [ErrorCode::Forbidden, 'No tienes permiso para hacer esto.'],
                404 => [ErrorCode::NotFound, 'El recurso solicitado no existe.'],
                405 => [ErrorCode::MethodNotAllowed, 'Esta acción no está disponible aquí.'],
                409 => [ErrorCode::Conflict, 'No se pudo hacer porque los datos cambiaron mientras tanto. Recarga la página e inténtalo de nuevo.'],
                413 => [ErrorCode::HttpError, 'Lo que intentas enviar es demasiado grande para el servidor. Reduce el tamaño del archivo e inténtalo de nuevo.'],
                419 => [ErrorCode::HttpError, 'La página estuvo abierta demasiado tiempo. Recárgala e inténtalo de nuevo.'],
                429 => [ErrorCode::TooManyRequests, 'Hiciste demasiados pedidos seguidos. Espera un momento y vuelve a intentarlo.'],
                503 => [ErrorCode::HttpError, 'El sistema está en mantenimiento. Vuelve a intentarlo en unos minutos.'],
                default => [ErrorCode::HttpError, "No se pudo procesar la solicitud (error {$status})."],
            };

            // Un abort() con texto propio en español manda sobre el genérico
            return ApiResponse::error($code, self::ownMessage($e) ?? $message, $status);
        }

        return ApiResponse::error(
            ErrorCode::InternalError,
            config('app.debug') ? $e->getMessage() : 'Ocurrió un error inesperado en el sistema. Inténtalo de nuevo; si se repite, avisa a soporte e indica el código de este mensaje.',
            500
        );
    }

    /**
     * El mensaje que escribió el código (abort(422, '…')). Los textos que pone
     * el propio framework, en inglés, no cuentan.
     */
    private static function ownMessage(Throwable $e): ?string
    {
        // abort() lanza exactamente estas clases; las demás las arma el framework con su propio texto
        if (! in_array($e::class, [HttpException::class, NotFoundHttpException::class], true)) {
            return null;
        }

        $message = trim($e->getMessage());

        if ($message === '' || preg_match('/^(The route |No query results|Not Found|Bad Request|Forbidden|Unauthorized|This action is unauthorized|Too Many|Method Not Allowed|The \w+ method is not supported|Service Unavailable|CSRF token|Page Expired|Request Entity|Conflict|Unprocessable)/i', $message)) {
            return null;
        }

        return $message;
    }
}
