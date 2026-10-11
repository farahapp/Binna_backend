<?php

declare(strict_types=1);

namespace Tests\Unit\Commercial;

use App\Commercial\CommercialVisibilityService;
use App\IAM\User;
use PHPUnit\Framework\TestCase;

/**
 * CAP012RegressionTest — Regression tests for CAP-012.
 *
 * Verifies that:
 *  1. CommercialVisibilityService does NOT break existing data for internal users.
 *  2. All non-restricted fields pass through unchanged.
 *  3. Empty data arrays are handled gracefully.
 *  4. Data arrays with no restricted fields are returned unchanged.
 *  5. filterCollection with empty array returns empty array.
 *  6. All internal roles (admin, manager, etc.) retain full access.
 *  7. All external roles (customer, owner, client) are restricted.
 */
class CAP012RegressionTest extends TestCase
{
    private CommercialVisibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CommercialVisibilityService();
    }

    private function makeUserWithRole(string $roleName): User
    {
        $user = $this->createMock(User::class);
        $user->method('hasRole')
            ->willReturnCallback(fn (string $r) => $r === $roleName);
        $user->method('hasPermission')
            ->willReturn(false);
        return $user;
    }

    // -------------------------------------------------------------------------
    // Regression: existing data not broken for internal users
    // -------------------------------------------------------------------------

    /** @test */
    public function internal_user_data_is_not_modified(): void
    {
        $user = $this->makeUserWithRole('admin');

        $data = [
            'id'             => 'uuid-001',
            'contract_value' => '500000.00',
            'status'         => 'ACTIVE',
            'purchase_price' => '350000.00',
            'profit_margin'  => '150000.00',
        ];

        $result = $this->service->filterFields($data, $user);

        // Data must be identical — no modification for internal users
        $this->assertSame($data, $result);
    }

    /** @test */
    public function empty_data_array_returns_empty_for_customer(): void
    {
        $user = $this->makeUserWithRole('customer');
        $result = $this->service->filterFields([], $user);
        $this->assertSame([], $result);
    }

    /** @test */
    public function empty_data_array_returns_empty_for_admin(): void
    {
        $user = $this->makeUserWithRole('admin');
        $result = $this->service->filterFields([], $user);
        $this->assertSame([], $result);
    }

    /** @test */
    public function data_with_no_restricted_fields_unchanged_for_customer(): void
    {
        $user = $this->makeUserWithRole('customer');

        $data = [
            'id'              => 'uuid-001',
            'contract_number' => 'C-2024-001',
            'contract_value'  => '500000.00',
            'status'          => 'ACTIVE',
        ];

        $result = $this->service->filterFields($data, $user);

        $this->assertSame($data, $result);
    }

    /** @test */
    public function filter_collection_with_empty_array_returns_empty(): void
    {
        $user = $this->makeUserWithRole('customer');
        $result = $this->service->filterCollection([], $user);
        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // Regression: all internal roles retain full access
    // -------------------------------------------------------------------------

    /**
     * @test
     * @dataProvider internalRolesProvider
     */
    public function internal_role_retains_full_access(string $role): void
    {
        $user = $this->makeUserWithRole($role);

        $data = [
            'id'             => 'uuid-001',
            'purchase_price' => '350000.00',
            'profit_margin'  => '150000.00',
            'internal_cost'  => '320000.00',
        ];

        $result = $this->service->filterFields($data, $user);

        $this->assertArrayHasKey('purchase_price', $result, "Role [{$role}] must see purchase_price");
        $this->assertArrayHasKey('profit_margin', $result, "Role [{$role}] must see profit_margin");
        $this->assertArrayHasKey('internal_cost', $result, "Role [{$role}] must see internal_cost");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function internalRolesProvider(): array
    {
        return [
            'admin'             => ['admin'],
            'manager'           => ['manager'],
            'internal_user'     => ['internal_user'],
            'finance_user'      => ['finance_user'],
            'cost_controller'   => ['cost_controller'],
            'quantity_surveyor' => ['quantity_surveyor'],
            'project_manager'   => ['project_manager'],
            'super_admin'       => ['super_admin'],
        ];
    }

    // -------------------------------------------------------------------------
    // Regression: all external roles are restricted
    // -------------------------------------------------------------------------

    /**
     * @test
     * @dataProvider externalRolesProvider
     */
    public function external_role_cannot_see_restricted_fields(string $role): void
    {
        $user = $this->makeUserWithRole($role);

        $data = [
            'id'             => 'uuid-001',
            'purchase_price' => '350000.00',
            'profit_margin'  => '150000.00',
            'internal_cost'  => '320000.00',
        ];

        $result = $this->service->filterFields($data, $user);

        $this->assertArrayNotHasKey('purchase_price', $result, "Role [{$role}] must NOT see purchase_price");
        $this->assertArrayNotHasKey('profit_margin', $result, "Role [{$role}] must NOT see profit_margin");
        $this->assertArrayNotHasKey('internal_cost', $result, "Role [{$role}] must NOT see internal_cost");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function externalRolesProvider(): array
    {
        return [
            'customer' => ['customer'],
            'owner'    => ['owner'],
            'client'   => ['client'],
        ];
    }

    // -------------------------------------------------------------------------
    // Regression: unknown role is treated as external (restricted)
    // -------------------------------------------------------------------------

    /** @test */
    public function unknown_role_is_treated_as_external(): void
    {
        $user = $this->makeUserWithRole('unknown_role_xyz');

        $data = [
            'id'             => 'uuid-001',
            'purchase_price' => '350000.00',
            'profit_margin'  => '150000.00',
        ];

        $result = $this->service->filterFields($data, $user);

        $this->assertArrayNotHasKey('purchase_price', $result);
        $this->assertArrayNotHasKey('profit_margin', $result);
        $this->assertArrayHasKey('id', $result);
    }
}
