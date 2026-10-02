<?php

namespace App\Http\Controllers;

use App\Http\Requests\RunnerHelloRequest;
use App\Http\Requests\RunnerSocketAuthRequest;
use App\Models\Runner;
use Illuminate\Http\JsonResponse;

class RunnerController extends Controller
{
    /**
     * Tell a runner who it is, how often to poll and where its doorbell is.
     */
    public function hello(RunnerHelloRequest $request): JsonResponse
    {
        $runner = (string) $request->attributes->get('runner');
        $socketUrl = config('workspaces.drivers.runner.socket_url');

        // A runner in the pool says where its previews are reached; the
        // static runner has no row and is reached at its configured host.
        if ($request->filled('service_host')) {
            Runner::query()->where('name', $runner)->update(['service_host' => $request->validated('service_host'), 'last_seen_at' => now()]);
        }

        return response()->json([
            'runner' => $runner,
            'poll_seconds' => (int) config('workspaces.drivers.runner.poll_seconds'),
            'output_limit' => (int) config('workspaces.commands.output_limit'),
            'socket' => filled($socketUrl) ? [
                'url' => $socketUrl,
                'key' => config('broadcasting.connections.reverb.key'),
                'channel' => "private-runner.{$runner}",
            ] : null,
        ]);
    }

    /**
     * Sign the runner's subscription to its own private channel, as the
     * Pusher protocol that Reverb speaks expects.
     */
    public function socketAuth(RunnerSocketAuthRequest $request): JsonResponse
    {
        $key = (string) config('broadcasting.connections.reverb.key');
        $signature = hash_hmac('sha256', "{$request->validated('socket_id')}:{$request->validated('channel_name')}", (string) config('broadcasting.connections.reverb.secret'));

        return response()->json(['auth' => "{$key}:{$signature}"]);
    }
}
