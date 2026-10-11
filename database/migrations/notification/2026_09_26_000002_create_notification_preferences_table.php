<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_slug', 100)->index();
            $table->uuid('user_id')->index();
            $table->string('notification_type', 150)->index();
            $table->string('channel', 50)->default('database');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['tenant_slug', 'user_id', 'notification_type', 'channel'], 'notif_pref_unique');
        });
    }
    public function down(): void {
        Schema::dropIfExists('notification_preferences');
    }
};
