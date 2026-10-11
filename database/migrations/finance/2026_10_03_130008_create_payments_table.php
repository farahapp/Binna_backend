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
        Schema::connection('tenant')->create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('payment_number')->unique();
            $table->string('payment_type', 20); // SUPPLIER | CLIENT
            $table->string('payment_method', 30); // CASH | BANK_TRANSFER | CHEQUE
            $table->uuid('party_id'); // supplier_id or client_id — no FK
            $table->string('party_type', 50); // 'supplier' | 'client'
            $table->uuid('invoice_id')->nullable(); // no FK
            $table->uuid('bank_account_id')->nullable(); // no FK
            $table->decimal('amount', 15, 4);
            $table->date('payment_date');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->string('tenant_slug');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('payments');
    }
};
