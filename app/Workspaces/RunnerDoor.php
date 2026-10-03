<?php

namespace App\Workspaces;

use App\Models\Runner;
use Illuminate\Http\Client\PendingRequest;

/**
 * A runner's preview door: one HTTPS port on the runner that leads to its
 * previews, for a control plane that shares no private network with it
 * (such as one on Laravel Cloud). Its certificate is made by the runner
 * when it starts, so it is trusted by its public key, which the runner
 * reported over its own signed-in hello, not by a certificate authority.
 * Only requests that carry the runner's door key get through.
 */
class RunnerDoor
{
    public const KEY_HEADER = 'X-Builder-Door-Key';

    /**
     * Get the address of a preview port behind a runner's door.
     */
    public static function url(string $host, int $doorPort, int $port): string
    {
        return "https://{$host}:{$doorPort}/~{$port}";
    }

    /**
     * Determine whether an upstream address leads through a runner's door.
     */
    public static function leadsThrough(string $url): bool
    {
        return preg_match('#^https://[^/]+:\d+/~\d+$#', $url) === 1;
    }

    /**
     * Get the address a preview's server listens on. Behind a door it
     * listens only on the runner itself, and the door leads to it.
     */
    public static function listenHost(string $url): string
    {
        return self::leadsThrough($url) ? '127.0.0.1' : (string) parse_url($url, PHP_URL_HOST);
    }

    /**
     * Prepare a request to an upstream address: one through a door trusts
     * only the runner's own certificate and carries its key. When no
     * runner has that door any more, the request fails as one that could
     * not connect, as a stopped preview does.
     */
    public function prepare(PendingRequest $request, string $url): PendingRequest
    {
        if (! self::leadsThrough($url)) {
            return $request;
        }

        $runner = Runner::query()
            ->where('service_host', parse_url($url, PHP_URL_HOST))
            ->where('preview_door_port', parse_url($url, PHP_URL_PORT))
            ->first();

        // No certificate has an all-zero key, so this pin matches none.
        $pin = $runner->preview_door_pin ?? base64_encode(str_repeat("\0", 32));

        return $request
            ->withOptions(['verify' => false, 'curl' => [CURLOPT_PINNEDPUBLICKEY => "sha256//{$pin}"]])
            ->withHeaders([self::KEY_HEADER => (string) $runner?->preview_door_key]);
    }
}
