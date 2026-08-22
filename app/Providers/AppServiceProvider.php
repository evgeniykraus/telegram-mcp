<?php

namespace App\Providers;

use App\Factories\CustomMadelineProtoFactory;
use App\Repositories\Analytics\AnalyticsRepository;
use App\Repositories\Analytics\Contracts\AnalyticsRepositoryInterface;
use App\Repositories\Post\Contracts\PostRepositoryInterface;
use App\Repositories\Post\PostRepository;
use App\Repositories\Source\Contracts\SourceRepositoryInterface;
use App\Repositories\Source\SourceRepository;
use App\Services\Analytics\AnalyticsService;
use App\Services\Collection\TelegramCollectionService;
use App\Services\Pipeline\EmbeddingService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            SourceRepositoryInterface::class,
            SourceRepository::class
        );

        $this->app->bind(
            PostRepositoryInterface::class,
            PostRepository::class
        );

        $this->app->bind(
            AnalyticsRepositoryInterface::class,
            AnalyticsRepository::class
        );

        $this->app->singleton(CustomMadelineProtoFactory::class, function ($app): CustomMadelineProtoFactory {
            return new CustomMadelineProtoFactory(
                $app->make(DatabaseManager::class),
                'telegram_sessions'
            );
        });

        $this->app->bind(AnalyticsService::class, function ($app): AnalyticsService {
            return new AnalyticsService(
                $app->make(AnalyticsRepositoryInterface::class),
                $app->make(SourceRepositoryInterface::class),
                $app->make(PostRepositoryInterface::class),
                $app->make(EmbeddingService::class),
                $app->make(TelegramCollectionService::class),
            );
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
