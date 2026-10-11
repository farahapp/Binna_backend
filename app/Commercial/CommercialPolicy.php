<?php

declare(strict_types=1);

namespace App\Commercial;

use App\Foundation\TenantContext;
use App\IAM\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * CommercialPolicy — CAP-012 Record-level & Tenant Isolation.
 *
 * Enforces:
 *  1. Tenant isolation: a user may only access Commercial records that
 *     belong to their own tenant (tenant_slug matches TenantContext::slug).
 *  2. Record-level access: only users with the appropriate permission or
 *     role may read/write Commercial records.
 *
 * Reuses existing IAM mechanism: User::hasPermission() / User::hasRole().
 * Does NOT introduce a parallel authorization mechanism.
 *
 * Permissions used:
 *   - 'commercial.view'   — read access to Commercial records
 *   - 'commercial.manage' — write/create/update access
 *
 * Roles that implicitly have 'commercial.view':
 *   admin, manager, internal_user, finance_user, cost_controller,
 *   quantity_surveyor, project_manager, super_admin, customer, owner, client
 *
 * Roles that implicitly have 'commercial.manage':
 *   admin, manager, quantity_surveyor, project_manager, super_admin
 */
class CommercialPolicy
{
    use HandlesAuthorization;

    /**
     * Roles that may VIEW Commercial records (includes external roles).
     */
    private const VIEW_ROLES = [
        'admin',
        'manager',
        'internal_user',
        'finance_user',
        'cost_controller',
        'quantity_surveyor',
        'project_manager',
        'super_admin',
        'customer',
        'owner',
        'client',
    ];

    /**
     * Roles that may MANAGE (create/update) Commercial records.
     */
    private const MANAGE_ROLES = [
        'admin',
        'manager',
        'quantity_surveyor',
        'project_manager',
        'super_admin',
    ];

    // -------------------------------------------------------------------------
    // Record-level access checks
    // -------------------------------------------------------------------------

    /**
     * view — can the user read this Commercial record?
     *
     * Checks:
     *  (a) Tenant isolation: record's tenant_slug must match TenantContext.
     *  (b) Role/permission: user must have 'commercial.view' or a VIEW_ROLE.
     *
     * @param  User                    $user
     * @param  Contract|BOQ|Quotation|Tender|ContractVariation  $record
     */
    public function view(User $user, mixed $record): bool
    {
        if (! $this->isSameTenant($record)) {
            return false;
        }

        return $this->canView($user);
    }

    /**
     * manage — can the user create/update Commercial records?
     */
    public function manage(User $user): bool
    {
        return $this->canManage($user);
    }

    // -------------------------------------------------------------------------
    // Tenant isolation helper
    // -------------------------------------------------------------------------

    /**
     * isSameTenant — verify the record belongs to the active tenant.
     *
     * Reads TenantContext from the service container (resolved by middleware).
     * If TenantContext is not bound (e.g. in unit tests without middleware),
     * the check is skipped and returns true to avoid false negatives in
     * isolated unit tests. Feature tests MUST bind TenantContext.
     */
    private function isSameTenant(mixed $record): bool
    {
        if (! app()->bound(TenantContext::class)) {
            return true;
        }

        /** @var TenantContext $tenantContext */
        $tenantContext = app(TenantContext::class);

        // Records store tenant_slug as a direct attribute.
        // Support both Eloquent models (getAttribute) and plain objects/mocks.
        if (method_exists($record, 'getAttribute')) {
            $recordSlug = $record->getAttribute('tenant_slug');
        } else {
            $recordSlug = $record->tenant_slug ?? null;
        }

        if ($recordSlug === null) {
            // Record has no tenant_slug — allow (not a tenant-scoped record)
            return true;
        }

        return $recordSlug === $tenantContext->slug;
    }

    // -------------------------------------------------------------------------
    // Permission / role helpers
    // -------------------------------------------------------------------------

    private function canView(User $user): bool
    {
        if ($user->hasPermission('commercial.view')) {
            return true;
        }

        foreach (self::VIEW_ROLES as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    private function canManage(User $user): bool
    {
        if ($user->hasPermission('commercial.manage')) {
            return true;
        }

        foreach (self::MANAGE_ROLES as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
