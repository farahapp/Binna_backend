<?php

declare(strict_types=1);

namespace Tests\Unit\Commercial;

use App\Commercial\CommercialPolicy;
use App\Foundation\TenantContext;
use App\IAM\User;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * CommercialPolicyTest — CAP-012 Unit Tests (Record-level + Tenant Isolation).
 *
 * Tests:
 *  1. Admin can view a record from the same tenant.
 *  2. Customer can view a record from the same tenant.
 *  3. Any user CANNOT view a record from a different tenant (tenant isolation).
 *  4. Admin can manage Commercial records.
 *  5. Customer CANNOT manage Commercial records.
 *  6. Owner CANNOT manage Commercial records.
 *  7. Record without tenant_slug is accessible (not a tenant-scoped record).
 */
class CommercialPolicyTest extends TestCase
{
    private CommercialPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CommercialPolicy();

        // Reset the container between tests
        Container::setInstance(null);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeUserWithRole(string $roleName): User
    {
        $user = $this->createMock(User::class);
        $user->method('hasRole')
            ->willReturnCallback(fn (string $r) => $r === $roleName);
        $user->method('hasPermission')
            ->willReturn(false);
        return $user;
    }

    private function makeUserWithPermission(string $permissionName): User
    {
        $user = $this->createMock(User::class);
        $user->method('hasPermission')
            ->willReturnCallback(fn (string $p) => $p === $permissionName);
        $user->method('hasRole')
            ->willReturn(false);
        return $user;
    }

    /**
     * Build a simple object with a given tenant_slug (simulates a Commercial record).
     * Uses stdClass to avoid Eloquent mock complexity — the Policy only reads tenant_slug.
     */
    private function makeContract(?string $tenantSlug): object
    {
        $contract = new \stdClass();
        $contract->tenant_slug = $tenantSlug;
        return $contract;
    }

    /**
     * Bind a TenantContext into the container with the given slug.
     */
    private function bindTenantContext(string $slug): void
    {
        $tenantContext = new TenantContext(
            id: 1,
            slug: $slug,
            name: 'Test Tenant',
            schemaName: 'tenant_test',
            status: 'active',
        );

        $container = Container::getInstance();
        $container->instance(TenantContext::class, $tenantContext);
    }

    // -------------------------------------------------------------------------
    // view — allowed access
    // -------------------------------------------------------------------------

    /** @test */
    public function admin_can_view_record_from_same_tenant(): void
    {
        $this->bindTenantContext('tenant-alpha');
        $user = $this->makeUserWithRole('admin');
        $contract = $this->makeContract('tenant-alpha');

        $this->assertTrue($this->policy->view($user, $contract));
    }

    /** @test */
    public function customer_can_view_record_from_same_tenant(): void
    {
        $this->bindTenantContext('tenant-alpha');
        $user = $this->makeUserWithRole('customer');
        $contract = $this->makeContract('tenant-alpha');

        $this->assertTrue($this->policy->view($user, $contract));
    }

    /** @test */
    public function owner_can_view_record_from_same_tenant(): void
    {
        $this->bindTenantContext('tenant-alpha');
        $user = $this->makeUserWithRole('owner');
        $contract = $this->makeContract('tenant-alpha');

        $this->assertTrue($this->policy->view($user, $contract));
    }

    /** @test */
    public function user_with_view_permission_can_view_record(): void
    {
        $this->bindTenantContext('tenant-alpha');
        $user = $this->makeUserWithPermission('commercial.view');
        $contract = $this->makeContract('tenant-alpha');

        $this->assertTrue($this->policy->view($user, $contract));
    }

    // -------------------------------------------------------------------------
    // view — tenant isolation (PROHIBITED)
    // -------------------------------------------------------------------------

    /** @test */
    public function admin_cannot_view_record_from_different_tenant(): void
    {
        $this->bindTenantContext('tenant-alpha');
        $user = $this->makeUserWithRole('admin');
        $contract = $this->makeContract('tenant-beta');

        $this->assertFalse($this->policy->view($user, $contract));
    }

    /** @test */
    public function customer_cannot_view_record_from_different_tenant(): void
    {
        $this->bindTenantContext('tenant-alpha');
        $user = $this->makeUserWithRole('customer');
        $contract = $this->makeContract('tenant-beta');

        $this->assertFalse($this->policy->view($user, $contract));
    }

    /** @test */
    public function manager_cannot_view_record_from_different_tenant(): void
    {
        $this->bindTenantContext('tenant-alpha');
        $user = $this->makeUserWithRole('manager');
        $contract = $this->makeContract('tenant-beta');

        $this->assertFalse($this->policy->view($user, $contract));
    }

    // -------------------------------------------------------------------------
    // view — record without tenant_slug
    // -------------------------------------------------------------------------

    /** @test */
    public function record_without_tenant_slug_is_accessible(): void
    {
        $this->bindTenantContext('tenant-alpha');
        $user = $this->makeUserWithRole('admin');
        $contract = $this->makeContract(null);

        $this->assertTrue($this->policy->view($user, $contract));
    }

    // -------------------------------------------------------------------------
    // manage — allowed
    // -------------------------------------------------------------------------

    /** @test */
    public function admin_can_manage_commercial_records(): void
    {
        $user = $this->makeUserWithRole('admin');
        $this->assertTrue($this->policy->manage($user));
    }

    /** @test */
    public function manager_can_manage_commercial_records(): void
    {
        $user = $this->makeUserWithRole('manager');
        $this->assertTrue($this->policy->manage($user));
    }

    /** @test */
    public function quantity_surveyor_can_manage_commercial_records(): void
    {
        $user = $this->makeUserWithRole('quantity_surveyor');
        $this->assertTrue($this->policy->manage($user));
    }

    /** @test */
    public function user_with_manage_permission_can_manage(): void
    {
        $user = $this->makeUserWithPermission('commercial.manage');
        $this->assertTrue($this->policy->manage($user));
    }

    // -------------------------------------------------------------------------
    // manage — prohibited
    // -------------------------------------------------------------------------

    /** @test */
    public function customer_cannot_manage_commercial_records(): void
    {
        $user = $this->makeUserWithRole('customer');
        $this->assertFalse($this->policy->manage($user));
    }

    /** @test */
    public function owner_cannot_manage_commercial_records(): void
    {
        $user = $this->makeUserWithRole('owner');
        $this->assertFalse($this->policy->manage($user));
    }

    /** @test */
    public function client_cannot_manage_commercial_records(): void
    {
        $user = $this->makeUserWithRole('client');
        $this->assertFalse($this->policy->manage($user));
    }

    /** @test */
    public function unknown_role_cannot_manage_commercial_records(): void
    {
        $user = $this->makeUserWithRole('unknown_role');
        $this->assertFalse($this->policy->manage($user));
    }
}
