<?php

namespace App\Previews;

use App\Enums\PreviewStatus;
use App\Models\Preview;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a preview host: exchanges an owner's single-use grant for a session
 * cookie on that host, then relays the session holder's requests to the
 * preview's app.
 *
 * Requests on a preview host never reach the control plane's routes,
 * sessions or cookies. The preview is passive: nothing it serves can act on
 * the control plane with the owner's rights.
 */
class PreviewGateway
{
    /**
     * The path that exchanges a grant for a session.
     */
    public const SESSION_PATH = '__builder/session';

    /**
     * Request headers that are not forwarded: hop-by-hop headers, and ones
     * the gateway sets itself.
     *
     * @var list<string>
     */
    protected const DROPPED_REQUEST_HEADERS = [
        'host', 'cookie', 'content-length', 'connection', 'keep-alive', 'proxy-authenticate',
        'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade',
        'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port', 'x-forwarded-prefix',
    ];

    /**
     * Hop-by-hop response headers that are not relayed.
     *
     * @var list<string>
     */
    protected const DROPPED_RESPONSE_HEADERS = [
        'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade',
    ];

    /**
     * Handle a request for the preview host.
     */
    public function handle(Request $request, ?Preview $preview): Response
    {
        if ($preview === null) {
            return $this->page(404, __('This preview does not exist.'));
        }

        if ($request->path() === self::SESSION_PATH) {
            return $this->startSession($request, $preview);
        }

        if (! $this->hasSession($request, $preview)) {
            return $this->page(403, __('Open this preview from the builder.'));
        }

        $this->recordActivity($preview);

        return $this->forward($request, $preview);
    }

    /**
     * Exchange a valid, unused grant for a session cookie on the preview host.
     */
    protected function startSession(Request $request, Preview $preview): Response
    {
        $grant = $request->query('grant');

        $valid = $preview->status === PreviewStatus::Ready
            && is_string($grant)
            && $preview->grant_hash !== null
            && hash_equals($preview->grant_hash, hash('sha256', $grant))
            && $preview->grant_expires_at?->isFuture();

        if (! $valid) {
            return $this->page(403, __('This preview link has expired. Open the preview from the builder again.'));
        }

        $secret = Str::random(64);
        $minutes = (int) config('builder.preview.session_minutes');

        $preview->update([
            'grant_hash' => null,
            'grant_expires_at' => null,
            'session_hash' => hash('sha256', $secret),
            'session_expires_at' => now()->addMinutes($minutes),
        ]);

        $response = new RedirectResponse('/');
        $response->headers->setCookie(Cookie::create(
            name: (string) config('builder.preview.cookie'),
            value: $secret,
            expire: now()->addMinutes($minutes),
            path: '/',
            secure: config('builder.preview.scheme') === 'https',
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        ));

        return $response;
    }

    /**
     * Determine if the request carries the preview's current session cookie.
     */
    protected function hasSession(Request $request, Preview $preview): bool
    {
        $secret = $request->cookies->get((string) config('builder.preview.cookie'));

        return $preview->status === PreviewStatus::Ready
            && is_string($secret)
            && $preview->session_hash !== null
            && hash_equals($preview->session_hash, hash('sha256', $secret))
            && (bool) $preview->session_expires_at?->isFuture();
    }

    /**
     * Keep an open preview (and its workspace) from being reaped as idle,
     * writing at most once a minute.
     */
    protected function recordActivity(Preview $preview): void
    {
        if ($preview->last_seen_at !== null && $preview->last_seen_at->isAfter(now()->subMinute())) {
            return;
        }

        $preview->update(['last_seen_at' => now()]);
        $preview->workspace?->update(['last_activity_at' => now()]);
    }

    /**
     * Relay the request to the preview's app and its response back.
     *
     * The app sees the preview host, so the links it generates point at the
     * preview. The gateway's own cookie is removed from what it forwards.
     */
    protected function forward(Request $request, Preview $preview): Response
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            if (! in_array(strtolower($name), self::DROPPED_REQUEST_HEADERS, true)) {
                $headers[$name] = implode(', ', array_filter($values, fn ($value) => $value !== null));
            }
        }

        $headers['Host'] = $request->getHttpHost();
        $headers['X-Forwarded-For'] = (string) $request->ip();
        $headers['X-Forwarded-Host'] = $request->getHttpHost();
        $headers['X-Forwarded-Proto'] = $request->getScheme();

        $cookies = $this->forwardedCookies((string) $request->headers->get('cookie', ''));

        if ($cookies !== '') {
            $headers['Cookie'] = $cookies;
        }

        $options = [];
        $body = $request->getContent();
        $isMultipart = $body === '' && $request->files->count() > 0;

        if ($isMultipart) {
            // PHP does not keep the raw body of multipart requests, so rebuild
            // it from the parsed fields and files (with a new boundary).
            unset($headers['content-type'], $headers['Content-Type']);
            $options['multipart'] = $this->multipart($request);
        }

        $pending = Http::withOptions(['allow_redirects' => false, 'decode_content' => false])
            ->timeout((int) config('builder.preview.request_timeout'))
            ->withHeaders($headers);

        if ($isMultipart) {
            $pending = $pending->asMultipart();
        } elseif ($body === '' && $request->request->count() > 0) {
            $pending = $pending->withBody(http_build_query($request->request->all()), 'application/x-www-form-urlencoded');
        } elseif ($body !== '') {
            $pending = $pending->withBody($body, (string) $request->headers->get('content-type', 'application/octet-stream'));
        }

        try {
            $upstream = $pending->send($request->getMethod(), rtrim((string) $preview->upstream_url, '/').$request->getRequestUri(), $options);
        } catch (ConnectionException) {
            return $this->page(502, __('The preview is not responding. Start it again from the builder.'));
        }

        $response = new Response($upstream->body(), $upstream->status());

        foreach ($upstream->headers() as $name => $values) {
            if (! in_array(strtolower($name), self::DROPPED_RESPONSE_HEADERS, true)) {
                $response->headers->set($name, $values);
            }
        }

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * Rebuild a multipart body from the request's fields and uploaded files.
     *
     * @return list<array{name: string, contents: mixed, filename?: string}>
     */
    protected function multipart(Request $request): array
    {
        $parts = [];

        foreach ($this->flatten($request->request->all()) as $name => $value) {
            $parts[] = ['name' => $name, 'contents' => $value];
        }

        foreach ($this->flatten($request->files->all()) as $name => $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $parts[] = ['name' => $name, 'contents' => fopen($file->getRealPath(), 'r'), 'filename' => $file->getClientOriginalName()];
            }
        }

        return $parts;
    }

    /**
     * Flatten nested form data into field names like "team[name]".
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    protected function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";

            if (is_array($value)) {
                $flat += $this->flatten($value, $name);
            } else {
                $flat[$name] = $value;
            }
        }

        return $flat;
    }

    /**
     * Remove the gateway's session cookie from a Cookie header.
     */
    protected function forwardedCookies(string $header): string
    {
        $name = (string) config('builder.preview.cookie');

        $pairs = array_filter(
            array_map('trim', explode(';', $header)),
            fn (string $pair) => $pair !== '' && ! str_starts_with($pair, $name.'='),
        );

        return implode('; ', $pairs);
    }

    /**
     * Render a short plain page for the preview host.
     */
    protected function page(int $status, string $message): Response
    {
        return new Response(
            '<!doctype html><meta charset="utf-8"><title>Preview</title><p style="font-family:sans-serif;margin:3rem">'.e($message).'</p>',
            $status,
            ['Content-Type' => 'text/html; charset=utf-8', 'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store'],
        );
    }
}
