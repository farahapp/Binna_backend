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
 * ResolveTenantContext (X-Tenant middleware)
 *
 * Reads the X-Tenant header, resolves the tenant from the database,
 * and binds the TenantContext into the service container for the
 * duration of the request.
 *
 * Security invariants:
 *  - schema_name is NEVER read from the request; it comes from the DB only.
 *  - Requests without a valid X-Tenant header receive 400.
 *  - Requests for an unknown tenant receive 404.
 *  - Requests for a non-active tenant receive 403.
 *  - No cross-tenant access is possible because the context is resolved
 *    exclusively from the database record identified by the slug.
 */
class ResolveTenantContext
{
    public function __construct(
        private readonly TenantContextResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->header('X-Tenant');

        if (empty($slug)) {
            return response()->json([
                'error' => 'X-Tenant header is required.',
            ], Response::HTTP_BAD_REQUEST);
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
