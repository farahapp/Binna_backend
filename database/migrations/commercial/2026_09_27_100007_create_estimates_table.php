<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estimates migration — Per-Tenant Schema.
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant-local FK to tenders
            $table->uuid('tender_id')->nullable()->index();
            $table->foreign('tender_id')->references('id')->on('tenders')->onDelete('set null');

            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->decimal('total_amount', 15, 4)->default(0);
            $table->string('status', 30)->default('DRAFT')->index();

            // prepared_by: UUID reference to IAM User — no cross-schema FK
            $table->uuid('prepared_by')->nullable()->index();

            $table->text('notes')->nullable();

            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimates');
    }
};
