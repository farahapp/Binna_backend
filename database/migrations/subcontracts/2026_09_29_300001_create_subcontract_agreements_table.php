<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('subcontract_agreements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_slug', 100)->index();
            $table->string('agreement_number', 100)->nullable()->index();
            $table->string('subcontractor_name', 255);
            $table->uuid('subcontractor_id')->nullable()->index();  // UUID only, no FK
            $table->uuid('project_id')->nullable()->index();         // UUID only, no cross-schema FK
            $table->string('title', 255);
            $table->text('scope_summary')->nullable();
            $table->decimal('contract_value', 18, 4)->default(0);
            $table->decimal('retention_percentage', 5, 2)->default(0);
            $table->string('currency', 10)->default('SAR');
            $table->string('status', 30)->default('draft')->index();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->uuid('approval_request_id')->nullable();  // UUID only, no FK
            $table->uuid('signed_by')->nullable();            // UUID only, no FK
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('subcontract_agreements');
    }
};
