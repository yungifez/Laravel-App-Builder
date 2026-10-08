<?php

namespace App\Providers;

use App\Features\FeatureGeneratorManager;
use App\Models\User;
use App\Operations\WorkerPulse;
use App\Runs\Agents\CodingAgentManager;
use App\Runs\ConstructionDriverManager;
use App\Workspaces\Boxes\BoxProviderManager;
use App\Workspaces\Machines\MachineCloudManager;
use App\Workspaces\WorkspaceManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Passport\Passport;

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
        $this->app->singleton(MachineCloudManager::class);
        $this->app->singleton(WorkerPulse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Operators see every owner's changes, so nobody is one by default.
        Gate::define('viewOperations', fn (User $user) => $user->hasVerifiedEmail()
            && in_array(strtolower($user->email), (array) config('operations.operators'), true));

        // Owners' questions go to operators and to the developers an
        // operator approved. Approved developers see nothing else.
        Gate::define('answerDeveloperQuestions', fn (User $user) => $user->can('viewOperations')
            || ($user->hasVerifiedEmail() && $user->developerApplication?->approved() === true));

        // A worker's calls count against its token, not an address it
        // shares with others.
        RateLimiter::for('worker', fn (Request $request) => Limit::perMinute((int) config('builder.agents.workers.per_minute'))
            ->by('worker:'.($request->attributes->get('worker_token') ?? $request->ip())));

        // The owner's tool signed in through OAuth holds a short pass, which
        // it renews on its own for as long as an app stays connected.
        Passport::tokensExpireIn(now()->addMinutes((int) config('builder.agents.workers.sign_in_minutes')));
        Passport::refreshTokensExpireIn(now()->addDays((int) config('builder.agents.workers.project_days')));

        // Where the owner lets their tool in. Whoever registered the tool
        // named it, so the page also says where it sends the owner back.
        Passport::authorizationView(fn (array $parameters) => Inertia::render('auth/AllowTool', [
            'tool' => $parameters['client']->name,
            'returnsTo' => parse_url((string) $parameters['request']->query('redirect_uri'), PHP_URL_HOST),
            'authToken' => $parameters['authToken'],
            'csrfToken' => csrf_token(),
        ])->toResponse($parameters['request']));

        // Checks and previews on their own queues need their own workers
        // under `composer dev` too, or they would never start.
        foreach (['checks' => config('builder.verification.queue'), 'previews' => config('builder.preview.queue')] as $name => $queue) {
            if (is_string($queue) && $queue !== '') {
                DevCommands::artisan("queue:listen --queue={$queue} --tries=1 --timeout=0", $name);
            }
        }
        // Inertia pages receive resources as plain props, not under "data".
        JsonResource::withoutWrapping();
    }
}
