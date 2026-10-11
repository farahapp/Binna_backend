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
        Schema::connection('tenant')->create('bi_kpi_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('kpi_key', 100); // unique identifier e.g. 'budget_utilization_pct'
            $table->string('module_scope', 50); // which module provides the source data
            $table->json('calculation_config')->nullable(); // config for KPI computation
            $table->string('unit', 30)->default('count'); // percentage|currency_qar|count|days
            $table->string('status', 20)->default('active');
            $table->string('tenant_slug');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('bi_kpi_definitions');
    }
};
