<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Users — shared schema migration.
 *
 * Platform operators (BINAA2 staff) are entirely separate from tenant users.
 * They live in the shared schema and have NO access to any tenant schema.
 *
 * Security invariants:
 *  - Platform users cannot be confused with tenant users (different table, different connection).
 *  - is_active flag allows disabling without deletion.
 *  - No tenant_id column — platform users are not scoped to any tenant.
 */
return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->create('platform_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('email');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('platform_users');
    }
};
