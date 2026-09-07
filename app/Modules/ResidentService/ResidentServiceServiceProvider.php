<?php

namespace App\Modules\ResidentService;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ResidentServiceServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     * Đăng ký các service bindings cho module ResidentService.
     */
    public function register(): void
    {
        $this->app->singleton(
            Services\ResidentServiceQueryService::class,
            Services\ResidentServiceQueryService::class
        );
    }

    /**
     * Bootstrap any application services.
     * Khởi tạo routes, migrations, events cho module ResidentService.
     */
    public function boot(): void
    {
        // Load migrations từ thư mục module
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/routes/api.php');

        // Đăng ký Event → Listener
        Event::listen(
            Events\TicketCreated::class,
            // Listeners sẽ được thêm ở đây
        );

        Event::listen(
            Events\TicketStatusChanged::class,
            // Listeners sẽ được thêm ở đây
        );
    }
}
