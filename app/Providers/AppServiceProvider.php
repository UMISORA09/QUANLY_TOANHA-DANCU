<?php

namespace App\Providers;

use App\Events\AmenityCreated;
use App\Events\AmenityDeleted;
use App\Events\AmenityUpdated;
use App\Listeners\InvalidateAmenityCacheListener;
use App\Repositories\Contracts\AmenityRepositoryInterface;
use App\Repositories\Contracts\ResidentRepositoryInterface;
use App\Repositories\Eloquent\DatabaseAmenityRepository;
use App\Repositories\Eloquent\EloquentResidentRepository;
use App\Services\Cicd\Adapters\GitHubApiAdapter;
use App\Services\Cicd\Contracts\GitHubApiClientInterface;
use App\Services\Search\Factories\SearchDriverFactory;
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
        // 1. Search Subsystem (Factory & Strategy Manager)
        $this->app->singleton(SearchDriverFactory::class, fn () => new SearchDriverFactory);
        $this->app->singleton(SearchManager::class, fn ($app) => new SearchManager($app->make(SearchDriverFactory::class)));

        // 2. Repositories (Data Access Abstraction)
        $this->app->bind(ResidentRepositoryInterface::class, EloquentResidentRepository::class);
        $this->app->bind(AmenityRepositoryInterface::class, DatabaseAmenityRepository::class);

        // 3. External Integrations (Adapter Pattern)
        $this->app->bind(GitHubApiClientInterface::class, GitHubApiAdapter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Domain Event Listeners (Observer / Pub-Sub Pattern for Side Effects)
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
