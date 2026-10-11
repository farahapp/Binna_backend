<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create network_profiles table — global (shared) schema.
 *
 * NETWORK-001 — Network Foundation & Profile
 *
 * Architectural constraints (NETWORK-ARCH-001):
 *  - Network Profile is a cross-tenant entity — lives in the global (mysql) schema.
 *  - NOT in any tenant schema — OD-001 Schema-per-Tenant preserved.
 *  - No direct cross-schema FK to tenant tables.
 *  - OD-005: Dual Identity + Optional Link.
 */
return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->create('network_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('company_name', 255);

            $table->enum('profile_type', [
                'owner',
                'engineer',
                'contractor',
                'main_contractor',
                'subcontractor',
                'supplier',
            ]);

            $table->enum('status', [
                'draft',
                'active',
                'suspended',
                'deactivated',
            ])->default('draft');

            $table->string('region', 100)->nullable();
            $table->json('specializations')->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('website', 255)->nullable();
            $table->text('description')->nullable();

            // Which tenant manages/owns this profile (if any).
            // Nullable — a profile may exist independently of any ERP tenant.
            $table->string('managed_by_tenant_slug', 100)->nullable();
            $table->index('managed_by_tenant_slug');

            $table->index('profile_type');
            $table->index('status');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('network_profiles');
    }
};
