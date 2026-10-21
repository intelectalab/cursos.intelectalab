<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\EmailTemplateEditor;
use App\Modules\Tenancy\Domain\EmailCatalog;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Correos automáticos de una academia: textos propios, apagar los que no
 * le sirvan, vista previa y prueba.
 */
class EmailTemplateController extends Controller
{
    private const SCOPE = EmailCatalog::SCOPE_ACADEMY;

    public function __construct(private readonly EmailTemplateEditor $editor) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->editor->list(self::SCOPE, forAcademy: true));
    }

    public function show(string $key): JsonResponse
    {
        return ApiResponse::success($this->editor->show($key, self::SCOPE, forAcademy: true));
    }

    public function update(Request $request, string $key): JsonResponse
    {
        return ApiResponse::success($this->editor->save($key, self::SCOPE, true, self::texts($request, withEnabled: true), $request->user()->id));
    }

    public function destroy(string $key): JsonResponse
    {
        return ApiResponse::success($this->editor->restore($key, self::SCOPE, forAcademy: true));
    }

    public function preview(Request $request, string $key): JsonResponse
    {
        $mail = $this->editor->example($key, self::SCOPE, self::texts($request), $request->attributes->get('tenant'));

        return ApiResponse::success(['html' => $mail->render()]);
    }

    public function test(Request $request, string $key): JsonResponse
    {
        $mail = $this->editor->example($key, self::SCOPE, self::texts($request), $request->attributes->get('tenant'), test: true);

        return ApiResponse::success($this->editor->sendTest($mail, $request->user()->email));
    }

    /**
     * @return array{subject: string, body: string, button: ?string, enabled?: bool}
     */
    public static function texts(Request $request, bool $withEnabled = false): array
    {
        return $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'button' => ['nullable', 'string', 'max:60'],
        ] + ($withEnabled ? ['enabled' => ['required', 'boolean']] : [])) + ['button' => null];
    }
}
