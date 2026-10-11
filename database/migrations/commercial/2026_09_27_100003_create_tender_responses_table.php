<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TenderResponses migration — Per-Tenant Schema.
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant-local FK to tenders
            $table->uuid('tender_id')->index();
            $table->foreign('tender_id')->references('id')->on('tenders')->onDelete('cascade');

            $table->string('respondent_name', 255)->nullable();
            $table->string('respondent_email', 255)->nullable()->index();
            $table->timestamp('submission_date')->nullable();
            $table->string('status', 30)->default('RECEIVED')->index();

            $table->text('notes')->nullable();

            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_responses');
    }
};
