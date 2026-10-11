<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * TenantContextResolver — resolves a TenantContext from the database
 * using the slug supplied in the X-Tenant header.
 *
 * The schema_name is ALWAYS read from the database; it is never derived
 * from or accepted from the client.
 */
class TenantContextResolver
{
    /**
     * Resolve a TenantContext by slug.
     *
     * @throws ModelNotFoundException
     */
    public function resolve(string $slug): TenantContext
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::where('slug', $slug)->firstOrFail();

        return TenantContext::fromModel($tenant);
    }

    /**
     * Attempt to resolve; returns null if the tenant is not found.
     */
    public function tryResolve(string $slug): ?TenantContext
    {
        $tenant = Tenant::where('slug', $slug)->first();

        return $tenant ? TenantContext::fromModel($tenant) : null;
    }
}
