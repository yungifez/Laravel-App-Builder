<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Arr;
use Inertia\Middleware;

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
            ],
            // What needs the owner, newest first. Pages poll this on its own.
            'notifications' => fn () => $request->user() === null ? null : [
                'unread' => $request->user()->unreadNotifications()->count(),
                'items' => $request->user()->notifications()->latest()->limit(8)->get()
                    ->map(fn (DatabaseNotification $notification) => [
                        'id' => $notification->id,
                        // What it says only: the numbers it keeps stay here.
                        ...Arr::only($notification->data, ['kind', 'title', 'body']),
                        'read' => $notification->read_at !== null,
                        'created_at' => $notification->created_at?->toIso8601String(),
                    ]),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
