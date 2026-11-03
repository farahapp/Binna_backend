<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Foundation\PlatformUser;
use App\Foundation\TenantContext;
use App\Http\Middleware\VerifyTenantMembership;
use App\IAM\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * VerifyTenantMembershipTypeGuardTest — GAP-3
 *
 * Verifies the defensive type guard added to VerifyTenantMembership:
 * a PlatformUser instance must be rejected with HTTP 403 immediately,
 * without executing any query against the tenant schema.
 *
 * All tests invoke the middleware directly (unit-style) to avoid the full
 * HTTP stack and to allow precise assertion that no tenant DB query runs.
 *
 * Coverage:
 *   TG-01  PlatformUser → 403, message contains 'Platform operators'
 *   TG-02  PlatformUser → no tenant DB query executed (DB::pretend assertion)
 *   TG-03  IAM\User present in tenant schema → passes through (200)
 *   TG-04  IAM\User absent from tenant schema → 403 'does not belong'
 *
 * Constraints:
 *   - No application files modified beyond VerifyTenantMembership.php.
 *   - No real tenant provisioned; no schema created or dropped.
 *   - No MySQL settings altered.
 *   - No stage/commit/push/deploy.
 */
class VerifyTenantMembershipTypeGuardTest extends TestCase
{
    private const SCHEMA = 'binaa2_testing';
    private const TENANT_SLUG = 'tg-test-tenant';

    // -------------------------------------------------------------------------
    // setUp / tearDown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext();
        $this->configureTenantConnection();
        $this->ensureIamTablesExist();
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('tenant')->table('users')
                ->where('email', 'like', 'tg-test-%')
                ->delete();
        } catch (\Throwable) {}

        parent::tearDown();
    }

    // =========================================================================
    // TG-01 — PlatformUser is rejected with 403
    // =========================================================================

    /** @test */
    public function platform_user_is_rejected_with_403(): void
    {
        $platformUser = new PlatformUser();
        $platformUser->id    = 999;
        $platformUser->name  = 'Platform Op';
        $platformUser->email = 'op@platform.test';

        $request = Request::create('/api/auth/me', 'GET');
        $request->setUserResolver(fn () => $platformUser);

        $middleware = new VerifyTenantMembership();
        $response   = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(403, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertStringContainsString(
            'Platform operators',
            $body['error'],
            'Error message must identify the rejection as a platform-operator type guard'
        );
    }

    // =========================================================================
    // TG-02 — PlatformUser rejection fires NO tenant DB query
    // =========================================================================

    /** @test */
    public function platform_user_rejection_executes_no_tenant_db_query(): void
    {
        $platformUser = new PlatformUser();
        $platformUser->id    = 888;
        $platformUser->name  = 'Platform Op';
        $platformUser->email = 'op2@platform.test';

        $request = Request::create('/api/auth/me', 'GET');
        $request->setUserResolver(fn () => $platformUser);

        $tenantQueriesExecuted = [];

        // DB::listen captures every query on every connection.
        // We record only those on the 'tenant' connection.
        DB::listen(function ($query) use (&$tenantQueriesExecuted): void {
            // $query->connectionName is the resolved connection name.
            if (str_contains((string) $query->connectionName, 'tenant')) {
                $tenantQueriesExecuted[] = $query->sql;
            }
        });

        $middleware = new VerifyTenantMembership();
        $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        // Filter to SELECT queries on the users table — the membership check.
        $membershipQueries = array_filter(
            $tenantQueriesExecuted,
            fn (string $sql) => stripos($sql, 'users') !== false
        );

        $this->assertEmpty(
            $membershipQueries,
            'VerifyTenantMembership must NOT query the tenant users table for a PlatformUser. '
            . 'Queries recorded: ' . implode('; ', $tenantQueriesExecuted)
        );
    }

    // =========================================================================
    // TG-03 — IAM\User present in tenant schema passes through (200)
    // =========================================================================

    /** @test */
    public function tenant_user_present_in_schema_passes_through(): void
    {
        // Create a real user in the tenant schema.
        $user           = new User();
        $user->setConnection('tenant');
        $user->name     = 'TG Test Member';
        $user->email    = 'tg-test-member-' . Str::random(6) . '@example.com';
        $user->password = Hash::make('password');
        $user->save();

        $request = Request::create('/api/auth/me', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new VerifyTenantMembership();
        $response   = $middleware->handle(
            $request,
            fn ($r) => response()->json(['ok' => true], 200)
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    // =========================================================================
    // TG-04 — IAM\User absent from tenant schema is rejected with 403
    // =========================================================================

    /** @test */
    public function tenant_user_absent_from_schema_is_rejected_with_403(): void
    {
        // Build a User object that is NOT saved to the DB (ghost user).
        $ghostUser = new User();
        $ghostUser->setConnection('tenant');
        $ghostUser->id = Str::uuid()->toString();

        $request = Request::create('/api/auth/me', 'GET');
        $request->setUserResolver(fn () => $ghostUser);

        $middleware = new VerifyTenantMembership();
        $response   = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(403, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertStringContainsString(
            'does not belong to this tenant',
            $body['error'],
            'Error message must indicate the user is not a member of this tenant'
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function bindTenantContext(): void
    {
        $context = new TenantContext(
            id: 1,
            slug: self::TENANT_SLUG,
            name: 'TG Test Tenant',
            schemaName: self::SCHEMA,
            status: 'active',
        );

        App::instance(TenantContext::class, $context);
    }

    private function configureTenantConnection(): void
    {
        Config::set('database.connections.tenant.driver',    'mysql');
        Config::set('database.connections.tenant.host',      config('database.connections.mysql.host', '127.0.0.1'));
        Config::set('database.connections.tenant.port',      config('database.connections.mysql.port', '3307'));
        Config::set('database.connections.tenant.database',  self::SCHEMA);
        Config::set('database.connections.tenant.username',  config('database.connections.mysql.username'));
        Config::set('database.connections.tenant.password',  config('database.connections.mysql.password'));
        Config::set('database.connections.tenant.charset',   'utf8mb4');
        Config::set('database.connections.tenant.collation', 'utf8mb4_unicode_ci');
        Config::set('database.connections.tenant.prefix',    '');
        Config::set('database.connections.tenant.strict',    true);
        DB::purge('tenant');
    }

    private function ensureIamTablesExist(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasTable('users')) {
            $schema->create('users', function ($table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }
    }
}
