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
        Schema::connection('tenant')->create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('invoice_number')->unique();
            $table->uuid('client_id'); // CRM Client — no FK (cross-module)
            $table->uuid('project_id')->nullable(); // Projects — no FK
            $table->uuid('contract_id')->nullable(); // Commercial — no FK
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('total_amount', 15, 4)->default(0);
            $table->decimal('paid_amount', 15, 4)->default(0);
            $table->string('status', 30)->default('DRAFT'); // DRAFT|ISSUED|PAID|PARTIALLY_PAID|CANCELLED
            $table->text('notes')->nullable();
            $table->uuid('issued_by')->nullable();
            $table->string('tenant_slug');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('invoices');
    }
};
