<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ServePreviewHosts;
use App\Http\Middleware\SignOutSuspendedPeople;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ServePreviewHosts::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'time_zone', 'screen']);

        // Stripe signs its webhooks, so Cashier checks them instead.
        $middleware->validateCsrfTokens(except: ['stripe/*']);

        $middleware->web(append: [
            SignOutSuspendedPeople::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The builder's own pages get an error page in its style. A page
        // left open too long goes back with a note instead. Requests
        // outside the web pages, and apps people build, which answer on
        // their own hosts, keep their own errors.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();

            if (app()->isLocal() || $request->expectsJson() || ! $request->hasSession() || $request->getHost() !== parse_url((string) config('app.url'), PHP_URL_HOST)) {
                return $response;
            }

            if ($status === 419) {
                Inertia::flash('toast', ['type' => 'error', 'message' => __('This page was open too long. Try again.')]);

                return back();
            }

            if (! in_array($status, [403, 404, 429, 500, 503], true)) {
                return $response;
            }

            return Inertia::render('public/Error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
