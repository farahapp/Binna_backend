<?php

use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\SwitchToTenantSchema;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // CORS — must be before all other middleware (handles preflight OPTIONS).
        $middleware->prepend(HandleCors::class);

        // Sanctum SPA: adds session/cookie auth support for stateful domains (C-002 §26).
        // Prepend to API group so cookie-based requests are recognized before auth:sanctum check.
        $middleware->prependToGroup('api', EnsureFrontendRequestsAreStateful::class);

        // Register the X-Tenant middleware as a named alias.
        // Apply it selectively to API route groups — not globally.
        // SESSION 27D-6: register Sanctum ability middleware aliases.
        // 'abilities' checks ALL listed abilities (AND logic).
        // 'ability'   checks ANY listed ability  (OR logic).
        $middleware->alias([
            'tenant'        => ResolveTenantContext::class,
            'tenant.schema' => SwitchToTenantSchema::class,
            'abilities'     => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability'       => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);

        // Middleware priority fix (M4 — HB-1):
        // Laravel's default priority list places AuthenticatesRequests (auth:sanctum)
        // before any custom middleware not in the priority list. This causes auth:sanctum
        // to run before ResolveTenantContext and SwitchToTenantSchema, so the 'tenant'
        // connection still points at the shared DB when Sanctum looks up the PAT → 401.
        //
        // Fix: insert ResolveTenantContext and SwitchToTenantSchema into the priority
        // list immediately before AuthenticatesRequests so they always run first.
        $middleware->priority([
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            ResolveTenantContext::class,
            SwitchToTenantSchema::class,
            AuthenticatesRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
