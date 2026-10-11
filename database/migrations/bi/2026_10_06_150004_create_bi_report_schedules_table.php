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
        Schema::connection('tenant')->create('bi_report_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('report_id'); // no FK — same-schema reference
            $table->string('name');
            $table->string('frequency', 20)->default('monthly'); // daily|weekly|monthly
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('status', 20)->default('active'); // active|paused
            $table->json('config')->nullable();
            $table->string('tenant_slug');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('bi_report_schedules');
    }
};
