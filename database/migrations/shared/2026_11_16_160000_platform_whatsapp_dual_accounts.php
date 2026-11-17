<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('mysql')->hasTable('platform_whatsapp_accounts')) {
            Schema::connection('mysql')->create('platform_whatsapp_accounts', function (Blueprint $table): void {
                $table->id();
                $table->string('slot', 16); // primary|secondary
                $table->string('lifecycle_state', 40)->default('disconnected');
                $table->boolean('enabled')->default(false);
                $table->string('candidate_phone_e164', 32)->nullable();
                $table->string('linked_phone_e164', 32)->nullable();
                $table->string('verified_phone_e164', 32)->nullable();
                $table->string('approved_sender_e164', 32)->nullable();
                $table->string('active_sender_e164', 32)->nullable();
                $table->timestamp('identity_verified_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('last_connected_at')->nullable();
                $table->timestamp('last_disconnected_at')->nullable();
                $table->timestamp('last_tested_at')->nullable();
                $table->string('last_test_result', 40)->nullable();
                $table->timestamp('last_successful_send_at')->nullable();
                $table->timestamp('last_failed_send_at')->nullable();
                $table->boolean('replacement_pending')->default(false);
                $table->boolean('has_session_backup')->default(false);
                $table->timestamps();
                $table->unique('slot');
            });

            $now = now();
            foreach (['primary', 'secondary'] as $slot) {
                DB::connection('mysql')->table('platform_whatsapp_accounts')->insert([
                    'slot' => $slot,
                    'lifecycle_state' => 'disconnected',
                    'enabled' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (Schema::connection('mysql')->hasTable('platform_whatsapp_settings')) {
            Schema::connection('mysql')->table('platform_whatsapp_settings', function (Blueprint $table): void {
                if (! Schema::connection('mysql')->hasColumn('platform_whatsapp_settings', 'failover_enabled')) {
                    $table->boolean('failover_enabled')->default(false);
                }
                if (! Schema::connection('mysql')->hasColumn('platform_whatsapp_settings', 'email_fallback_enabled')) {
                    $table->boolean('email_fallback_enabled')->default(true);
                }
                if (! Schema::connection('mysql')->hasColumn('platform_whatsapp_settings', 'primary_priority')) {
                    $table->unsignedTinyInteger('primary_priority')->default(1);
                }
                if (! Schema::connection('mysql')->hasColumn('platform_whatsapp_settings', 'secondary_priority')) {
                    $table->unsignedTinyInteger('secondary_priority')->default(2);
                }
            });
        }

        if (! Schema::connection('mysql')->hasTable('platform_whatsapp_dispatch_log')) {
            Schema::connection('mysql')->create('platform_whatsapp_dispatch_log', function (Blueprint $table): void {
                $table->id();
                $table->uuid('dispatch_id')->unique();
                $table->string('purpose', 64);
                $table->string('template', 64);
                $table->string('destination_masked', 32)->nullable();
                $table->string('chosen_slot', 16)->nullable();
                $table->string('attempted_slots', 64)->nullable();
                $table->string('failover_reason', 120)->nullable();
                $table->string('result', 32);
                $table->string('channel_final', 16)->nullable(); // whatsapp|email
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::connection('mysql')->hasTable('platform_whatsapp_ops_audit')) {
            Schema::connection('mysql')->create('platform_whatsapp_ops_audit', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->string('event', 120)->index();
                $table->string('slot', 16)->nullable()->index();
                $table->string('previous_phone_e164', 32)->nullable();
                $table->string('new_phone_e164', 32)->nullable();
                $table->string('result', 32)->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('platform_whatsapp_ops_audit');
        Schema::connection('mysql')->dropIfExists('platform_whatsapp_dispatch_log');
        Schema::connection('mysql')->dropIfExists('platform_whatsapp_accounts');
    }
};
