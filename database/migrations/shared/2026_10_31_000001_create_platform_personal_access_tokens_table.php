<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Personal Access Tokens — shared schema migration.
 *
 * Creates the personal_access_tokens table on the shared 'mysql' connection
 * for use exclusively by PlatformUser (BINAA2 platform operators).
 *
 * This table is intentionally separate from the per-tenant personal_access_tokens
 * table (created by database/migrations/iam/2026_09_24_000001_create_iam_tables.php).
 *
 * Key schema differences from the tenant version:
 *  - tokenable_id is BIGINT UNSIGNED (morphs), NOT UUID (uuidMorphs).
 *    PlatformUser uses auto-increment bigInteger primary key.
 *  - Lives on the 'mysql' connection (shared schema), never on 'tenant'.
 *
 * Security invariants:
 *  - Only PlatformUser tokens are stored here (tokenable_type = App\Foundation\PlatformUser).
 *  - Tenant user tokens are never stored here.
 *  - This table is never queried by the Sanctum tenant guard (App\IAM\PersonalAccessToken
 *    uses the 'tenant' connection and never touches 'mysql').
 */
return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->create('platform_personal_access_tokens', function (Blueprint $table) {
            $table->id();
            // Explicit columns instead of morphs() to avoid MySQL's 64-char index
            // name limit. morphs('tokenable') auto-generates the index name
            // 'platform_personal_access_tokens_tokenable_type_tokenable_id_index'
            // which is 65 characters — one over MySQL's hard limit of 64.
            // We declare the columns manually and use a short index name.
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id'); // bigint — matches PlatformUser->id (auto-increment)
            $table->index(['tokenable_type', 'tokenable_id'], 'ppat_tokenable_index');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('platform_personal_access_tokens');
    }
};
