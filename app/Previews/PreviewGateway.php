<?php

namespace App\Previews;

use App\Actions\Previews\GrantPreviewAccess;
use App\Enums\PreviewStatus;
use App\Models\Preview;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
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
     * The path a page of the app calls while someone can see it, so the
     * preview keeps running (resources/preview-tools/alive.js).
     */
    public const ALIVE_PATH = '__builder/alive';

    /**
     * How many places one preview can be open in at once, such as the
     * builder and a tab of its own.
     */
    protected const SESSIONS = 5;

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
            return $this->page(403, __('This page has closed. Open the app again from the builder, or from the link you were sent.'));
        }

        if ($request->path() === self::ALIVE_PATH) {
            $this->recordActivity($preview);

            return new Response('', 204, ['Cache-Control' => 'no-store']);
        }

        // Requests a page makes by itself, such as polling, do not count:
        // a tab left open in the background would keep the app running.
        // Opening a page does, and an open page says when it is seen.
        if ($this->opensPage($request)) {
            $this->recordActivity($preview);
        }

        return $this->forward($request, $preview);
    }

    /**
     * Exchange a valid, unused grant for a session cookie on the preview host.
     */
    protected function startSession(Request $request, Preview $preview): Response
    {
        $grant = $request->query('grant');

        // Someone the owner shared the app with, rather than the owner.
        $shared = $preview->status === PreviewStatus::Ready
            && is_string($grant)
            && Cache::pull(GrantPreviewAccess::sharedKey($preview, $grant)) === true;

        $valid = $shared || ($preview->status === PreviewStatus::Ready
            && is_string($grant)
            && $preview->grant_hash !== null
            && hash_equals($preview->grant_hash, hash('sha256', $grant))
            && $preview->grant_expires_at?->isFuture());

        if (! $valid) {
            return $this->page(403, __('This link to the app has expired. Open the app again from the builder, or from the link you were sent.'));
        }

        $secret = Str::random(64);
        $minutes = (int) config('builder.preview.session_minutes');

        if (! $shared) {
            $preview->update([
                'grant_hash' => null,
                'grant_expires_at' => null,
                'session_hash' => hash('sha256', $secret),
                'session_expires_at' => now()->addMinutes($minutes),
            ]);
        }

        // The owner can have the app open in the builder and in a tab of its
        // own at once, so a new session does not end the ones before it.
        // People the owner shared it with have sessions of their own, so
        // they never end the owner's.
        $key = $shared ? self::sharedSessionsKey($preview) : self::sessionsKey($preview);
        /** @var array<string, int> $open */
        $open = Cache::get($key, []);
        $sessions = collect($open)
            ->filter(fn (int $expires) => $expires > now()->getTimestamp())
            ->put(hash('sha256', $secret), now()->addMinutes($minutes)->getTimestamp())
            ->sortDesc()
            ->take($shared ? (int) config('builder.preview.shared_sessions') : self::SESSIONS);
        Cache::put($key, $sessions->all(), now()->addMinutes($minutes));

        // Every preview can show inside the builder (the app, and a change
        // waiting for the owner), where the preview host is a third party,
        // so its cookie is partitioned to the site that shows it.
        // Back to the page the owner was on. Only a path on this host.
        $to = $request->query('to');
        $response = new RedirectResponse(is_string($to) && preg_match('#^/(?![/\\\\])#', $to) === 1 ? $to : '/');
        $response->headers->setCookie(Cookie::create(
            name: (string) config('builder.preview.cookie'),
            value: $secret,
            expire: now()->addMinutes($minutes),
            path: '/',
            secure: true,
            httpOnly: true,
            sameSite: Cookie::SAMESITE_NONE,
            partitioned: true,
        ));

        // A cookie of the app's own that came with the grant, such as the
        // session of the person the owner signs in as.
        $cookie = Cache::pull(GrantPreviewAccess::cookieKey($preview, (string) $grant));

        if (is_array($cookie)) {
            $response->headers->setCookie(Cookie::create(
                name: (string) $cookie['name'],
                value: (string) $cookie['value'],
                expire: now()->addMinutes((int) $cookie['minutes']),
                path: '/',
                secure: true,
                httpOnly: true,
                sameSite: Cookie::SAMESITE_NONE,
                partitioned: true,
            ));
        }

        return $response;
    }

    /**
     * Determine if the request carries the preview's current session cookie.
     */
    protected function hasSession(Request $request, Preview $preview): bool
    {
        $secret = $request->cookies->get((string) config('builder.preview.cookie'));

        if ($preview->status !== PreviewStatus::Ready || ! is_string($secret)) {
            return false;
        }

        $hash = hash('sha256', $secret);

        if ($preview->session_hash !== null && hash_equals($preview->session_hash, $hash)) {
            return (bool) $preview->session_expires_at?->isFuture();
        }

        $expires = Cache::get(self::sessionsKey($preview), [])[$hash]
            ?? Cache::get(self::sharedSessionsKey($preview), [])[$hash]
            ?? null;

        return is_int($expires) && $expires > now()->getTimestamp();
    }

    /**
     * Where the sessions of a preview still open wait, so the owner can
     * have it open in more than one place.
     */
    public static function sessionsKey(Preview $preview): string
    {
        return "previews:{$preview->id}:sessions";
    }

    /**
     * Where the sessions of people the owner shared the app with wait.
     */
    public static function sharedSessionsKey(Preview $preview): string
    {
        return "previews:{$preview->id}:shared-sessions";
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
     * Tell whether the request opens a page. Browsers say so; a client
     * that does not, such as curl, is counted as a person.
     */
    protected function opensPage(Request $request): bool
    {
        $mode = $request->headers->get('Sec-Fetch-Mode');

        return $mode === null || $mode === 'navigate';
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
            return $this->page(502, __('The preview is not responding. Start it again from the builder.'), tellBuilder: true);
        }

        $response = new Response($upstream->body(), $upstream->status());

        foreach ($upstream->headers() as $name => $values) {
            if (! in_array(strtolower($name), self::DROPPED_RESPONSE_HEADERS, true)) {
                $response->headers->set($name, $values);
            }
        }

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $this->letBuilderShow($response);

        $this->addScript($response, File::get(resource_path('preview-tools/alive.js')));

        if ($preview->editable) {
            $this->prepareForEditing($response);
        } else {
            $this->reportPages($response);
        }

        return $response;
    }

    /**
     * Let the builder show the app in a frame, as a working app. Only the
     * builder's origin may frame it. There the preview host is a third
     * party, so the app's own cookies (its session, its form tokens) would
     * be refused and every sign-in or form would fail: they are relayed
     * the way a framed site's cookies must be, kept apart for the builder.
     */
    protected function letBuilderShow(Response $response): void
    {
        $response->headers->remove('X-Frame-Options');
        $response->headers->set('Content-Security-Policy', 'frame-ancestors '.self::builderOrigin(), false);

        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            $response->headers->setCookie($cookie->withSecure(true)->withSameSite(Cookie::SAMESITE_NONE)->withPartitioned(true));
        }
    }

    /**
     * Add the point-and-edit overlay to an editable preview's pages.
     */
    protected function prepareForEditing(Response $response): void
    {
        $this->addScript($response, File::get((string) config('builder.preview.overlay')));
    }

    /**
     * Let the builder follow the pages of a preview that is not edited,
     * such as a change the owner tries: each page says where it is, and
     * the builder's back, forward and page list move it.
     */
    protected function reportPages(Response $response): void
    {
        $this->addScript($response, File::get(resource_path('preview-tools/pages.js')));
    }

    /**
     * Add a script for the builder to the end of an HTML page.
     */
    protected function addScript(Response $response, string $script): void
    {
        $body = (string) $response->getContent();
        $position = strripos($body, '</body>');
        $encoded = ! in_array(strtolower((string) $response->headers->get('Content-Encoding', 'identity')), ['', 'identity'], true);

        if ($encoded || $position === false || ! str_contains(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            return;
        }

        $tag = '<script data-builder-origin="'.e(self::builderOrigin()).'">'.$script.'</script>';

        $response->setContent(substr_replace($body, $tag, $position, 0));
        $response->headers->remove('Content-Length');
    }

    /**
     * Get the origin of the builder (scheme, host and port of APP_URL).
     */
    public static function builderOrigin(): string
    {
        $url = parse_url((string) config('app.url'));
        $port = isset($url['port']) ? ':'.$url['port'] : '';

        return ($url['scheme'] ?? 'http').'://'.($url['host'] ?? 'localhost').$port;
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
    protected function page(int $status, string $message, bool $tellBuilder = false): Response
    {
        // A builder showing the app hears that it stopped, so it can offer
        // to start it again instead of showing this page. The builder page
        // can load this frame before it listens, as when it was drawn on the
        // server; it then says hello, and hears it again.
        $origin = json_encode(self::builderOrigin(), JSON_UNESCAPED_SLASHES);
        $script = $tellBuilder
            ? '<script>parent.postMessage({builder:true,type:"lost"},'.$origin.');'
                .'addEventListener("message",function(e){if(e.origin==='.$origin.'&&e.source===parent&&e.data&&e.data.builder===true&&e.data.type==="hello"){parent.postMessage({builder:true,type:"lost"},'.$origin.')}})</script>'
            : '';

        return new Response(
            '<!doctype html><meta charset="utf-8"><title>Preview</title><p style="font-family:sans-serif;margin:3rem">'.e($message).'</p>'.$script,
            $status,
            ['Content-Type' => 'text/html; charset=utf-8', 'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store'],
        );
    }
}
