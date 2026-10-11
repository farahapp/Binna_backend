<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Foundation\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * SwitchToTenantSchema
 *
 * Configures the 'tenant' database connection to point at the schema
 * that was resolved by ResolveTenantContext.
 *
 * Must run AFTER ResolveTenantContext (which binds TenantContext) and
 * BEFORE auth:sanctum (which needs the tenant connection to look up PATs).
 *
 * Pipeline order:
 *   ResolveTenantContext → SwitchToTenantSchema → auth:sanctum → Controller
 *
 * Security invariants:
 *  - The schema name is read exclusively from the server-resolved TenantContext.
 *  - No client-supplied schema/database name is ever trusted.
 *  - Does NOT introduce a second tenant-resolution mechanism.
 *  - Does NOT add row-level tenant_id filtering.
 */
class SwitchToTenantSchema
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        $schemaName      = $context->schemaName;
        $currentDatabase = Config::get('database.connections.tenant.database');

        Config::set('database.connections.tenant.driver',    'mysql');
        Config::set('database.connections.tenant.host',      Config::get('database.connections.mysql.host'));
        Config::set('database.connections.tenant.port',      Config::get('database.connections.mysql.port'));
        Config::set('database.connections.tenant.database',  $schemaName);
        Config::set('database.connections.tenant.username',  Config::get('database.connections.mysql.username'));
        Config::set('database.connections.tenant.password',  Config::get('database.connections.mysql.password'));
        Config::set('database.connections.tenant.charset',   'utf8mb4');
        Config::set('database.connections.tenant.collation', 'utf8mb4_unicode_ci');
        Config::set('database.connections.tenant.prefix',    '');
        Config::set('database.connections.tenant.strict',    true);

        // Only purge and reconnect when the target schema has actually changed.
        // Purging an already-correct connection is unnecessary and, during tests,
        // would destroy the transaction that the testing framework opened on this
        // connection — breaking test isolation.
        if ($currentDatabase !== $schemaName) {
            DB::purge('tenant');
            DB::reconnect('tenant');
        }

        return $next($request);
    }
}
