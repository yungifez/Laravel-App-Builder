<?php

namespace Tests\Fixtures;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Stands in for an owner's app while the recorder is tested: each method
 * is the action of one route. It is not in a test class, because the
 * recorder leaves out what a test's own code does inside a request.
 */
class RecordedApp
{
    public const PATH = 'tests/Fixtures/RecordedApp.php';

    public function renamed(User $user): Response
    {
        DB::transaction(function () use ($user) {
            $user->update(['name' => 'Renamed']);
            Mail::raw('Hello', fn ($message) => $message->to('owner@example.com'));
        });
        dispatch(fn () => null);

        return response()->noContent();
    }

    public function read(): Response
    {
        return tap(response()->noContent(), fn () => User::factory()->create());
    }

    public function denied(): never
    {
        User::factory()->create();
        abort(403);
    }

    public function invalid(Request $request): void
    {
        $request->validate(['name' => 'required']);
    }

    public function undone(): void
    {
        try {
            DB::transaction(function () {
                User::factory()->create();
                abort(422);
            });
        } finally {
            User::query()->count();
        }
    }

    public function order(): Response
    {
        DB::table('users')->where('id', 0)->update(['name' => 'Ordered']);
        Mail::raw('Receipt', fn ($message) => $message->to('owner@example.com'));

        return response()->noContent();
    }

    public function invite(): Response
    {
        Mail::raw('You are invited', fn ($message) => $message->to('guest@example.com'));
        DB::transaction(fn () => User::factory()->create());

        return response()->noContent();
    }

    /**
     * Saves in two steps with no transaction around them.
     */
    public function steps(): Response
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['name' => 'Second step']);

        return response()->noContent();
    }

    public function receipt(): Response
    {
        Mail::raw('Receipt', fn ($message) => $message->to('owner@example.com'));

        return response()->noContent();
    }

    public function queued(): Response
    {
        dispatch(function () {
            User::query()->count();
        });
        User::query()->count();

        return response()->noContent();
    }

    public function thrown(): never
    {
        DB::table('users')->where('id', 0)->update(['name' => 'Thrown']);

        throw new RuntimeException('No error page for this one.');
    }

    public function full(User $user): never
    {
        DB::table('users')->where('id', $user->id)->update(['name' => 'Tried']);
        abort(422);
    }

    public function quiet(): Response
    {
        return response()->noContent();
    }
}
