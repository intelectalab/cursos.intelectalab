<?php

namespace App\Shared\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

/**
 * Formato estándar de respuesta (Maestro §7.1):
 * éxito = data + meta; error = code + message + details + trace_id.
 */
class ApiResponse
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function success(mixed $data, ?array $meta = null, int $status = 200): JsonResponse
    {
        $body = ['data' => $data];

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    /**
     * @param  callable(mixed): mixed  $map
     */
    public static function paginated(LengthAwarePaginator $paginator, callable $map, array $meta = []): JsonResponse
    {
        return self::success(
            collect($paginator->items())->map($map)->values(),
            [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ] + $meta
        );
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    public static function error(ErrorCode $code, string $message, int $status, ?array $details = null): JsonResponse
    {
        return response()->json([
            'code' => $code->value,
            'message' => $message,
            'details' => $details,
            'trace_id' => Context::get('request_id'),
        ], $status);
    }
}
