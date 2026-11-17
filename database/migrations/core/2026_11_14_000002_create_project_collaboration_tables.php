<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared project collaboration: contractor submissions, consultant reviews, owner decisions.
 */
return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->create('project_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_identity_id')->index();
            $table->string('submitter_tenant_slug', 100);
            $table->uuid('submitter_participation_id')->nullable();
            $table->string('submission_type', 50); // document|rfi|inspection_request|progress|other
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 30)->default('submitted'); // submitted|under_review|recommended|owner_pending|approved|rejected|returned
            $table->timestamps();
            $table->index('submitter_tenant_slug');
            $table->index('status');
        });

        Schema::connection('mysql')->create('project_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('submission_id')->index();
            $table->uuid('project_identity_id')->index();
            $table->string('reviewer_tenant_slug', 100);
            $table->string('reviewer_org_type', 30)->default('consultant');
            $table->string('outcome', 40); // recommend_approve|recommend_reject|observation|clarification_needed
            $table->text('comments')->nullable();
            $table->boolean('requires_owner_decision')->default(false);
            $table->timestamps();
            $table->index('reviewer_tenant_slug');
        });

        Schema::connection('mysql')->create('project_owner_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('submission_id')->index();
            $table->uuid('review_id')->nullable()->index();
            $table->uuid('project_identity_id')->index();
            $table->string('owner_tenant_slug', 100);
            $table->string('decision', 30); // approve|reject|return
            $table->text('comments')->nullable();
            $table->timestamps();
            $table->index('owner_tenant_slug');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('project_owner_decisions');
        Schema::connection('mysql')->dropIfExists('project_reviews');
        Schema::connection('mysql')->dropIfExists('project_submissions');
    }
};
