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
        Schema::connection('mysql')->create('network_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tender_id')->index();
            $table->enum('status', ['not_started', 'open', 'completed'])->default('not_started')->index();
            $table->uuid('started_by')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->timestamp('evaluation_started_at')->nullable();
            $table->timestamp('evaluation_completed_at')->nullable();
            $table->string('managed_by_tenant_slug', 100)->nullable()->index();
            $table->timestamps();
            $table->unique('tender_id');
        });

        Schema::connection('mysql')->create('network_evaluation_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('evaluation_id')->index();
            $table->uuid('bid_id')->nullable()->index();
            $table->uuid('author_id');
            $table->text('content');
            $table->string('managed_by_tenant_slug', 100)->nullable()->index();
            $table->timestamps();
            $table->foreign('evaluation_id')->references('id')->on('network_evaluations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('network_evaluation_notes');
        Schema::connection('mysql')->dropIfExists('network_evaluations');
    }
};
