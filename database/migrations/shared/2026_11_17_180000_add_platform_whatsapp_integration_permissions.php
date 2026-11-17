<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mysql';

    /** @var list<string> */
    private array $permissions = [
        'platform.integrations.whatsapp.view' => 'مراقبة حالات حسابات واتساب المنصة',
        'platform.integrations.whatsapp.manage' => 'إدارة ربط وإعادة اتصال حسابات واتساب المنصة',
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->permissions as $name => $description) {
            DB::connection('mysql')->table('platform_permissions')->insertOrIgnore([
                'name' => $name,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Any role that could already manage WhatsApp keeps equivalent access.
        $roleIds = DB::connection('mysql')->table('platform_role_permissions as rp')
            ->join('platform_permissions as p', 'p.id', '=', 'rp.platform_permission_id')
            ->where('p.name', 'platform.settings.whatsapp.manage')
            ->pluck('rp.platform_role_id')
            ->all();

        $ownerRoleId = DB::connection('mysql')->table('platform_roles')
            ->where('slug', 'platform-owner')
            ->value('id');
        if ($ownerRoleId !== null) {
            $roleIds[] = $ownerRoleId;
        }

        foreach (array_unique($roleIds) as $roleId) {
            foreach (array_keys($this->permissions) as $name) {
                $permissionId = DB::connection('mysql')->table('platform_permissions')
                    ->where('name', $name)
                    ->value('id');
                if ($permissionId === null) {
                    continue;
                }
                DB::connection('mysql')->table('platform_role_permissions')->insertOrIgnore([
                    'platform_role_id' => $roleId,
                    'platform_permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->permissions) as $name) {
            $permissionId = DB::connection('mysql')->table('platform_permissions')
                ->where('name', $name)
                ->value('id');
            if ($permissionId === null) {
                continue;
            }
            DB::connection('mysql')->table('platform_role_permissions')
                ->where('platform_permission_id', $permissionId)
                ->delete();
            DB::connection('mysql')->table('platform_permissions')
                ->where('id', $permissionId)
                ->delete();
        }
    }
};
