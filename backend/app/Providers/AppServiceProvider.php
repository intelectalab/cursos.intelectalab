<?php

namespace App\Providers;

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

        // Cada módulo de dominio versiona sus propias migraciones
        $this->loadMigrationsFrom(glob(app_path('Modules/*/Database/Migrations')));
    }
}
