<?php

namespace App\Providers;

use App\Modules\Academic\Application\ActivityContent;
use App\Modules\Certificates\Application\IssueCertificateOnCompletion;
use App\Modules\Courses\Application\CourseContentExtension;
use App\Modules\Enrollments\Domain\Events\EnrollmentCompleted;
use App\Modules\Sales\Application\Payments\ManualPaymentGateway;
use App\Modules\Sales\Application\Payments\PaymentGateway;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
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

        // Reacciones entre módulos: quien emite el evento no conoce a quien lo escucha
        Event::listen(EnrollmentCompleted::class, IssueCertificateOnCompletion::class);

        // Cada módulo de dominio versiona sus propias migraciones
        $this->loadMigrationsFrom(glob(app_path('Modules/*/Database/Migrations')));
    }
}
