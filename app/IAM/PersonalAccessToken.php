<?php

declare(strict_types=1);

namespace App\IAM;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * PersonalAccessToken — tenant-scoped Sanctum PAT model (C-002).
 *
 * Extends the standard Sanctum PersonalAccessToken and forces all
 * PAT reads and writes to use the 'tenant' connection, ensuring that
 * tokens are stored in and looked up from the per-tenant schema.
 *
 * Registered via Sanctum::usePersonalAccessTokenModel() in IamServiceProvider.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * The database connection that should be used by the model.
     *
     * Forces all PAT operations (storage, lookup, revocation) to the
     * tenant connection, which is configured at runtime by
     * SwitchToTenantSchema middleware to point at the correct per-tenant schema.
     */
    protected $connection = 'tenant';
}
