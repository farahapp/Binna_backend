<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant IAM migration — Track 2, Batch 2 (NEW-002).
 *
 * Documents the IAM tables that IamMigrator creates programmatically
 * in every new tenant schema. This file serves as the authoritative
 * schema reference and is NOT applied by `php artisan migrate` directly.
 *
 * Applied by: IamMigrator::run() — called from TenantProvisioner::provision()
 * after TenantBaselineMigrator (Batch 1) succeeds.
 *
 * Tables created:
 *   - users                  (UUID v4 PK — TODEC-003)
 *   - roles
 *   - permissions
 *   - model_has_roles        (user ↔ role pivot)
 *   - role_has_permissions   (role ↔ permission pivot)
 *   - personal_access_tokens (uuidMorphs — matches UUID v4 user IDs)
 *
 * Idempotency: IamMigrator guards each table with hasTable(), so re-running
 * provisioning on an existing schema is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── users ─────────────────────────────────────────────────────────────
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }

        // ── roles ─────────────────────────────────────────────────────────────
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('guard_name')->default('api');
                $table->timestamps();
            });
        }

        // ── permissions ───────────────────────────────────────────────────────
        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('guard_name')->default('api');
                $table->timestamps();
            });
        }

        // ── model_has_roles ───────────────────────────────────────────────────
        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->uuid('model_id');
                $table->primary(['role_id', 'model_id', 'model_type']);
                $table->index(['model_id', 'model_type']);
                $table->foreign('role_id')
                    ->references('id')->on('roles')
                    ->onDelete('cascade');
            });
        }

        // ── role_has_permissions ──────────────────────────────────────────────
        if (! Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
                $table->foreign('permission_id')
                    ->references('id')->on('permissions')
                    ->onDelete('cascade');
                $table->foreign('role_id')
                    ->references('id')->on('roles')
                    ->onDelete('cascade');
            });
        }

        // ── personal_access_tokens ────────────────────────────────────────────
        // uuidMorphs matches UUID v4 user IDs (TODEC-003).
        Schema::dropIfExists('personal_access_tokens');
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
    }
};
