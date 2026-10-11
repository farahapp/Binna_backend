<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add bank_account_id and cash_account_id to collections table.
 * TASK-016 — Finance → Accounting Integration (OI-E2E-03)
 * DEC-006: payment_method + bank_account_id/cash_account_id required for PaymentReceived event.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('collections', function (Blueprint $table) {
            $table->uuid('bank_account_id')->nullable()->after('payment_method');
            $table->uuid('cash_account_id')->nullable()->after('bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('collections', function (Blueprint $table) {
            $table->dropColumn(['bank_account_id', 'cash_account_id']);
        });
    }
};
