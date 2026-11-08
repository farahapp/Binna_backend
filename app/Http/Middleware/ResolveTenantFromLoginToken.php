<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Foundation\Tenant;
use App\Foundation\TenantContext;
use App\Foundation\TenantContextResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ResolveTenantFromLoginToken
 *
 * Validates the short-lived resolution_token issued by TenantResolutionController
 * and, on success, binds a trusted TenantContext directly into the service
 * container — without touching HeaderBag or injecting any synthetic header.
 *
 * Security invariants:
 *  - If resolution_token is absent/invalid/expired the request is rejected (422).
 *  - X-Tenant is intentionally ignored here; it cannot override a token-based flow.
 *  - The TenantContext is resolved exclusively from the database (slug + id + status).
 *  - ResolveTenantContext (downstream) will detect the pre-bound context and skip
 *    its own X-Tenant lookup, so X-Tenant cannot hijack the login flow.
 *
 * Legacy / Platform flows that do NOT pass through this middleware are unaffected.
 *
 * ── WARN V-08 — Laravel Octane compatibility note ────────────────────────────
 * App::instance() binds the TenantContext into the application container for
 * the duration of this request.  In standard PHP-FPM each request is a
 * separate process, so the binding is naturally scoped to one request.
 *
 * If the application is ever migrated to Laravel Octane (long-running process),
 * the container is reused across requests.  The TenantContext bound here would
 * persist into the next request unless explicitly cleared.
 *
 * Mitigation required before adopting Octane:
 *   Add a terminate() hook (or Octane RequestHandled listener) that calls
 *   App::forgetInstance(TenantContext::class) after each request completes.
 *
 * This note is documentation only — NO behaviour change is made here.
 * Current runtime: PHP-FPM (safe).  Octane: NOT in use.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class ResolveTenantFromLoginToken
{
    public function __construct(
        private readonly TenantContextResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $raw = (string) $request->input('resolution_token');

            if ($raw === '') {
                throw new \RuntimeException('Missing resolution_token.');
            }

            $payload = json_decode(
                Crypt::decryptString($raw),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (($payload['expires_at'] ?? 0) < now()->timestamp) {
                throw new \RuntimeException('Expired token.');
            }

            // Double-check: id + slug + status=active — all from the DB.
            $tenant = Tenant::on('mysql')
                ->whereKey($payload['tenant_id'] ?? null)
                ->where('slug', $payload['slug'] ?? null)
                ->where('status', 'active')
                ->firstOrFail();

            // Bind a trusted TenantContext directly — no header injection.
            // ResolveTenantContext will honour this pre-bound instance.
            App::instance(TenantContext::class, TenantContext::fromModel($tenant));
        } catch (Throwable) {
            return response()->json(['message' => 'Unable to continue with the provided details.'], 422);
        }

        return $next($request);
    }
}
