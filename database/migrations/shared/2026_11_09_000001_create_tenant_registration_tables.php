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
        Schema::connection('mysql')->create('registration_applicants', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('mysql')->create('registration_otp_challenges', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->index();
            $table->string('code_hash', 255);
            $table->timestamp('expires_at')->index();
            $table->timestamp('sent_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('mysql')->create('registration_applicant_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('applicant_id')->constrained('registration_applicants')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('mysql')->create('registration_representatives', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 32)->nullable();
            $table->string('password')->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('mysql')->create('registration_representative_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('representative_id')->constrained('registration_representatives')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('mysql')->create('registration_representative_invites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('representative_id')->constrained('registration_representatives')->cascadeOnDelete();
            $table->string('purpose', 32);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('mysql')->create('registration_identity_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('event', 64);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id', 'created_at'], 'reg_identity_subject_audit_idx');
            $table->index(['actor_type', 'actor_id', 'created_at'], 'reg_identity_actor_audit_idx');
        });

        Schema::connection('mysql')->create('tenant_registration_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_number', 32)->unique();
            $table->string('slug', 63)->unique();
            $table->foreignId('applicant_id')->nullable()->constrained('registration_applicants')->restrictOnDelete();
            $table->foreignId('representative_id')->nullable()->constrained('registration_representatives')->restrictOnDelete();
            $table->foreignId('tenant_id')->nullable()->unique()->constrained('tenants')->restrictOnDelete();
            $table->string('source', 32);
            $table->string('status', 40)->default('draft')->index();
            $table->string('applicant_email')->index();
            $table->string('name');
            $table->string('legal_type', 32);
            $table->string('activity_type', 32);
            $table->string('phone', 32)->nullable();
            $table->string('responsible_name');
            $table->string('responsible_qid', 32)->nullable();
            $table->date('qid_expires_at')->nullable();
            $table->timestamp('owner_consent_at')->nullable();
            $table->string('owner_consent_version', 32)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'status']);
            $table->index(['legal_type', 'activity_type']);
            $table->index(['created_at', 'status']);
        });

        Schema::connection('mysql')->create('tenant_registration_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('tenant_registration_requests')->cascadeOnDelete();
            $table->foreignId('representative_id')->constrained('registration_representatives')->cascadeOnDelete();
            $table->unsignedBigInteger('assigned_by')->index();
            $table->timestamp('assigned_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['request_id', 'representative_id'], 'reg_assignment_req_rep_uq');
        });

        Schema::connection('mysql')->create('tenant_registration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('tenant_registration_requests')->cascadeOnDelete();
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event', 64);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['request_id', 'created_at']);
            $table->index(['actor_type', 'actor_id', 'created_at']);
        });

        Schema::connection('mysql')->create('tenant_registration_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('tenant_registration_requests')->cascadeOnDelete();
            $table->string('uploaded_by_type', 32);
            $table->unsignedBigInteger('uploaded_by_id')->nullable();
            $table->string('disk', 32)->default('local');
            $table->string('path', 512);
            $table->string('original_name', 255);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size');
            $table->timestamps();
            $table->index(['request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('tenant_registration_attachments');
        Schema::connection('mysql')->dropIfExists('tenant_registration_events');
        Schema::connection('mysql')->dropIfExists('tenant_registration_assignments');
        Schema::connection('mysql')->dropIfExists('tenant_registration_requests');
        Schema::connection('mysql')->dropIfExists('registration_identity_audit_events');
        Schema::connection('mysql')->dropIfExists('registration_representative_invites');
        Schema::connection('mysql')->dropIfExists('registration_representative_tokens');
        Schema::connection('mysql')->dropIfExists('registration_representatives');
        Schema::connection('mysql')->dropIfExists('registration_applicant_tokens');
        Schema::connection('mysql')->dropIfExists('registration_otp_challenges');
        Schema::connection('mysql')->dropIfExists('registration_applicants');
    }
};
