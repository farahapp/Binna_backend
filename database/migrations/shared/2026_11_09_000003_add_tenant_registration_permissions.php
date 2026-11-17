<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mysql';

    private const PERMISSIONS = [
        'platform.tenant_registrations.view' => 'عرض طلبات تسجيل المستأجرين',
        'platform.tenant_registrations.review' => 'مراجعة قرارات طلبات تسجيل المستأجرين',
        'platform.tenant_registrations.identifiers.view' => 'عرض أرقام CR وQID في طلبات التسجيل',
        'platform.tenant_registrations.assign-representatives' => 'إسناد طلبات التسجيل إلى المندوبين',
        'platform.registration_representatives.view' => 'عرض هويات المندوبين',
        'platform.registration_representatives.create' => 'إضافة هويات المندوبين',
        'platform.registration_representatives.approve' => 'اعتماد أو إعادة تفعيل هويات المندوبين',
        'platform.registration_representatives.suspend' => 'إيقاف هويات المندوبين',
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $name => $description) {
            DB::connection('mysql')->table('platform_permissions')->insertOrIgnore([
                'name' => $name,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('mysql')->table('platform_role_permissions')
            ->whereIn('platform_permission_id', function ($query): void {
                $query->select('id')
                    ->from('platform_permissions')
                    ->whereIn('name', array_keys(self::PERMISSIONS));
            })
            ->delete();

        DB::connection('mysql')->table('platform_permissions')
            ->whereIn('name', array_keys(self::PERMISSIONS))
            ->delete();
    }
};
