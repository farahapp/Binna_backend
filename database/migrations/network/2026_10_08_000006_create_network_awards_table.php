<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NETWORK-005 — Award
 *
 * Creates the `network_awards` table on the global `mysql` connection.
 *
 * Constraints:
 *  - One Award per Tender (unique on tender_id).
 *  - References NetworkTender, NetworkBid, NetworkProfile — no cross-schema FK.
 *  - Global Network schema only — no tenant schema modification.
 */
return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->create('network_awards', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tender reference — unique: one Award per Tender
            $table->uuid('tender_id')->unique();

            // Winning Bid reference
            $table->uuid('winning_bid_id');

            // Winning contractor Network Profile reference
            $table->uuid('winning_network_profile_id');

            // Actor who issued the Award
            $table->uuid('awarded_by');

            // Server-authoritative Award timestamp
            $table->timestamp('awarded_at')->useCurrent();

            // Optional reason/notes
            $table->text('reason')->nullable();

            // Tenant context (managed_by_tenant_slug — consistent with NETWORK-001/002/003/004)
            $table->string('managed_by_tenant_slug')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('network_awards');
    }
};
