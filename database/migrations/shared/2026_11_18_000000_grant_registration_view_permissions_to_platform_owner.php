<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Restores view-only Platform Admin navigation for registration areas.
 *
 * The 2026_11_09 registration-permission migration created the permission
 * catalog rows but never attached them to the built-in platform-owner role.
 * This migration only adds the two view links needed to show the existing
 * pages; it grants no review, approval, mutation, or tenant access.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'platform.tenant_registrations.view',
        'platform.registration_representatives.view',
    ];

    public function up(): void
    {
        $ownerRoleId = DB::connection('mysql')->table('platform_roles')
            ->where('slug', 'platform-owner')
            ->value('id');

        if (! $ownerRoleId) {
            return;
        }

        foreach (self::PERMISSIONS as $permissionName) {
            $permissionId = DB::connection('mysql')->table('platform_permissions')
                ->where('name', $permissionName)
                ->value('id');

            if (! $permissionId) {
                continue;
            }

            DB::connection('mysql')->table('platform_role_permissions')->insertOrIgnore([
                'platform_role_id' => $ownerRoleId,
                'platform_permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $ownerRoleId = DB::connection('mysql')->table('platform_roles')
            ->where('slug', 'platform-owner')
            ->value('id');

        if (! $ownerRoleId) {
            return;
        }

        $permissionIds = DB::connection('mysql')->table('platform_permissions')
            ->whereIn('name', self::PERMISSIONS)
            ->pluck('id')
            ->all();

        if ($permissionIds === []) {
            return;
        }

        DB::connection('mysql')->table('platform_role_permissions')
            ->where('platform_role_id', $ownerRoleId)
            ->whereIn('platform_permission_id', $permissionIds)
            ->delete();
    }
};
