<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOQItems migration — Per-Tenant Schema.
 *
 * Fields per PHASE 6 §10.3: description, unit, quantity, unit_rate, amount, wbs_reference.
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant-local FK to boq_sections
            $table->uuid('boq_section_id')->index();
            $table->foreign('boq_section_id')->references('id')->on('boq_sections')->onDelete('cascade');

            $table->text('description');
            $table->string('unit', 50)->nullable();          // Unit of Measure
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('unit_rate', 15, 4)->default(0);
            $table->decimal('amount', 15, 4)->default(0);    // quantity × rate

            // WBS reference — nullable UUID, no cross-schema FK (Projects not yet implemented)
            $table->string('wbs_reference', 100)->nullable()->index();

            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();

            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_items');
    }
};
