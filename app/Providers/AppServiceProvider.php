<?php

namespace App\Providers;

use App\Features\FeatureGeneratorManager;
use App\Runs\Agents\CodingAgentManager;
use App\Runs\ConstructionDriverManager;
use App\Workspaces\Boxes\BoxProviderManager;
use App\Workspaces\WorkspaceManager;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Framework defaults (strict models, immutable dates, prohibited
     * destructive commands in production, password rules, ...) are configured
     * by nunomaduro/essentials; toggle them in config/essentials.php.
     */
    public function register(): void
    {
        $this->app->singleton(WorkspaceManager::class);
        $this->app->singleton(FeatureGeneratorManager::class);
        $this->app->singleton(ConstructionDriverManager::class);
        $this->app->singleton(CodingAgentManager::class);
        $this->app->singleton(BoxProviderManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Previews on their own queue need their own worker under
        // `composer dev` too, or they would never start.
        if (is_string($queue = config('builder.preview.queue')) && $queue !== '') {
            DevCommands::artisan("queue:listen --queue={$queue} --tries=1 --timeout=0", 'previews');
        }
    }
}
