<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared-schema migration — Track 1 (NEW-002).
 *
 * Creates the tenant_migrations table that tracks per-tenant migration
 * status independently (NEW-002 §7.1).
 */
return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->create('tenant_migrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('migration', 255);
            $table->integer('batch');
            $table->enum('status', ['applied', 'failed', 'rolled_back'])->default('applied');
            $table->timestamp('applied_at')->nullable();

            $table->index(['tenant_id', 'migration']);
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('tenant_migrations');
    }
};
