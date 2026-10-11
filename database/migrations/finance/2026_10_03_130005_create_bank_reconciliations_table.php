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
        Schema::connection('tenant')->create('bank_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bank_account_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('statement_balance', 15, 4);
            $table->decimal('book_balance', 15, 4);
            $table->decimal('difference', 15, 4)->default(0);
            $table->string('status', 20)->default('DRAFT'); // DRAFT | COMPLETED
            $table->uuid('reconciled_by')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('tenant_slug');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('bank_reconciliations');
    }
};
