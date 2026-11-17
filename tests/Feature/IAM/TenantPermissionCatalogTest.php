<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Foundation\IamMigrator;
use App\Foundation\ProvisioningConnectionAccess;
use App\IAM\Role;
use App\IAM\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * DEC-BI-IAM-001 — tenant permission catalog via IamMigrator.
 *
 * Verifies that:
 *  1. A tenant going through the IamMigrator path (provisioning or sanctioned
 *     re-run) gets the 'bi.dashboard.view' permission exactly once and linked
 *     to tenant_admin only (company admins can view BI dashboards).
 *  2. Existing tenants are backfilled idempotently — no duplicates on re-run.
 *  3. employee (and any other role) NEVER receives the permission.
 *
 * These tests require the isolated local MySQL harness (BINAA2_TEST_ISOLATED_MYSQL)
 * because they exercise real per-tenant IAM schema operations on MySQL.
 * On the default in-memory SQLite harness they are skipped — the catalog is
 * still proven by the MySQL harness runs recorded in the checkpoint docs.
 */
class TenantPermissionCatalogTest extends TestCase
{
    private const PASSWORD = 'Catalog001Test!Aa';

    private ?array $tenantCtx = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('BINAA2_TEST_ISOLATED_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Requires the isolated local MySQL harness (BINAA2_TEST_ISOLATED_MYSQL=true).');
        }

        require_once __DIR__ . '/../../bootstrap_iam_tenant.php';
        $this->tenantCtx = bootstrapAuthSmokeTenant(self::PASSWORD);

        // Allow the IamMigrator temporary (provisioning-driver) connection to
        // open inside THIS test only. Production keeps the strict guard; the
        // isolation test that asserts the guard denial runs in its own class
        // with a fresh app instance and is unaffected by this binding.
        $this->app->instance(
            ProvisioningConnectionAccess::class,
            new class extends ProvisioningConnectionAccess {
                public function assertAuthorized(): void {}
            }
        );
    }

    protected function tearDown(): void
    {
        // Remove test-created artifacts; keep the catalog itself (it is the
        // legitimate end-state of the harness schema).
        try {
            if ($this->tenantCtx !== null) {
                $conn = DB::connection('tenant');
                $conn->table('model_has_roles')->where('model_id', $this->tenantCtx['user']->id)->delete();
                $conn->table('users')->where('email', $this->tenantCtx['user']->email)->delete();
                $conn->table('roles')->whereIn('name', [$this->tenantCtx['role']->name, 'employee'])->delete();
                $conn->table('personal_access_tokens')->delete();
                $conn->table('audit_entries')->delete();
                cleanupAuthSmokeTenant();
            }
        } catch (\Throwable) {
            // Best-effort.
        }
        $this->tenantCtx = null;
        parent::tearDown();
    }

    private function migrator(): IamMigrator
    {
        return $this->app->make(IamMigrator::class);
    }

    /** Remove the catalog permission and its links to simulate a pre-existing
     * tenant provisioned before DEC-BI-IAM-001. */
    private function stripCatalog(): void
    {
        $conn = DB::connection('tenant');
        $permId = $conn->table('permissions')->where('name', IamMigrator::PERMISSION_BI_DASHBOARD_VIEW)->value('id');
        if ($permId !== null) {
            $conn->table('role_has_permissions')->where('permission_id', $permId)->delete();
            $conn->table('permissions')->where('id', $permId)->delete();
        }
    }

    private function permCount(): int
    {
        return (int) DB::connection('tenant')->table('permissions')
            ->where('name', IamMigrator::PERMISSION_BI_DASHBOARD_VIEW)->count();
    }

    private function adminLinkCount(): int
    {
        return (int) DB::connection('tenant')->table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.name', IamMigrator::ROLE_TENANT_ADMIN)
            ->where('permissions.name', IamMigrator::PERMISSION_BI_DASHBOARD_VIEW)
            ->count();
    }

    /** @test */
    public function company_admin_gets_bi_dashboard_view_on_provisioning_path(): void
    {
        $this->stripCatalog();

        // Full sanctioned IamMigrator path (same call TenantProvisioner makes).
        $this->migrator()->run($this->tenantCtx['tenant']);

        $this->assertSame(1, $this->permCount(), 'Catalog must contain exactly one bi.dashboard.view row');
        $this->assertSame(1, $this->adminLinkCount(), 'tenant_admin must be linked to the permission exactly once');

        // The company admin resolves the permission through the tenant_admin role.
        $admin = User::on('tenant')->where('email', $this->tenantCtx['user']->email)->first();
        $admin->assignRole(Role::on('tenant')->where('name', IamMigrator::ROLE_TENANT_ADMIN)->first());
        $this->assertTrue($admin->hasPermission(IamMigrator::PERMISSION_BI_DASHBOARD_VIEW));
    }

    /** @test */
    public function existing_tenant_is_backfilled_without_duplicates_on_rerun(): void
    {
        $this->stripCatalog();

        $migrator = $this->migrator();

        // First sanctioned re-run backfills the existing tenant.
        $migrator->run($this->tenantCtx['tenant']);
        $this->assertSame(1, $this->permCount());
        $this->assertSame(1, $this->adminLinkCount());

        // Re-running (provisioning retry / upgrade) must not duplicate anything.
        $migrator->run($this->tenantCtx['tenant']);
        $migrator->run($this->tenantCtx['tenant']);
        $this->assertSame(1, $this->permCount(), 'Re-run must not duplicate the permission row');
        $this->assertSame(1, $this->adminLinkCount(), 'Re-run must not duplicate the role link');
    }

    /** @test */
    public function employee_and_other_roles_never_receive_the_permission(): void
    {
        $this->stripCatalog();

        Role::on('tenant')->firstOrCreate(['name' => 'employee'], ['guard_name' => 'api']);

        $this->migrator()->run($this->tenantCtx['tenant']);

        $permId = DB::connection('tenant')->table('permissions')
            ->where('name', IamMigrator::PERMISSION_BI_DASHBOARD_VIEW)->value('id');

        $employeeLinks = (int) DB::connection('tenant')->table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->where('roles.name', 'employee')
            ->where('permission_id', $permId)
            ->count();
        $this->assertSame(0, $employeeLinks, 'employee role must never receive the catalog permission');

        $totalLinks = (int) DB::connection('tenant')->table('role_has_permissions')
            ->where('permission_id', $permId)->count();
        $this->assertSame(1, $totalLinks, 'Only tenant_admin may hold the catalog permission');
    }

    /** @test */
    public function direct_connection_seed_backfills_without_provisioning_context(): void
    {
        $this->stripCatalog();

        // The public injection point used by the local backfill tool: caller
        // owns the connection, no provisioning authorization involved.
        $this->migrator()->seedPermissionCatalogOnConnection('tenant');

        $this->assertSame(1, $this->permCount());
        $this->assertSame(1, $this->adminLinkCount());
    }
}
