<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications table — Per-Tenant Schema.
 * F-3 (UNRESOLVED): Retry/dead-letter semantics are not implemented.
 * F-13 (UNRESOLVED): UUID follows HasUuids convention.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_slug', 100)->index();
            $table->uuid('notifiable_id')->index();
            $table->string('notifiable_type', 150)->index();
            $table->string('type', 150)->index();
            $table->string('channel', 50)->default('database')->index();
            $table->json('data')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('notifications');
    }
};
