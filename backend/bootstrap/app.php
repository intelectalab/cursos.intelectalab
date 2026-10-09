<?php

use App\Modules\Billing\Http\Middleware\EnforcePlanAccess;
use App\Modules\Identity\Http\Middleware\EnsureTenantUser;
use App\Modules\Identity\Http\Middleware\RequirePermission;
use App\Modules\Platform\Http\Middleware\EnsurePlatformAdmin;
use App\Modules\Tenancy\Http\Middleware\RequireCentral;
use App\Modules\Tenancy\Http\Middleware\RequireTenant;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use App\Shared\Http\ApiExceptionRenderer;
use App\Shared\Http\Middleware\AssignRequestId;
use App\Shared\Http\Middleware\Idempotency;
use App\Shared\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands(glob(__DIR__.'/../app/Modules/*/Console'))
    ->withMiddleware(function (Middleware $middleware): void {
        // El tenant se resuelve antes de autenticar
        $middleware->api(prepend: [
            AssignRequestId::class,
            ResolveTenant::class,
        ]);
        // Con el plan vencido y sin días de gracia, la organización queda en solo lectura
        $middleware->api(append: [SecurityHeaders::class, EnforcePlanAccess::class]);
        // Tope general por usuario o dirección (AppServiceProvider); las rutas sensibles tienen el suyo
        $middleware->throttleApi();

        $middleware->alias([
            'tenant' => RequireTenant::class,
            'central' => RequireCentral::class,
            'permission' => RequirePermission::class,
            'tenant.user' => EnsureTenantUser::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            'idempotent' => Idempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiExceptionRenderer::render($e);
            }
        });
    })->create();
