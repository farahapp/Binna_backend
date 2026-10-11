<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared-schema migration — Track 1 (NEW-002).
 *
 * Creates the tenants table in the shared database.
 * schema_name is populated server-side by TenantProvisioner; it is never
 * accepted from the client.
 */
return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 63)->unique();
            $table->string('name');
            $table->string('schema_name', 80)->unique()->nullable();
            $table->enum('status', ['pending', 'active', 'suspended'])->default('pending');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('tenants');
    }
};
