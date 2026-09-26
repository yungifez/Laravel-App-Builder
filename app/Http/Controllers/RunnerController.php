<?php

namespace App\Http\Controllers;

use App\Http\Requests\RunnerSocketAuthRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RunnerController extends Controller
{
    /**
     * Tell a runner who it is, how often to poll and where its doorbell is.
     */
    public function hello(Request $request): JsonResponse
    {
        $runner = (string) $request->attributes->get('runner');
        $socketUrl = config('workspaces.drivers.runner.socket_url');

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
