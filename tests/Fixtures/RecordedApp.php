<?php

namespace Tests\Fixtures;

use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use RuntimeException;
use Throwable;

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

    public function welcome(User $user): Response
    {
        DB::table('users')->where('id', $user->id)->update(['name' => 'Welcomed']);
        Mail::to('guest@example.com')->send(new RecordedMail);

        return response()->noContent();
    }

    public function noticed(User $user, Request $request): Response
    {
        DB::table('users')->where('id', $user->id)->update(['name' => 'Noticed']);
        $user->notify(new RecordedNotice([$request->string('channel', 'mail')->toString()]));

        return response()->noContent();
    }

    /**
     * Queues one job at once and one that waits for the transaction.
     */
    public function later(): Response
    {
        DB::transaction(function () {
            RecordedJob::dispatch();
            RecordedJob::dispatch()->afterCommit();
            User::query()->count();
        });

        return response()->noContent();
    }

    public function called(User $user): Response
    {
        DB::table('users')->where('id', $user->id)->update(['name' => 'Called']);
        Http::post('https://outside.example/hook', ['user' => $user->id]);

        return response()->noContent();
    }

    /**
     * Marks the person as paid after an outside call, whatever its
     * answer. A careful request asks the answer first and stops. One
     * that notes the answer asks and carries on. A wary one reads what
     * the answer holds and stops on that.
     */
    public function charged(User $user, Request $request): Response
    {
        $answer = Http::post('https://outside.example/charge', ['user' => $user->id]);

        abort_if($request->boolean('careful') && $answer->failed(), 502);
        abort_if($request->boolean('wary') && $answer->json('message') !== null, 502);

        $unpaid = $request->boolean('noted') && $answer->failed();

        DB::table('users')->where('id', $user->id)->update(['name' => $unpaid ? 'Unpaid' : 'Paid']);

        return response()->noContent();
    }

    /**
     * Has other code make the outside call for it, so this code does
     * not get the answer.
     */
    public function relayed(User $user): Response
    {
        value(app(Factory::class)->post(...), 'https://outside.example/hook');
        DB::table('users')->where('id', $user->id)->update(['name' => 'Relayed']);

        return response()->noContent();
    }

    /**
     * Asks if the person may, checks what they sent, saves, tells a
     * listener, and answers with a resource that reads.
     */
    public function parts(User $user, Request $request): RecordedResource
    {
        Gate::authorize('record');
        $request->validate(['name' => 'exists:users,name']);
        $user->update(['name' => 'Parts']);
        event('recorded');

        return new RecordedResource($user);
    }

    public function allowed(User $user): bool
    {
        return User::query()->whereKey($user->id)->exists();
    }

    public function watched(User $user): void
    {
        User::query()->whereKey($user->id)->exists();
    }

    public function heard(): void
    {
        array_map(fn () => User::query()->count(), [1]);
    }

    public function reported(RuntimeException $exception): void
    {
        User::query()->count();
    }

    public function ran(): Response
    {
        dispatch_sync(new RecordedJob);

        return response()->noContent();
    }

    /**
     * Runs one job in place by name, then queues one.
     */
    public function both(): Response
    {
        dispatch_sync(new RecordedJob);
        RecordedJob::dispatch();

        return response()->noContent();
    }

    /**
     * Queues a job that thanks the person, and does nothing after it.
     */
    public function thanked(Request $request): Response
    {
        RecordedPersonalJob::dispatch($request->string('from')->toString(), $request->user()?->email);

        return response()->noContent();
    }

    /**
     * Queues a job about a person, then deletes the person.
     */
    public function dropped(User $user): Response
    {
        RecordedPersonalJob::dispatch('model', person: $user);
        $user->delete();

        return response()->noContent();
    }

    public function told(User $user): Response
    {
        $user->notify(new RecordedQueuedNotice);

        return response()->noContent();
    }

    public function worked(): Response
    {
        RecordedJob::dispatch();

        return response()->noContent();
    }

    public function careful(): Response
    {
        RecordedCarefulJob::dispatch();

        return response()->noContent();
    }

    /**
     * Carries on as if the job it queued is done.
     */
    public function waited(): Response
    {
        RecordedCarefulJob::dispatch();

        if (DB::table('users')->where('name', 'Told')->exists()) {
            Mail::raw('Told', fn ($message) => $message->to('owner@example.com'));
        }

        return response()->noContent();
    }

    /**
     * Dispatches an event that listeners hear.
     */
    public function ordered(): Response
    {
        event(new RecordedEvent);

        return response()->noContent();
    }

    /**
     * Asks an outside service again when it gets no answer.
     */
    public function retried(Request $request): Response
    {
        Http::retry(2, 0)
            ->withHeaders($request->boolean('keyed') ? ['Idempotency-Key' => 'charge-1'] : [])
            ->post('https://outside.example/charge', ['amount' => 5]);

        return response()->noContent();
    }

    /**
     * Catches an email that cannot be sent. It carries on as if the
     * email was sent, unless it is asked to record the failure, to write
     * it to the log or to tell the person.
     */
    public function hushed(Request $request): RedirectResponse
    {
        try {
            Mail::raw('Receipt', fn ($message) => $message->to('owner@example.com'));
        } catch (Throwable $exception) {
            if ($request->boolean('recorded')) {
                report($exception);
            }

            if ($request->boolean('logged')) {
                Log::warning('The receipt was not sent.');
            }

            if ($request->boolean('told')) {
                return redirect('/_hidden/receipt')->with('problem', 'The receipt was not sent.');
            }
        }

        return redirect('/_hidden/receipt')->with('status', 'The receipt is on its way.');
    }

    /**
     * Catches an email that cannot be sent and answers with JSON, the
     * way a package for screens does: what it tells the person is in
     * text that holds JSON. Only when asked does it name a field as wrong.
     */
    public function screened(Request $request): JsonResponse
    {
        $wrong = [];

        try {
            Mail::raw('Receipt', fn ($message) => $message->to('owner@example.com'));
        } catch (Throwable) {
            $wrong = $request->boolean('told') ? ['email' => ['The receipt was not sent.']] : [];
        }

        return response()->json(['components' => [[
            'snapshot' => json_encode(['data' => ['email' => 'owner@example.com', 'rows' => [7 => ['name' => 'First']]], 'memo' => ['errors' => $wrong]]),
            'effects' => ['html' => '<p>Receipt</p>'],
        ]]]);
    }

    /**
     * Catches an email that cannot be sent and answers with an Inertia
     * page. Only when asked does it show another page for the failure.
     */
    public function paged(Request $request): InertiaResponse
    {
        $page = 'Receipt/Sent';

        try {
            Mail::raw('Receipt', fn ($message) => $message->to('owner@example.com'));
        } catch (Throwable) {
            $page = $request->boolean('told') ? 'Receipt/NotSent' : $page;
        }

        return Inertia::render($page, ['receipt' => ['number' => 7, 'lines' => [['name' => 'First']]]]);
    }

    /**
     * Sends one email to each of two people from one line. An email that
     * cannot be sent stops the rest, unless it is asked to record the
     * failure and go on.
     */
    public function round(Request $request): Response
    {
        foreach (['first@example.com', 'second@example.com'] as $to) {
            try {
                Mail::raw('Round', fn ($message) => $message->to($to));
            } catch (Throwable $exception) {
                if (! $request->boolean('careful')) {
                    throw $exception;
                }

                report($exception);
            }
        }

        return response()->noContent();
    }

    /**
     * Saves, then queues a job that catches an email that cannot be sent.
     */
    public function hushedLater(User $user, Request $request): Response
    {
        DB::table('users')->where('id', $user->id)->update(['name' => 'Queued']);
        RecordedHushedJob::dispatch($request->boolean('recorded'));

        return response()->noContent();
    }

    /**
     * Saves, then queues a job that lets a failure of its email through.
     */
    public function workedLater(User $user): Response
    {
        DB::table('users')->where('id', $user->id)->update(['name' => 'Queued']);
        RecordedJob::dispatch();

        return response()->noContent();
    }

    /**
     * Saves, then writes a file to a disk. Only when asked does it look
     * at what the disk gave back and tell the person.
     */
    public function stored(Request $request): RedirectResponse
    {
        DB::table('users')->where('id', 0)->update(['name' => 'Has a note']);
        $stored = Storage::disk('recorded')->put('notes/note.txt', 'A note');

        if ($request->boolean('careful') && ! $stored) {
            return redirect('/_stored/note')->with('problem', 'The note was not stored.');
        }

        return redirect('/_stored/note')->with('status', 'The note is stored.');
    }

    /**
     * Deletes a file from a disk, then the row that names it. A careful
     * request deletes the row first.
     */
    public function removed(Request $request): Response
    {
        if ($request->boolean('careful')) {
            DB::table('users')->where('id', 0)->delete();
            Storage::disk('recorded')->delete('notes/note.txt');
        } else {
            Storage::disk('recorded')->delete('notes/note.txt');
            DB::table('users')->where('id', 0)->delete();
        }

        return response()->noContent();
    }

    /**
     * Catches a save that fails and answers as if it saved.
     */
    public function swallowed(): Response
    {
        try {
            DB::transaction(fn () => User::factory()->create());
        } catch (Throwable) {
            //
        }

        return response()->noContent();
    }
}
