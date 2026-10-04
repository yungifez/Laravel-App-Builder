<?php

namespace App\Http\Middleware;

use App\Features\LiftedLimit;
use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Arr;
use Inertia\Middleware;
use Lab404\Impersonate\Services\ImpersonateManager;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'operator' => (bool) $request->user()?->can('viewOperations'),
                // An operator signed in as this person; read from the session only.
                'impersonating' => $request->hasSession() && app(ImpersonateManager::class)->isImpersonating(),
            ],
            // What needs the owner, newest first. Pages poll this on its own.
            'notifications' => fn () => $request->user() === null ? null : $this->notifications($request->user()),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // How the owner writes dates, so the page drawn on the server
            // shows them as the browser will and nothing moves once it
            // loads. The browser leaves its time zone in a cookie.
            'clock' => [
                'locale' => str_replace('_', '-', $request->getPreferredLanguage() ?? 'en'),
                'timeZone' => in_array($zone = $request->cookie('time_zone'), timezone_identifiers_list(), true) ? $zone : 'UTC',
            ],
            // Whether the owner's screen is wide, so the app is first drawn
            // at the size it stays at. The browser leaves this in a cookie.
            'wideScreen' => $request->cookie('screen') === 'wide',
        ];
    }

    /**
     * Get what needs the owner, newest first, each named with the app it
     * is about, as one list holds every app's news.
     *
     * @return array{unread: int, items: array<int, array<mixed>>}
     */
    protected function notifications(User $user): array
    {
        $notifications = $user->notifications()->latest()->limit(8)->get();
        $apps = $user->projects()->whereIn('id', $notifications->pluck('data.project_id')->filter())->pluck('name', 'id');
        $stopped = FeatureRequest::query()->with('latestRun')->findMany($notifications->where('data.kind', 'failed')->pluck('data.feature_request_id')->filter())->keyBy('id');

        return [
            'unread' => $user->unreadNotifications()->count(),
            'items' => $notifications->map(fn (DatabaseNotification $notification) => [
                'id' => $notification->id,
                // What it says only: the numbers it keeps stay here.
                ...Arr::only($notification->data, ['kind', 'title', 'body']),
                // A stop for a limit that has lifted no longer says to wait.
                'reason' => ($change = $stopped->get($notification->data['feature_request_id'] ?? null)) === null
                    ? $notification->data['reason'] ?? null
                    : LiftedLimit::reason($change, $notification->data['reason'] ?? null),
                'app' => $apps->get($notification->data['project_id'] ?? null),
                'read' => $notification->read_at !== null,
                'created_at' => $notification->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
