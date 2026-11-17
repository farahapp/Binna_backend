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
        Schema::connection('mysql')->create('network_tender_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tender_id')->index();
            $table->uuid('invitee_network_profile_id')->index();
            $table->string('invitee_tenant_slug', 100)->nullable()->index();
            $table->string('inviter_tenant_slug', 100);
            $table->string('status', 30)->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->string('response_note', 500)->nullable();
            $table->timestamps();
            $table->unique(['tender_id', 'invitee_network_profile_id'], 'nti_tender_invitee_uq');
            $table->index('inviter_tenant_slug');
            $table->index('status');
        });

        Schema::connection('mysql')->create('network_clarifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tender_id')->index();
            $table->uuid('bid_id')->nullable()->index();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('asked_by_tenant_slug', 100);
            $table->uuid('asked_by_profile_id')->nullable();
            $table->string('directed_to_tenant_slug', 100)->nullable();
            $table->text('question');
            $table->text('response')->nullable();
            $table->string('status', 30)->default('open');
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->index('asked_by_tenant_slug');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('network_clarifications');
        Schema::connection('mysql')->dropIfExists('network_tender_invitations');
    }
};
