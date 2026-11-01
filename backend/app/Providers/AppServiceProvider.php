<?php

namespace App\Providers;

use App\Modules\Academic\Application\ActivityContent;
use App\Modules\Academic\Domain\Events\GradeRecorded;
use App\Modules\B2b\Application\CancelLicensesOnOrderCancelled;
use App\Modules\B2b\Application\IssueLicensesOnOrderPaid;
use App\Modules\Billing\Application\PlanQuota;
use App\Modules\Billing\Application\PlatformGateways;
use App\Modules\Billing\Application\StartTrialOnProvision;
use App\Modules\Certificates\Application\IssueCertificateOnCompletion;
use App\Modules\Certificates\Application\IssueCertificateOnGrade;
use App\Modules\Courses\Application\CourseContentExtension;
use App\Modules\Enrollments\Application\EnrollmentQuota;
use App\Modules\Enrollments\Domain\Events\EnrollmentCompleted;
use App\Modules\Sales\Application\Exchange\ExchangeRateService;
use App\Modules\Sales\Application\Exchange\ExchangeRateSource;
use App\Modules\Sales\Application\Exchange\SunatExchangeRateSource;
use App\Modules\Sales\Application\Payments\ManualPaymentGateway;
use App\Modules\Sales\Application\Payments\Online\MercadoPagoGateway;
use App\Modules\Sales\Application\Payments\Online\OnlineGateway;
use App\Modules\Sales\Application\Payments\Online\OnlinePaymentService;
use App\Modules\Sales\Application\Payments\Online\PayPalGateway;
use App\Modules\Sales\Application\Payments\PaymentGateway;
use App\Modules\Sales\Domain\Events\OrderCancelled;
use App\Modules\Sales\Domain\Events\OrderPaid;
use App\Modules\Tenancy\Domain\Events\TenantProvisioned;
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

        // Pasarelas de cobro en línea: cada organización conecta su cuenta en las que quiera
        $this->app->tag([MercadoPagoGateway::class, PayPalGateway::class], OnlineGateway::TAG);
        $this->app->when(OnlinePaymentService::class)->needs('$gateways')->giveTagged(OnlineGateway::TAG);
        // Fuentes del tipo de cambio automático; otra fuente se suma a esta lista
        $this->app->tag([SunatExchangeRateSource::class], ExchangeRateSource::TAG);
        $this->app->when(ExchangeRateService::class)->needs('$sources')->giveTagged(ExchangeRateSource::TAG);
        // La plataforma cobra los planes con las mismas pasarelas, con su propia cuenta
        $this->app->when(PlatformGateways::class)->needs('$gateways')->giveTagged(OnlineGateway::TAG);

        // Cupo de alumnos: lo pone el plan de la organización en la plataforma
        $this->app->bind(EnrollmentQuota::class, PlanQuota::class);
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

        // «Descargar los datos de mi academia» es pesado: 3 cada 10 minutos por persona, con su propio contador
        RateLimiter::for('data-export', fn (Request $request) => Limit::perMinutes(10, 3)->by('data-export:'.($request->user()?->getAuthIdentifier() ?: $request->ip())));

        // Reacciones entre módulos: quien emite el evento no conoce a quien lo escucha
        Event::listen(EnrollmentCompleted::class, IssueCertificateOnCompletion::class);
        Event::listen(GradeRecorded::class, IssueCertificateOnGrade::class);
        Event::listen(OrderPaid::class, IssueLicensesOnOrderPaid::class);
        Event::listen(TenantProvisioned::class, StartTrialOnProvision::class);
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
