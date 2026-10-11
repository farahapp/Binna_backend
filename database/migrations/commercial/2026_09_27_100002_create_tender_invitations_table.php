<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TenderInvitations migration — Per-Tenant Schema.
 *
 * TASK-004A — Commercial Domain/Data Foundation
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant-local FK to tenders
            $table->uuid('tender_id')->index();
            $table->foreign('tender_id')->references('id')->on('tenders')->onDelete('cascade');

            $table->string('invitee_name', 255)->nullable();
            $table->string('invitee_email', 255)->nullable()->index();
            $table->string('invitee_type', 50)->nullable(); // supplier | partner | subcontractor
            $table->string('status', 30)->default('PENDING')->index();

            $table->timestamp('invited_at')->nullable();
            $table->timestamp('responded_at')->nullable();

            $table->text('notes')->nullable();

            $table->string('tenant_slug', 100)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_invitations');
    }
};
