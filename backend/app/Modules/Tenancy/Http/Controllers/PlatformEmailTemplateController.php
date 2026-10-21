<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\EmailTemplateEditor;
use App\Modules\Tenancy\Domain\EmailCatalog;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Correos en la consola de la plataforma: los suyos (pagos de plan) y el
 * texto base de los correos de las academias. `scope` elige cuáles.
 */
class PlatformEmailTemplateController extends Controller
{
    public function __construct(private readonly EmailTemplateEditor $editor) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->editor->list($this->scope($request), forAcademy: false));
    }

    public function show(Request $request, string $key): JsonResponse
    {
        return ApiResponse::success($this->editor->show($key, $this->scope($request), forAcademy: false));
    }

    public function update(Request $request, string $key): JsonResponse
    {
        return ApiResponse::success($this->editor->save($key, $this->scope($request), false, EmailTemplateController::texts($request, withEnabled: true), $request->user()->email));
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        return ApiResponse::success($this->editor->restore($key, $this->scope($request), forAcademy: false));
    }

    public function preview(Request $request, string $key): JsonResponse
    {
        $mail = $this->editor->example($key, $this->scope($request), EmailTemplateController::texts($request), null);

        return ApiResponse::success(['html' => $mail->render()]);
    }

    public function test(Request $request, string $key): JsonResponse
    {
        $mail = $this->editor->example($key, $this->scope($request), EmailTemplateController::texts($request), null, test: true);

        return ApiResponse::success($this->editor->sendTest($mail, $request->user()->email));
    }

    private function scope(Request $request): string
    {
        return $request->validate(['scope' => ['required', 'in:'.EmailCatalog::SCOPE_PLATFORM.','.EmailCatalog::SCOPE_ACADEMY]])['scope'];
    }
}
