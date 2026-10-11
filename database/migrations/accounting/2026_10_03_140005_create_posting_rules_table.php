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
        Schema::connection('tenant')->create('posting_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('trigger_event', 100); // e.g. 'InvoiceIssued', 'GoodsReceived'
            $table->string('rule_name', 200);
            $table->uuid('debit_account_id')->nullable(); // no FK
            $table->uuid('credit_account_id')->nullable(); // no FK
            $table->string('amount_source', 100)->nullable(); // field name in event payload
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->string('tenant_slug');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('posting_rules');
    }
};
