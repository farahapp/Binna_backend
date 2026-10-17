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

        // Only switch the active database when the target schema has changed.
        //
        // We use a USE statement instead of DB::purge() + DB::reconnect() to
        // avoid destroying any open database transaction on this connection.
        // During tests, RefreshDatabase wraps each test in a transaction on the
        // 'tenant' connection; purging the connection would roll back that
        // transaction and break test isolation.
        //
        // In production there are no open transactions at middleware time, so
        // the USE statement is equivalent to a reconnect for our purposes.
        if ($currentDatabase !== $schemaName) {
            DB::connection('tenant')->statement("USE `{$schemaName}`");
        }

        return $next($request);
    }
}
