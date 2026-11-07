<?php

namespace App\Providers;

use App\Infrastructure\AuditLog;
use App\Infrastructure\Metrics;
use App\Infrastructure\TransactionManager;
use App\Repositories\CustomerRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Services\NotificationService;
use App\Support\RequestClock;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Bind by class name only: register() runs before GoAopServiceProvider initializes the aspect kernel (at the start
     * of the boot phase), and a class resolved here would be loaded unwoven.
     */
    public function register(): void
    {
        // One instance per request, shared by the services and the aspects
        foreach ([
            AuditLog::class,
            Metrics::class,
            TransactionManager::class,
            ProductRepository::class,
            CustomerRepository::class,
            OrderRepository::class,
            NotificationService::class,
        ] as $service) {
            $this->app->singleton($service);
        }

        // The generated module handlers, collected by App\Services\ModuleRunner with #[Tag('app.modules')]
        $this->app->tag(require app_path('Modules/modules.php'), 'app.modules');

        $this->app->booted(static function (): void {
            RequestClock::$bootedAt = microtime(true);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
