<?php

namespace App\Providers;

use App\Services\Search\SearchManager;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SearchManager::class, fn () => new SearchManager);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (
            $this->app->environment('production')
            || request()->header('x-forwarded-proto') === 'https'
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || env('VERCEL')
        ) {
            URL::forceScheme('https');
        }
    }
}
