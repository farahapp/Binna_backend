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
        Schema::connection('tenant')->create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('account_code')->unique();
            $table->string('name');
            $table->string('account_type', 20); // ASSET|LIABILITY|EQUITY|REVENUE|EXPENSE
            $table->uuid('parent_id')->nullable(); // no FK — same-schema reference
            $table->boolean('is_control_account')->default(false);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('tenant_slug');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('accounts');
    }
};
