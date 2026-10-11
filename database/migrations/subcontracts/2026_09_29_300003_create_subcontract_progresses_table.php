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
        Schema::connection('tenant')->create('subcontract_progresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_slug', 100)->index();
            $table->uuid('subcontract_agreement_id')->index();
            $table->foreign('subcontract_agreement_id')->references('id')->on('subcontract_agreements')->onDelete('cascade');
            $table->string('progress_period', 10)->index(); // YYYY-MM format (monthly period)
            $table->decimal('percentage_complete', 5, 2)->default(0);
            $table->decimal('cumulative_percentage', 5, 2)->default(0);
            $table->decimal('amount_certified', 18, 4)->default(0);
            $table->decimal('cumulative_amount', 18, 4)->default(0);
            $table->uuid('recorded_by')->nullable()->index();  // UUID only, no FK
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('subcontract_progresses');
    }
};
