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
        Schema::connection('mysql')->create('network_bids', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tender_id')->index();
            $table->uuid('contractor_profile_id')->index();
            $table->uuid('effective_version_id')->nullable()->index();
            $table->enum('status', ['submitted', 'revised', 'withdrawn'])->default('submitted')->index();
            $table->unsignedInteger('latest_revision_number')->default(1);
            $table->timestamp('submitted_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('managed_by_tenant_slug', 100)->nullable()->index();
            $table->timestamps();
            $table->unique(['tender_id', 'contractor_profile_id']);
        });

        Schema::connection('mysql')->create('network_bid_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bid_id')->index();
            $table->unsignedInteger('revision_number');
            $table->json('content');
            $table->uuid('actor_id');
            $table->text('reason')->nullable();
            $table->enum('status', ['effective', 'superseded', 'withdrawn'])->default('effective')->index();
            $table->timestamp('submitted_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->unique(['bid_id', 'revision_number']);
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('network_bid_versions');
        Schema::connection('mysql')->dropIfExists('network_bids');
    }
};
