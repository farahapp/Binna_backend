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
        Schema::connection('tenant')->create('cash_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cash_account_id');
            $table->string('type', 10); // IN | OUT
            $table->decimal('amount', 15, 4);
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->date('transaction_date');
            $table->uuid('recorded_by')->nullable();
            $table->string('tenant_slug');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('cash_transactions');
    }
};
