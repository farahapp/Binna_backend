<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('mysql')->hasTable('platform_whatsapp_settings')) {
            Schema::connection('mysql')->create('platform_whatsapp_settings', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->boolean('enabled')->default(false);
                $table->boolean('otp_enabled')->default(false);
                $table->boolean('notifications_enabled')->default(false);
                $table->string('configured_sender_e164', 32)->nullable();
                $table->timestamps();
            });

            $now = now();
            DB::connection('mysql')->table('platform_whatsapp_settings')->insert([
                'id' => 1,
                'enabled' => false,
                'otp_enabled' => false,
                'notifications_enabled' => false,
                'configured_sender_e164' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! Schema::connection('mysql')->hasTable('platform_whatsapp_message_audit')) {
            Schema::connection('mysql')->create('platform_whatsapp_message_audit', function (Blueprint $table): void {
                $table->id();
                $table->string('message_id', 64)->nullable()->index();
                $table->string('template', 64);
                $table->string('destination_masked', 32)->nullable();
                $table->string('status', 32);
                $table->timestamp('created_at')->useCurrent();
            });
        }

        $now = now();
        $permission = 'platform.settings.whatsapp.manage';
        $exists = DB::connection('mysql')->table('platform_permissions')->where('name', $permission)->exists();
        if (! $exists) {
            DB::connection('mysql')->table('platform_permissions')->insert([
                'name' => $permission,
                'description' => 'إدارة ربط واتساب المنصة وإعدادات الإرسال',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $ownerRoleId = DB::connection('mysql')->table('platform_roles')->where('slug', 'platform-owner')->value('id');
        $permId = DB::connection('mysql')->table('platform_permissions')->where('name', $permission)->value('id');
        if ($ownerRoleId && $permId) {
            DB::connection('mysql')->table('platform_role_permissions')->insertOrIgnore([
                'platform_role_id' => $ownerRoleId,
                'platform_permission_id' => $permId,
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('platform_whatsapp_message_audit');
        Schema::connection('mysql')->dropIfExists('platform_whatsapp_settings');

        $permId = DB::connection('mysql')->table('platform_permissions')
            ->where('name', 'platform.settings.whatsapp.manage')
            ->value('id');
        if ($permId) {
            DB::connection('mysql')->table('platform_role_permissions')
                ->where('platform_permission_id', $permId)
                ->delete();
            DB::connection('mysql')->table('platform_permissions')
                ->where('id', $permId)
                ->delete();
        }
    }
};
