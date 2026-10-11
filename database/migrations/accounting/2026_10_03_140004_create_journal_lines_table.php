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
        Schema::connection('tenant')->create('journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('entry_id'); // no FK — same-schema reference
            $table->uuid('account_id'); // no FK — same-schema reference
            $table->decimal('debit', 15, 4)->default(0);
            $table->decimal('credit', 15, 4)->default(0);
            $table->uuid('project_id')->nullable(); // no FK — cross-module reference
            $table->uuid('cost_code_id')->nullable();
            $table->uuid('department_id')->nullable();
            $table->text('description')->nullable();
            $table->string('tenant_slug');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('journal_lines');
    }
};
