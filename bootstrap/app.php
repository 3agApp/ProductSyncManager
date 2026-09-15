<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetShopUrlDefaults;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetShopUrlDefaults::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['webhook_secret']);
        $exceptions->shouldRenderJsonWhen(
            // Webhooks have no session to redirect back with, so they always get JSON.
            fn (Request $request) => $request->is('api/*') || $request->is('webhooks/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();

            // JSON callers and the Filament panel keep their own error handling.
            if ($response instanceof JsonResponse || $request->is('admin', 'admin/*', 'livewire*')) {
                return $response;
            }

            // An expired session is not worth an error page: send the user back
            // to the form so reloading and resubmitting is the obvious next step.
            if ($status === 419) {
                Inertia::flash('toast', ['type' => 'error', 'message' => __('Your session expired. Please try again.')]);

                return back();
            }

            // 403 and 404 carry no debug detail, so they always get the app's page.
            // Server errors keep the debug screen wherever debug mode is on.
            $showsErrorPage = in_array($status, [403, 404], true)
                || (in_array($status, [500, 503], true) && ! config('app.debug'));

            if (! $showsErrorPage) {
                return $response;
            }

            try {
                return Inertia::render('error-page', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            } catch (Throwable $renderException) {
                // If the app cannot render (database down, missing build), fall
                // back to Laravel's plain page rather than failing twice.
                report($renderException);

                return $response;
            }
        });
    })->create();
