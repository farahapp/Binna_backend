<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant baseline migration — Track 2 (NEW-002).
 *
 * This is the first migration applied to every new tenant schema.
 * It creates the schema_info marker table that confirms the schema
 * was initialised correctly.
 *
 * IAM tables (users, roles, permissions, personal_access_tokens) will be
 * added in TASK-001B as per the dependency order: Foundation → IAM → Audit.
 *
 * This migration is applied by TenantBaselineMigrator, not by
 * `php artisan migrate` directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('schema_info')) {
            Schema::create('schema_info', function (Blueprint $table) {
                $table->id();
                $table->string('key', 100)->unique();
                $table->string('value', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('schema_info');
    }
};
