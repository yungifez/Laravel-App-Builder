<?php

namespace App\Publishing;

use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class LiveAppClient
{
    public function __construct(private PublicAddress $addresses) {}

    /**
     * Pin the connection to the public addresses checked for this request.
     * Keep the named host for TLS and refuse redirects and proxy hops.
     *
     * @throws ConnectionException when the address cannot be checked safely.
     */
    public function request(string $address): PendingRequest
    {
        $request = Http::timeout((int) config('builder.publishing.confirm.timeout'))->withoutRedirecting();

        if (config('builder.publishing.allow_local_remotes')) {
            return $request;
        }

        $ips = $this->addresses->addresses($address);

        if ($ips === [] || ! extension_loaded('curl')) {
            throw new ConnectionException('The app address cannot be checked through a public HTTPS connection.');
        }

        $host = strtolower((string) parse_url($address, PHP_URL_HOST));
        $port = parse_url($address, PHP_URL_PORT) ?: 443;
        $resolved = implode(',', array_map(fn (string $ip) => str_contains($ip, ':') ? "[{$ip}]" : $ip, $ips));

        return $request->setHandler(new CurlHandler)->withOptions([
            'verify' => true,
            'proxy' => '',
            'curl' => [
                CURLOPT_RESOLVE => ["{$host}:{$port}:{$resolved}"],
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_PRE_PROXY => '',
            ],
        ]);
    }
}
