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
        Schema::connection('tenant')->create('receivables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id')->unique(); // one receivable per invoice
            $table->uuid('client_id'); // no FK
            $table->decimal('original_amount', 15, 4);
            $table->decimal('collected_amount', 15, 4)->default(0);
            $table->decimal('outstanding_amount', 15, 4);
            $table->date('due_date')->nullable();
            $table->string('status', 30)->default('OPEN'); // OPEN|PARTIALLY_COLLECTED|COLLECTED|WRITTEN_OFF
            $table->string('tenant_slug');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('receivables');
    }
};
