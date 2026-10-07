<?php

namespace App\Providers;

use App\Modules\Academic\Application\ActivityContent;
use App\Modules\B2b\Application\CancelLicensesOnOrderCancelled;
use App\Modules\B2b\Application\IssueLicensesOnOrderPaid;
use App\Modules\Certificates\Application\IssueCertificateOnCompletion;
use App\Modules\Courses\Application\CourseContentExtension;
use App\Modules\Enrollments\Domain\Events\EnrollmentCompleted;
use App\Modules\Sales\Application\Payments\ManualPaymentGateway;
use App\Modules\Sales\Application\Payments\PaymentGateway;
use App\Modules\Sales\Domain\Events\OrderCancelled;
use App\Modules\Sales\Domain\Events\OrderPaid;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);

        // Contenido que otros módulos aportan a los cursos al publicarlos
        $this->app->tag([ActivityContent::class], CourseContentExtension::TAG);

        // Cobro de las órdenes: manual mientras no haya proveedor de pagos (PD-001)
        $this->app->bind(PaymentGateway::class, ManualPaymentGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $context = $this->app->make(TenantContext::class);

        // Consola (migraciones, seeders, tinker) opera sin acotar por tenant;
        // las peticiones HTTP parten sin contexto hasta resolver el subdominio.
        if ($this->app->runningInConsole()) {
            $context->setSystem();
        }

        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use ($context) {
            $context->syncConnection($event->connection);
        });

        // Fuera de producción, cargar una relación fila por fila es un error: así las
        // pruebas detectan las consultas repetidas (N+1) antes de que lleguen al servidor
        Model::preventLazyLoading(! $this->app->isProduction());

        $this->configureTrustedProxies();

        // Tope general de la API: por usuario con sesión o, sin ella, por dirección
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('identity.api_rate_limit'))
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Reacciones entre módulos: quien emite el evento no conoce a quien lo escucha
        Event::listen(EnrollmentCompleted::class, IssueCertificateOnCompletion::class);
        Event::listen(OrderPaid::class, IssueLicensesOnOrderPaid::class);
        Event::listen(OrderCancelled::class, CancelLicensesOnOrderCancelled::class);

        // Cada módulo de dominio versiona sus propias migraciones
        $this->loadMigrationsFrom(glob(app_path('Modules/*/Database/Migrations')));
    }

    /**
     * Detrás de un balanceador, el HTTPS y la dirección del visitante llegan en
     * cabeceras que solo se aceptan de los proxies declarados (TRUSTED_PROXIES).
     */
    public function configureTrustedProxies(): void
    {
        $proxies = trim((string) config('tenancy.trusted_proxies'));

        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    }
}
