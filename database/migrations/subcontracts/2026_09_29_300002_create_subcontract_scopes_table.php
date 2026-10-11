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
        Schema::connection('tenant')->create('subcontract_scopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_slug', 100)->index();
            $table->uuid('subcontract_agreement_id')->index();
            $table->foreign('subcontract_agreement_id')->references('id')->on('subcontract_agreements')->onDelete('cascade');
            $table->string('scope_item_number', 50)->nullable();
            $table->text('description');
            $table->string('unit', 50)->nullable();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('unit_rate', 18, 4)->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('subcontract_scopes');
    }
};
