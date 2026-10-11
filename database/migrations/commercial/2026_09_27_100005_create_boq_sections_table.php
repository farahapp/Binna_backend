<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOQSections migration — Per-Tenant Schema.
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant-local FK to boqs
            $table->uuid('boq_id')->index();
            $table->foreign('boq_id')->references('id')->on('boqs')->onDelete('cascade');

            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_sections');
    }
};
