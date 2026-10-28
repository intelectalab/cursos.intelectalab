<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Tenancy\Application\TenantAppearance;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración → Apariencia: los colores de la academia y su pantalla de acceso.
 */
class AppearanceController extends Controller
{
    private const HEX = 'regex:/^#[0-9a-fA-F]{6}$/';

    public function __construct(private readonly TenantAppearance $appearance) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->appearance->current(), [
            'background' => [
                'max_kb' => config('files.limits.image'),
                'extensions' => config('files.purposes.'.TenantAppearance::BACKGROUND_PURPOSE.'.extensions'),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'primary' => ['nullable', 'string', self::HEX, 'required_with:accent'],
            'accent' => ['nullable', 'string', self::HEX, 'required_with:primary'],
            'login_title' => ['nullable', 'string', 'max:80'],
            'login_text' => ['nullable', 'string', 'max:240'],
            // Pie del sitio público
            'footer' => ['nullable', 'array'],
            'footer.text' => ['nullable', 'string', 'max:240'],
            'footer.phone' => ['nullable', 'string', 'max:30'],
            'footer.whatsapp' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ]{6,20}$/'],
            'footer.social' => ['nullable', 'array:'.implode(',', TenantAppearance::SOCIAL)],
            'footer.social.*' => ['nullable', 'url:https', 'max:200'],
        ], [
            'footer.whatsapp.regex' => 'Escribe el número de WhatsApp con el código del país, por ejemplo +51 987 654 321.',
            'footer.social.*.url' => 'Pega el enlace completo de tu página, empezando con https://.',
            'primary.regex' => 'El color principal debe tener la forma #1f6fbf.',
            'accent.regex' => 'El color de acento debe tener la forma #0ea5e9.',
        ]);

        $result = $this->appearance->save($request->attributes->get('tenant'), $data);

        return ApiResponse::success($result['appearance'], ['adjusted' => $result['adjusted']]);
    }

    public function reset(Request $request): JsonResponse
    {
        return ApiResponse::success($this->appearance->reset($request->attributes->get('tenant')));
    }

    public function uploadBackground(Request $request, FileStorage $storage): JsonResponse
    {
        $request->validate(['file' => $storage->rules(TenantAppearance::BACKGROUND_PURPOSE)]);

        return ApiResponse::success([
            'login_background_url' => $this->appearance->replaceBackground($request->attributes->get('tenant'), $request->file('file')),
        ]);
    }

    public function removeBackground(Request $request): JsonResponse
    {
        $this->appearance->removeBackground($request->attributes->get('tenant'));

        return ApiResponse::success(['login_background_url' => null]);
    }
}
