<?php

declare(strict_types=1);

namespace Tests\Unit\Commercial;

use App\Commercial\CommercialVisibilityService;
use App\IAM\User;
use PHPUnit\Framework\TestCase;

/**
 * CommercialVisibilityServiceTest — CAP-012 Unit Tests (Field-level Visibility).
 *
 * Tests:
 *  1. Internal user (admin role) can see all fields.
 *  2. Customer role cannot see restricted fields.
 *  3. Owner role cannot see restricted fields.
 *  4. Client role cannot see restricted fields.
 *  5. User with explicit permission 'commercial.view_internal_prices' can see all fields.
 *  6. Null user (unauthenticated) cannot see restricted fields.
 *  7. filterCollection applies filtering to each item.
 *  8. Non-restricted fields are always visible.
 *  9. canViewInternalFields returns correct boolean.
 */
class CommercialVisibilityServiceTest extends TestCase
{
    private CommercialVisibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CommercialVisibilityService();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a mock User with the given role name.
     * Uses PHPUnit mock — no database required.
     */
    private function makeUserWithRole(string $roleName): User
    {
        $user = $this->createMock(User::class);

        $user->method('hasRole')
            ->willReturnCallback(fn (string $r) => $r === $roleName);

        $user->method('hasPermission')
            ->willReturn(false);

        return $user;
    }

    /**
     * Build a mock User with the given permission.
     */
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
     * Sample data array that contains both restricted and non-restricted fields.
     *
     * @return array<string, mixed>
     */
    private function sampleData(): array
    {
        return [
            'id'                  => 'uuid-001',
            'contract_number'     => 'C-2024-001',
            'contract_value'      => '500000.00',
            'status'              => 'ACTIVE',
            'client_id'           => 'client-uuid',
            // Restricted fields
            'purchase_price'      => '350000.00',
            'profit_margin'       => '150000.00',
            'profit_margin_percent' => '30.00',
            'internal_cost'       => '320000.00',
            'overhead_cost'       => '30000.00',
            'gross_profit'        => '180000.00',
        ];
    }

    // -------------------------------------------------------------------------
    // canViewInternalFields
    // -------------------------------------------------------------------------

    /** @test */
    public function admin_role_can_view_internal_fields(): void
    {
        $user = $this->makeUserWithRole('admin');
        $this->assertTrue($this->service->canViewInternalFields($user));
    }

    /** @test */
    public function manager_role_can_view_internal_fields(): void
    {
        $user = $this->makeUserWithRole('manager');
        $this->assertTrue($this->service->canViewInternalFields($user));
    }

    /** @test */
    public function customer_role_cannot_view_internal_fields(): void
    {
        $user = $this->makeUserWithRole('customer');
        $this->assertFalse($this->service->canViewInternalFields($user));
    }

    /** @test */
    public function owner_role_cannot_view_internal_fields(): void
    {
        $user = $this->makeUserWithRole('owner');
        $this->assertFalse($this->service->canViewInternalFields($user));
    }

    /** @test */
    public function client_role_cannot_view_internal_fields(): void
    {
        $user = $this->makeUserWithRole('client');
        $this->assertFalse($this->service->canViewInternalFields($user));
    }

    /** @test */
    public function null_user_cannot_view_internal_fields(): void
    {
        $this->assertFalse($this->service->canViewInternalFields(null));
    }

    /** @test */
    public function explicit_permission_grants_internal_field_access(): void
    {
        $user = $this->makeUserWithPermission('commercial.view_internal_prices');
        $this->assertTrue($this->service->canViewInternalFields($user));
    }

    // -------------------------------------------------------------------------
    // filterFields — allowed access
    // -------------------------------------------------------------------------

    /** @test */
    public function admin_receives_all_fields(): void
    {
        $user = $this->makeUserWithRole('admin');
        $data = $this->sampleData();

        $result = $this->service->filterFields($data, $user);

        $this->assertArrayHasKey('purchase_price', $result);
        $this->assertArrayHasKey('profit_margin', $result);
        $this->assertArrayHasKey('internal_cost', $result);
        $this->assertArrayHasKey('overhead_cost', $result);
        $this->assertArrayHasKey('gross_profit', $result);
    }

    /** @test */
    public function internal_user_receives_all_fields(): void
    {
        $user = $this->makeUserWithRole('internal_user');
        $data = $this->sampleData();

        $result = $this->service->filterFields($data, $user);

        $this->assertArrayHasKey('purchase_price', $result);
        $this->assertArrayHasKey('profit_margin', $result);
    }

    // -------------------------------------------------------------------------
    // filterFields — prohibited internal fields
    // -------------------------------------------------------------------------

    /** @test */
    public function customer_does_not_receive_purchase_price(): void
    {
        $user = $this->makeUserWithRole('customer');
        $data = $this->sampleData();

        $result = $this->service->filterFields($data, $user);

        $this->assertArrayNotHasKey('purchase_price', $result);
    }

    /** @test */
    public function customer_does_not_receive_profit_margin(): void
    {
        $user = $this->makeUserWithRole('customer');
        $data = $this->sampleData();

        $result = $this->service->filterFields($data, $user);

        $this->assertArrayNotHasKey('profit_margin', $result);
        $this->assertArrayNotHasKey('profit_margin_percent', $result);
    }

    /** @test */
    public function customer_does_not_receive_internal_cost(): void
    {
        $user = $this->makeUserWithRole('customer');
        $data = $this->sampleData();

        $result = $this->service->filterFields($data, $user);

        $this->assertArrayNotHasKey('internal_cost', $result);
        $this->assertArrayNotHasKey('overhead_cost', $result);
    }

    /** @test */
    public function owner_does_not_receive_restricted_fields(): void
    {
        $user = $this->makeUserWithRole('owner');
        $data = $this->sampleData();

        $result = $this->service->filterFields($data, $user);

        $this->assertArrayNotHasKey('purchase_price', $result);
        $this->assertArrayNotHasKey('profit_margin', $result);
        $this->assertArrayNotHasKey('internal_cost', $result);
        $this->assertArrayNotHasKey('gross_profit', $result);
    }

    /** @test */
    public function null_user_does_not_receive_restricted_fields(): void
    {
        $data = $this->sampleData();

        $result = $this->service->filterFields($data, null);

        $this->assertArrayNotHasKey('purchase_price', $result);
        $this->assertArrayNotHasKey('profit_margin', $result);
        $this->assertArrayNotHasKey('internal_cost', $result);
    }

    // -------------------------------------------------------------------------
    // filterFields — non-restricted fields always visible
    // -------------------------------------------------------------------------

    /** @test */
    public function non_restricted_fields_always_visible_for_customer(): void
    {
        $user = $this->makeUserWithRole('customer');
        $data = $this->sampleData();

        $result = $this->service->filterFields($data, $user);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('contract_number', $result);
        $this->assertArrayHasKey('contract_value', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('client_id', $result);
    }

    // -------------------------------------------------------------------------
    // filterCollection
    // -------------------------------------------------------------------------

    /** @test */
    public function filter_collection_strips_restricted_fields_from_each_item(): void
    {
        $user = $this->makeUserWithRole('customer');

        $items = [
            $this->sampleData(),
            array_merge($this->sampleData(), ['id' => 'uuid-002', 'contract_number' => 'C-2024-002']),
        ];

        $result = $this->service->filterCollection($items, $user);

        $this->assertCount(2, $result);

        foreach ($result as $item) {
            $this->assertArrayNotHasKey('purchase_price', $item);
            $this->assertArrayNotHasKey('profit_margin', $item);
            $this->assertArrayNotHasKey('internal_cost', $item);
            $this->assertArrayHasKey('contract_number', $item);
        }
    }

    /** @test */
    public function filter_collection_returns_all_fields_for_admin(): void
    {
        $user = $this->makeUserWithRole('admin');

        $items = [$this->sampleData()];

        $result = $this->service->filterCollection($items, $user);

        $this->assertArrayHasKey('purchase_price', $result[0]);
        $this->assertArrayHasKey('profit_margin', $result[0]);
    }

    // -------------------------------------------------------------------------
    // getRestrictedFields
    // -------------------------------------------------------------------------

    /** @test */
    public function get_restricted_fields_returns_non_empty_array(): void
    {
        $fields = $this->service->getRestrictedFields();

        $this->assertIsArray($fields);
        $this->assertNotEmpty($fields);
        $this->assertContains('purchase_price', $fields);
        $this->assertContains('profit_margin', $fields);
        $this->assertContains('internal_cost', $fields);
    }
}
