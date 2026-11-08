<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->create('platform_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::connection('mysql')->create('platform_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description');
            $table->timestamps();
        });

        Schema::connection('mysql')->create('platform_role_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('platform_role_id');
            $table->unsignedBigInteger('platform_permission_id');
            $table->primary(['platform_role_id', 'platform_permission_id']);
            $table->foreign('platform_role_id')
                ->references('id')
                ->on('platform_roles')
                ->cascadeOnDelete();
            $table->foreign('platform_permission_id')
                ->references('id')
                ->on('platform_permissions')
                ->cascadeOnDelete();
        });

        Schema::connection('mysql')->create('platform_user_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('platform_user_id');
            $table->unsignedBigInteger('platform_role_id');
            $table->primary(['platform_user_id', 'platform_role_id']);
            $table->foreign('platform_user_id')
                ->references('id')
                ->on('platform_users')
                ->cascadeOnDelete();
            $table->foreign('platform_role_id')
                ->references('id')
                ->on('platform_roles')
                ->cascadeOnDelete();
        });

        Schema::connection('mysql')->create('platform_audit_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('event', 100)->index();
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 100)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $now = now();
        DB::connection('mysql')->table('platform_permissions')->insert([
            ['name' => 'platform.users.view', 'description' => 'عرض مستخدمي المنصة', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'platform.users.create', 'description' => 'إنشاء مستخدمي المنصة', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'platform.users.update', 'description' => 'تعديل حالة وبيانات مستخدمي المنصة', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'platform.users.assign-roles', 'description' => 'إسناد الأدوار لمستخدمي المنصة', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'platform.roles.view', 'description' => 'عرض أدوار المنصة', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'platform.roles.create', 'description' => 'إنشاء أدوار المنصة', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'platform.roles.update', 'description' => 'تعديل أدوار المنصة', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'platform.roles.delete', 'description' => 'حذف أدوار المنصة غير المسندة', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'platform.roles.assign-permissions', 'description' => 'إسناد الصلاحيات إلى أدوار المنصة', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'platform.permissions.view', 'description' => 'عرض دليل صلاحيات المنصة', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'platform.tenants.view', 'description' => 'عرض المستأجرين وتفاصيلهم', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'platform.tenants.create', 'description' => 'إنشاء مستأجر', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'platform.tenants.update', 'description' => 'تعديل بيانات المستأجر ومعرّفاته', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'platform.tenants.suspend', 'description' => 'تعليق مستأجر نشط', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'platform.tenants.activate', 'description' => 'تفعيل مستأجر', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('platform_audit_entries');
        Schema::connection('mysql')->dropIfExists('platform_user_roles');
        Schema::connection('mysql')->dropIfExists('platform_role_permissions');
        Schema::connection('mysql')->dropIfExists('platform_permissions');
        Schema::connection('mysql')->dropIfExists('platform_roles');
    }
};
