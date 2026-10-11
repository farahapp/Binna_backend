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
        Schema::connection('tenant')->create('bi_dashboards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('dashboard_type', 50)->default('custom'); // financial|project|hr|equipment|inventory|commercial|custom
            $table->json('config')->nullable(); // widget/layout configuration
            $table->string('status', 20)->default('active'); // active|inactive
            $table->string('tenant_slug');
            $table->uuid('created_by')->nullable(); // user id — no FK across schemas
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('bi_dashboards');
    }
};
