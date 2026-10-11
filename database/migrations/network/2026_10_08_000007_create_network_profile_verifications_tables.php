<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->create('network_profile_verifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('network_profile_id')->unique();
            $table->string('status', 20)->default('unverified')->index();
            $table->unsignedInteger('current_attempt_number')->default(0);
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['network_profile_id', 'status']);
        });

        Schema::connection('mysql')->create('network_profile_verification_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('verification_id');
            $table->uuid('network_profile_id');
            $table->unsignedInteger('attempt_number');
            $table->string('status', 20)->default('pending');
            $table->uuid('submitted_by');
            $table->timestamp('submitted_at');
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->json('evidence');
            $table->timestamps();

            $table->unique(['verification_id', 'attempt_number'], 'npv_attempt_number_unique');
            $table->index(['network_profile_id', 'status'], 'npv_profile_status_index');
            $table->index(['status', 'submitted_at'], 'npv_status_submitted_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('network_profile_verification_attempts');
        Schema::connection('mysql')->dropIfExists('network_profile_verifications');
    }
};
