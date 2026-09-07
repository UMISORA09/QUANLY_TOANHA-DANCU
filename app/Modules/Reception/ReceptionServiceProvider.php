<?php

namespace App\Modules\Reception;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ReceptionServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     * Đăng ký các service bindings cho module Reception.
     */
    public function register(): void
    {
        $this->app->singleton(
            Services\ReceptionQueryService::class,
            Services\ReceptionQueryService::class
        );
    }

    /**
     * Bootstrap any application services.
     * Khởi tạo routes, migrations, events cho module Reception.
     */
    public function boot(): void
    {
        // Load migrations từ thư mục module
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/routes/api.php');

        // Đăng ký Event → Listener
        // Ví dụ cross-module: ParcelReceived → ResidentService sẽ lắng nghe để gửi thông báo
        Event::listen(
            Events\ParcelReceived::class,
            [\App\Modules\ResidentService\Listeners\NotifyResidentOfParcel::class, 'handle'],
        );

        Event::listen(
            Events\ResidentCreated::class,
            // Listeners sẽ được thêm ở đây
        );
    }
}
