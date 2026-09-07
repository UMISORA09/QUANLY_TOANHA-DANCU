<?php

namespace App\Modules\Billing;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     * Đăng ký các service bindings cho module Billing.
     */
    public function register(): void
    {
        $this->app->singleton(
            Services\BillingQueryService::class,
            Services\BillingQueryService::class
        );
    }

    /**
     * Bootstrap any application services.
     * Khởi tạo routes, migrations, events cho module Billing.
     */
    public function boot(): void
    {
        // Load migrations từ thư mục module
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/routes/api.php');

        // Đăng ký Event → Listener
        Event::listen(
            Events\InvoiceCreated::class,
            // Listeners sẽ được thêm ở đây
        );

        Event::listen(
            Events\PaymentOverdue::class,
            // Listeners sẽ được thêm ở đây
        );
    }
}
