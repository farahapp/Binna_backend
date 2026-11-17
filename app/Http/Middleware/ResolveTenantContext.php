<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Foundation\TenantContext;
use App\Foundation\TenantContextResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveTenantContext
 *
 * Resolves the TenantContext for the current request using one of three paths:
 *
 *  PATH A — Trusted pre-bound context (token-based login flow):
 *    ResolveTenantFromLoginToken has already validated the resolution_token,
 *    looked up the tenant from the DB, and bound a TenantContext instance into
 *    the container.  This middleware detects that binding and passes through
 *    immediately — X-Tenant is NOT consulted and cannot override the token.
 *
 *  PATH C — SPA session-based tenant resolution (spaLogin flow):
 *    After a successful spaLogin(), AuthController stores the DB-resolved tenant
 *    slug in the session under '_spa_tenant_slug'.  This middleware reads that
 *    value, re-validates the tenant from the DB, and binds TenantContext.
 *    PATH B (X-Tenant header) is intentionally skipped — this prevents a
 *    tenant-switching attack via X-Tenant on an already-authenticated session.
 *
 *  PATH B — X-Tenant header (legacy login / PAT / direct-API requests):
 *    No pre-bound context and no session tenant exist.  The X-Tenant header is
 *    required; the tenant is resolved from the database by slug.
 *
 * Security invariants:
 *  - schema_name is NEVER read from the request; it always comes from the DB.
 *  - A pre-bound context (PATH A) takes precedence; X-Tenant cannot hijack it.
 *  - PATH C: slug from server-set session only; re-validated vs DB per request.
 *  - PATH B: missing X-Tenant → 401, unknown tenant → 404, inactive → 403.
 *  - No cross-tenant access is possible; context is always DB-authoritative.
 *
 * TenantContext is request-scoped and its container binding is cleared after
 * the downstream request pipeline completes, including when it throws.
 */
class ResolveTenantContext
{
    public function __construct(
        private readonly TenantContextResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $this->resolveForRequest($request, $next);
        } finally {
            App::forgetInstance(TenantContext::class);
        }
    }

    private function resolveForRequest(Request $request, Closure $next): Response
    {
        // PATH A: A trusted TenantContext was already bound by
        // ResolveTenantFromLoginToken (token-based flow).
        // X-Tenant is intentionally ignored in this path.
        if (App::bound(TenantContext::class)) {
            return $next($request);
        }

        // PATH A′ — Deferred token flow:
        // ResolveTenantFromLoginToken is registered on the same route group but
        // Laravel's middleware priority system may execute ResolveTenantContext
        // before ResolveTenantFromLoginToken when the 'api' group middleware
        // runs ahead of route-level middleware (observed on Laravel 11 / PHP-FPM).
        //
        // If a resolution_token is present in the request body, this middleware
        // defers — it passes through without requiring X-Tenant.
        // ResolveTenantFromLoginToken (which runs next) will either:
        //   (a) validate the token and bind TenantContext → SwitchToTenantSchema uses it, or
        //   (b) return 422 — the request is rejected before reaching the controller.
        //
        // Security invariant: deferring here does NOT grant access. The request
        // still cannot proceed without a valid TenantContext bound by
        // ResolveTenantFromLoginToken. SwitchToTenantSchema will throw if no
        // TenantContext is bound, and auth:sanctum will reject unauthenticated requests.
        //
        // X-Tenant cannot be used to bypass this: if resolution_token is present
        // but invalid, ResolveTenantFromLoginToken returns 422 before the controller.
        // If resolution_token is absent, PATH B or C applies as normal.
        if ($request->has('resolution_token')) {
            return $next($request);
        }

        // PATH C — SPA session-based tenant resolution:
        //
        // After a successful spaLogin() the server stores the DB-resolved tenant slug
        // in the session under '_spa_tenant_slug'.  That value was set server-side from
        // the verified TenantContext — it is NEVER read from the request or any header.
        //
        // When this path succeeds:
        //  - TenantContext is bound from the DB (fresh lookup, not from the session value
        //    directly) so deactivated tenants are caught immediately.
        //  - PATH B (X-Tenant header) is intentionally skipped, preventing a tenant-switch
        //    attack: a malicious X-Tenant header cannot override an authenticated session.
        //  - Logout calls session()->invalidate(), which removes '_spa_tenant_slug'
        //    automatically — no explicit cleanup required.
        //
        // Security invariants:
        //  - slug is read from the server-set session, not from the client.
        //  - Tenant is re-validated against the DB on every request (active check).
        //  - Stale entries (deleted/deactivated tenant) are purged and rejected (401).
        //  - User membership is verified downstream by VerifyTenantMembership middleware.
        //  - Platform routes use withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
        //    so they never have a session; PATH C cannot fire for platform requests.
        if ($request->hasSession()) {
            $sessionSlug = $request->session()->get('_spa_tenant_slug');

            if (! empty($sessionSlug)) {
                $context = $this->resolver->tryResolve((string) $sessionSlug);

                if ($context === null) {
                    // Tenant was deleted since this session was created — purge entry.
                    $request->session()->forget('_spa_tenant_slug');
                    return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
                }

                if (! $context->isActive()) {
                    // Tenant was deactivated since login — purge entry.
                    $request->session()->forget('_spa_tenant_slug');
                    return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
                }

                App::instance(TenantContext::class, $context);
                return $next($request);
            }
        }

        // PATH B: Legacy / token / direct-API request — require X-Tenant header.
        //
        // Unauthenticated requests that reach this point have no session tenant and
        // no X-Tenant header; we return 401 (not 400) so the response is indistinguishable
        // from an auth failure — leaking the existence of the X-Tenant header is avoided.
        $slug = $request->header('X-Tenant');

        if (empty($slug)) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Resolve from DB — schema_name comes from the database, not the client.
        $context = $this->resolver->tryResolve((string) $slug);

        if ($context === null) {
            return response()->json([
                'error' => 'Tenant not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        if (! $context->isActive()) {
            return response()->json([
                'error' => 'Tenant is not active.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Bind the resolved context so any downstream code can retrieve it.
        App::instance(TenantContext::class, $context);

        return $next($request);
    }
}
