<?php

namespace App\Providers;

use App\Events\AmenityCreated;
use App\Events\AmenityDeleted;
use App\Events\AmenityUpdated;
use App\Listeners\InvalidateAmenityCacheListener;
use App\Repositories\Contracts\AmenityRepositoryInterface;
use App\Repositories\Eloquent\DatabaseAmenityRepository;
use App\Services\Search\SearchManager;
use Illuminate\Support\Facades\Event;
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
        $this->app->bind(AmenityRepositoryInterface::class, DatabaseAmenityRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(AmenityCreated::class, [InvalidateAmenityCacheListener::class, 'handle']);
        Event::listen(AmenityUpdated::class, [InvalidateAmenityCacheListener::class, 'handle']);
        Event::listen(AmenityDeleted::class, [InvalidateAmenityCacheListener::class, 'handle']);

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
