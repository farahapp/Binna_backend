<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotations migration — Per-Tenant Schema.
 *
 * Lifecycle: DRAFT | UNDER_REVIEW | APPROVED | SUBMITTED | ACCEPTED | REJECTED | EXPIRED
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('quotation_number', 100)->nullable()->index();

            // Tenant-local FK to tenders
            $table->uuid('tender_id')->nullable()->index();
            $table->foreign('tender_id')->references('id')->on('tenders')->onDelete('set null');

            // Tenant-local FK to boqs
            $table->uuid('boq_id')->nullable()->index();
            $table->foreign('boq_id')->references('id')->on('boqs')->onDelete('set null');

            // CRM Client reference — UUID only, no cross-schema FK
            $table->uuid('client_id')->nullable()->index();

            $table->string('title', 255)->nullable();
            $table->decimal('total_value', 15, 4)->default(0);
            $table->string('status', 30)->default('DRAFT')->index();

            $table->date('quotation_date')->nullable();
            $table->date('validity_date')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();

            $table->text('notes')->nullable();

            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
