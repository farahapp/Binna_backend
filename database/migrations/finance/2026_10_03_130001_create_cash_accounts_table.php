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
        Schema::connection('tenant')->create('cash_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('account_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('QAR');
            $table->decimal('balance', 15, 4)->default(0);
            $table->string('status', 20)->default('active');
            $table->string('tenant_slug');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('cash_accounts');
    }
};
