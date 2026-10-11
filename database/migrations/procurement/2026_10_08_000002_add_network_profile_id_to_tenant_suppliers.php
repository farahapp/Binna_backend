<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add network_profile_id (nullable) to tenant_suppliers.
 *
 * NETWORK-001 — OD-005 Dual Identity + Optional Link
 *
 * Architectural constraints:
 *  - network_profile_id is nullable — linking is optional, never automatic.
 *  - No FK constraint — cross-schema coupling is prohibited (OD-001).
 *  - ERP Supplier does NOT automatically become a Network Profile.
 *  - Network Profile does NOT automatically become an ERP Supplier.
 *  - This migration runs on the tenant connection (per-tenant schema).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('tenant_suppliers', function (Blueprint $table) {
            // Nullable UUID reference to a Network Profile (cross-schema, no FK).
            $table->uuid('network_profile_id')->nullable()->after('is_active');
            $table->index('network_profile_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('tenant_suppliers', function (Blueprint $table) {
            $table->dropIndex(['network_profile_id']);
            $table->dropColumn('network_profile_id');
        });
    }
};
