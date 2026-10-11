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
        Schema::connection('tenant')->create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->string('source_module', 50)->nullable(); // Finance|Inventory|HR|Subcontracts|Equipment|Manual
            $table->uuid('source_document_id')->nullable();
            $table->string('source_document_type', 100)->nullable();
            $table->date('posting_date');
            $table->uuid('period_id'); // no FK — same-schema reference
            $table->string('status', 20)->default('DRAFT'); // DRAFT|POSTED|REVERSED
            $table->text('narration')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->uuid('reversal_of')->nullable(); // references another journal_entry
            $table->string('tenant_slug');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('journal_entries');
    }
};
