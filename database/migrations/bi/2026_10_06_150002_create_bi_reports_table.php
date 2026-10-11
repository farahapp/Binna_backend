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
        Schema::connection('tenant')->create('bi_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('report_key', 100); // unique slug e.g. 'finance_invoice_summary'
            $table->string('module_scope', 50); // finance|accounting|projects|hr|equipment|inventory|procurement|commercial|subcontracts
            $table->json('default_filters')->nullable(); // default filter configuration
            $table->string('status', 20)->default('active'); // active|inactive
            $table->string('tenant_slug');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('bi_reports');
    }
};
