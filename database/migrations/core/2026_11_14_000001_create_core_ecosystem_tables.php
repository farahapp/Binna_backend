<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core Ecosystem shared-schema foundation.
 * Properties, Project Identities, Participations, Link Requests, Engagements.
 * No cross-tenant FKs — UUID + tenant_slug references only.
 */
return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->create('properties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_tenant_slug', 100);
            $table->uuid('owner_network_profile_id')->nullable();
            $table->string('name', 255);
            $table->string('property_type', 50)->default('real_estate');
            $table->string('address_line', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('country', 2)->default('QA');
            $table->string('plot_number', 100)->nullable();
            $table->string('title_deed_ref', 100)->nullable();
            $table->string('ownership_status', 30)->default('claimed');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index('owner_tenant_slug');
            $table->index('ownership_status');
        });

        Schema::connection('mysql')->create('project_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('property_id')->nullable()->index();
            $table->string('canonical_code', 64)->nullable()->unique();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('region', 100)->nullable();
            $table->string('status', 30)->default('active');
            $table->string('created_by_tenant_slug', 100);
            $table->string('created_by_org_type', 30);
            $table->string('verified_owner_tenant_slug', 100)->nullable();
            $table->json('match_keys')->nullable();
            $table->timestamps();
            $table->index('created_by_tenant_slug');
            $table->index('verified_owner_tenant_slug');
            $table->index('status');
        });

        Schema::connection('mysql')->create('project_participations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_identity_id')->index();
            $table->string('participant_tenant_slug', 100);
            $table->uuid('participant_network_profile_id')->nullable();
            $table->string('organization_type', 30);
            $table->string('project_role', 50);
            $table->string('status', 30)->default('invited');
            $table->json('access_scope')->nullable();
            $table->uuid('engagement_id')->nullable();
            $table->uuid('invitation_id')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->string('source', 50)->nullable();
            $table->timestamps();
            $table->unique(['project_identity_id', 'participant_tenant_slug', 'project_role'], 'pp_identity_tenant_role_uq');
            $table->index('participant_tenant_slug');
            $table->index('status');
        });

        Schema::connection('mysql')->create('project_link_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('source_project_identity_id')->nullable()->index();
            $table->uuid('target_project_identity_id')->nullable()->index();
            $table->uuid('property_id')->nullable();
            $table->string('requester_tenant_slug', 100);
            $table->string('requester_org_type', 30);
            $table->string('match_type', 40);
            $table->json('evidence')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('decided_by_tenant_slug', 100)->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamps();
            $table->index('requester_tenant_slug');
            $table->index('status');
        });

        Schema::connection('mysql')->create('engagements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_identity_id')->nullable()->index();
            $table->uuid('network_award_id')->nullable()->index();
            $table->uuid('network_tender_id')->nullable()->index();
            $table->string('engagement_type', 40);
            $table->string('client_tenant_slug', 100);
            $table->string('provider_tenant_slug', 100);
            $table->uuid('client_network_profile_id')->nullable();
            $table->uuid('provider_network_profile_id')->nullable();
            $table->string('status', 30)->default('draft');
            $table->decimal('contract_value', 15, 2)->nullable();
            $table->string('currency', 3)->default('QAR');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->json('terms')->nullable();
            $table->timestamps();
            $table->index('client_tenant_slug');
            $table->index('provider_tenant_slug');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('engagements');
        Schema::connection('mysql')->dropIfExists('project_link_requests');
        Schema::connection('mysql')->dropIfExists('project_participations');
        Schema::connection('mysql')->dropIfExists('project_identities');
        Schema::connection('mysql')->dropIfExists('properties');
    }
};
