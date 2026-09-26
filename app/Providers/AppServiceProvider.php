<?php

namespace App\Providers;

use App\Features\FeatureGeneratorManager;
use App\Runs\Agents\CodingAgentManager;
use App\Runs\ConstructionDriverManager;
use App\Workspaces\WorkspaceManager;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
