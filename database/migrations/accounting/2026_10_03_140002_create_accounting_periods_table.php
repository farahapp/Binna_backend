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
        Schema::connection('tenant')->create('accounting_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('period_name', 20)->unique(); // e.g. "2026-10"
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('OPEN'); // OPEN|CLOSED|LOCKED
            $table->uuid('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('tenant_slug');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('accounting_periods');
    }
};
