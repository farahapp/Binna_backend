<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOQs migration — Per-Tenant Schema.
 *
 * Supports three BOQ types: tender_boq | contract_boq | variation_boq (PHASE 6 §10.2).
 * tender_id, contract_id, variation_id are nullable — only one is set per BOQ context.
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boqs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // BOQ type: tender_boq | contract_boq | variation_boq
            $table->string('type', 30)->index();

            // Context references — nullable, only one set per BOQ
            // tender_id: tenant-local FK (tenders exists at this point)
            $table->uuid('tender_id')->nullable()->index();
            $table->foreign('tender_id')->references('id')->on('tenders')->onDelete('set null');

            // contract_id and variation_id: will be set after those tables exist
            // Stored as UUID strings — FK constraints added after contracts/variations tables
            $table->uuid('contract_id')->nullable()->index();
            $table->uuid('variation_id')->nullable()->index();

            $table->string('title', 255)->nullable();
            $table->string('status', 30)->default('DRAFT')->index();
            $table->text('notes')->nullable();

            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boqs');
    }
};
