<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification history — immutable delivery record.
 * F-3 (UNRESOLVED): Failure/retry semantics are not implemented.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('notification_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_slug', 100)->index();
            $table->uuid('notification_id')->index();
            $table->string('channel', 50)->index();
            $table->string('status', 20)->default('sent')->index();
            $table->text('failure_reason')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('notification_history');
    }
};
