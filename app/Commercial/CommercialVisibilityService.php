<?php

declare(strict_types=1);

namespace App\Commercial;

use App\IAM\User;

/**
 * CommercialVisibilityService — CAP-012 Field-level Visibility.
 *
 * Enforces the following rules (CAP-012 / C-001):
 *
 *  Rule 1: Customer / Owner MUST NOT see internal purchase prices.
 *  Rule 2: Customer / Owner MUST NOT see profit margins.
 *  Rule 3: Customer / Owner MUST NOT see internal costs.
 *
 * Authority: Backend is the sole authority. Flutter/UI MUST NOT be relied upon
 * to hide these fields.
 *
 * Reuses existing IAM mechanism: User::hasPermission() / User::hasRole().
 * Does NOT introduce a parallel authorization mechanism.
 *
 * Roles that are considered "internal" (can see all fields):
 *   - admin
 *   - manager
 *   - internal_user
 *   - finance_user
 *   - cost_controller
 *
 * Roles that are considered "external" (restricted — Customer/Owner):
 *   - customer
 *   - owner
 *   - client
 *
 * Permission-based override: 'commercial.view_internal_prices'
 */
class CommercialVisibilityService
{
    /**
     * Internal fields that must be hidden from Customer/Owner roles.
     * These map to array keys returned by model->toArray().
     */
    private const RESTRICTED_FIELDS = [
        // Purchase / cost fields
        'purchase_price',
        'unit_purchase_price',
        'internal_cost',
        'internal_unit_cost',
        'total_internal_cost',
        'cost_price',
        'supplier_price',
        'subcontractor_price',

        // Profit / margin fields
        'profit_margin',
        'profit_margin_percent',
        'margin',
        'margin_percent',
        'gross_profit',
        'net_profit',
        'profit',

        // Internal cost breakdown
        'overhead_cost',
        'labour_cost_internal',
        'equipment_cost_internal',
        'material_cost_internal',
        'contingency_internal',
    ];

    /**
     * Roles that grant full visibility (internal users).
     */
    private const INTERNAL_ROLES = [
        'admin',
        'manager',
        'internal_user',
        'finance_user',
        'cost_controller',
        'quantity_surveyor',
        'project_manager',
        'super_admin',
    ];

    /**
     * Permission that explicitly grants access to internal pricing fields.
     */
    private const PERMISSION_VIEW_INTERNAL = 'commercial.view_internal_prices';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * canViewInternalFields — determine if the given user may see restricted fields.
     *
     * Returns true if the user:
     *   (a) has the explicit permission 'commercial.view_internal_prices', OR
     *   (b) holds an internal role.
     *
     * Returns false for Customer / Owner / Client roles (or any unrecognised role).
     */
    public function canViewInternalFields(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        // Explicit permission overrides everything
        if ($user->hasPermission(self::PERMISSION_VIEW_INTERNAL)) {
            return true;
        }

        // Check internal roles
        foreach (self::INTERNAL_ROLES as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * filterFields — strip restricted fields from a data array if the user
     * is not authorised to see them.
     *
     * @param  array<string, mixed>  $data   The raw model->toArray() output.
     * @param  User|null             $user   The authenticated user (null = unauthenticated).
     * @return array<string, mixed>          The filtered data safe to return to the caller.
     */
    public function filterFields(array $data, ?User $user): array
    {
        if ($this->canViewInternalFields($user)) {
            return $data;
        }

        return array_diff_key($data, array_flip(self::RESTRICTED_FIELDS));
    }

    /**
     * filterCollection — apply filterFields to each item in a collection array.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  User|null                         $user
     * @return array<int, array<string, mixed>>
     */
    public function filterCollection(array $items, ?User $user): array
    {
        if ($this->canViewInternalFields($user)) {
            return $items;
        }

        return array_map(
            fn (array $item) => $this->filterFields($item, $user),
            $items
        );
    }

    /**
     * getRestrictedFields — expose the list of restricted fields (for testing).
     *
     * @return string[]
     */
    public function getRestrictedFields(): array
    {
        return self::RESTRICTED_FIELDS;
    }
}
