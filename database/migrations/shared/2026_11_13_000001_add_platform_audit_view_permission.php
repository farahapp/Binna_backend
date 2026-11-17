<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        $now = now();
        DB::connection('mysql')->table('platform_permissions')->insertOrIgnore([
            'name' => 'platform.audit.view',
            'description' => 'عرض سجل تدقيق المنصة',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::connection('mysql')->table('platform_permissions')
            ->where('name', 'platform.audit.view')
            ->value('id');
        $ownerRoleId = DB::connection('mysql')->table('platform_roles')
            ->where('slug', 'platform-owner')
            ->value('id');

        if ($permissionId !== null && $ownerRoleId !== null) {
            DB::connection('mysql')->table('platform_role_permissions')->insertOrIgnore([
                'platform_role_id' => $ownerRoleId,
                'platform_permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::connection('mysql')->table('platform_permissions')
            ->where('name', 'platform.audit.view')
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::connection('mysql')->table('platform_role_permissions')
            ->where('platform_permission_id', $permissionId)
            ->delete();
        DB::connection('mysql')->table('platform_permissions')
            ->where('id', $permissionId)
            ->delete();
    }
};
