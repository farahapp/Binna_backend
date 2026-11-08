<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        DB::connection('mysql')->table('platform_permissions')->insertOrIgnore([
            'name' => 'platform.tenants.identifiers.approve',
            'description' => 'اعتماد معرّف تسجيل دخول بعد مراجعته',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $permissionId = DB::connection('mysql')->table('platform_permissions')
            ->where('name', 'platform.tenants.identifiers.approve')
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
