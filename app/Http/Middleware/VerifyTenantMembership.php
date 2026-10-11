<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Foundation\TenantContext;
use App\IAM\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * VerifyTenantMembership
 *
 * After Sanctum authenticates the user, this middleware verifies that
 * the authenticated user exists in the current tenant's schema.
 *
 * Security invariant: a PAT issued for Tenant A cannot be used in Tenant B.
 */
class VerifyTenantMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);

        // Switch to tenant schema and verify user exists there.
        $this->switchToTenantSchema($context->schemaName);

        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        // Verify the user actually belongs to this tenant's schema.
        $exists = User::where('id', $user->id)->exists();

        if (! $exists) {
            return response()->json(
                ['error' => 'User does not belong to this tenant.'],
                Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }

    private function switchToTenantSchema(string $schemaName): void
    {
        Config::set('database.connections.tenant.driver', 'mysql');
        Config::set('database.connections.tenant.host', Config::get('database.connections.mysql.host'));
        Config::set('database.connections.tenant.port', Config::get('database.connections.mysql.port'));
        Config::set('database.connections.tenant.database', $schemaName);
        Config::set('database.connections.tenant.username', Config::get('database.connections.mysql.username'));
        Config::set('database.connections.tenant.password', Config::get('database.connections.mysql.password'));
        Config::set('database.connections.tenant.charset', 'utf8mb4');
        Config::set('database.connections.tenant.collation', 'utf8mb4_unicode_ci');
        Config::set('database.connections.tenant.prefix', '');
        Config::set('database.connections.tenant.strict', true);

        DB::purge('tenant');
        DB::reconnect('tenant');
    }
}
