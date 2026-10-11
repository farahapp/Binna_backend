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
        Schema::connection('tenant')->create('subcontract_certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_slug', 100)->index();
            $table->uuid('subcontract_agreement_id')->index();
            $table->foreign('subcontract_agreement_id')->references('id')->on('subcontract_agreements')->onDelete('cascade');
            $table->uuid('subcontract_progress_id')->nullable()->index();
            $table->foreign('subcontract_progress_id')->references('id')->on('subcontract_progresses')->onDelete('set null');
            $table->string('certificate_number', 100)->nullable()->index();
            $table->decimal('certified_amount', 18, 4)->default(0);
            $table->decimal('retention_deducted', 18, 4)->default(0);
            $table->decimal('net_payable', 18, 4)->default(0);
            $table->string('status', 30)->default('draft')->index();
            $table->uuid('approved_by')->nullable();  // UUID only, no FK
            $table->date('issued_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('subcontract_certificates');
    }
};
