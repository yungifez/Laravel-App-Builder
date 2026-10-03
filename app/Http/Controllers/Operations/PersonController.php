<?php

namespace App\Http\Controllers\Operations;

use App\Actions\Billing\MeasureUsage;
use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Subscription;

/**
 * Everyone with an account: who they are, their plan and their use.
 */
class PersonController extends Controller
{
    /**
     * List people, the newest first, found by name or email.
     */
    public function index(Request $request, MeasureUsage $measureUsage): Response
    {
        $search = trim((string) $request->query('search'));

        $people = User::query()
            ->withCount('projects')
            ->when($search !== '', fn (Builder $users) => $users->where(fn (Builder $users) => $users
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('email', "%{$search}%")))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('operations/People', [
            'search' => $search,
            'totals' => [
                'people' => User::query()->count(),
                'joined_this_week' => User::query()->where('created_at', '>=', now()->subWeek())->count(),
                'paying' => Subscription::query()->whereIn('stripe_status', ['active', 'trialing', 'past_due'])->count(),
            ],
            'people' => $people->through(function (User $user) use ($measureUsage) {
                $usage = $measureUsage->handle($user);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'verified' => $user->email_verified_at !== null,
                    'joined_at' => $user->created_at?->toIso8601String(),
                    'apps' => (int) $user->getAttribute('projects_count'),
                    'plan' => (string) config("billing.plans.{$usage['plan']}.name"),
                    'percent' => $usage['unlimited'] ? null : $usage['percent'],
                ];
            }),
        ]);
    }

    /**
     * Show one person: their plan, use, apps and messages.
     */
    public function show(User $user, MeasureUsage $measureUsage): Response
    {
        $usage = $measureUsage->handle($user);

        return Inertia::render('operations/Person', [
            'person' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'verified' => $user->email_verified_at !== null,
                'joined_at' => $user->created_at?->toIso8601String(),
                'two_factor' => $user->two_factor_confirmed_at !== null,
                'plan' => (string) config("billing.plans.{$usage['plan']}.name"),
                'granted' => $measureUsage->granted($user),
                'granted_until' => $measureUsage->granted($user) === null ? null : $user->granted_plan_until?->toDateString(),
                'stripe' => $user->hasStripeId(),
                'can_sign_in_as' => $user->canBeImpersonated(),
                'usage' => [
                    'percent' => $usage['percent'],
                    'used_usd' => $usage['used_usd'],
                    'allowance_usd' => $usage['allowance_usd'],
                    'resets_on' => $usage['resets_at']->isoFormat('D MMMM'),
                    'unlimited' => $usage['unlimited'],
                ],
            ],
            // The plans an operator can give: all but the one everybody has.
            'plans' => collect((array) config('billing.plans'))->slice(1)->map(fn (array $plan, string $key) => ['key' => $key, 'name' => $plan['name']])->values(),
            'apps' => $user->projects()
                ->withCount('featureRequests')
                ->latest('id')
                ->get()
                ->map(fn (Project $project) => [
                    'name' => $project->name,
                    'changes' => (int) $project->getAttribute('feature_requests_count'),
                    'created_at' => $project->created_at?->toIso8601String(),
                ]),
            'messages' => ContactMessage::query()
                ->where('user_id', $user->id)
                ->orWhere('email', $user->email)
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (ContactMessage $message) => [
                    'id' => $message->id,
                    'message' => $message->message,
                    'sent_at' => $message->created_at?->toIso8601String(),
                    'handled' => $message->handled_at !== null,
                ]),
        ]);
    }
}
