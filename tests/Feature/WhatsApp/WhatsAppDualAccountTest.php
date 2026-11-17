<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Foundation\PlatformUser;
use App\WhatsApp\Contracts\WhatsAppBridgeContract;
use App\WhatsApp\FakeWhatsAppBridge;
use App\WhatsApp\WhatsAppDispatchService;
use App\WhatsApp\WhatsAppDualAccountService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class WhatsAppDualAccountTest extends TestCase
{
    private FakeWhatsAppBridge $bridge;

    private WhatsAppDualAccountService $accounts;

    private WhatsAppDispatchService $dispatch;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'whatsapp.use_fake_bridge' => true,
        ]);
        DB::purge('mysql');
        $this->createSchema();

        $this->bridge = new FakeWhatsAppBridge;
        $this->app->instance(WhatsAppBridgeContract::class, $this->bridge);
        $this->accounts = $this->app->make(WhatsAppDualAccountService::class);
        $this->dispatch = $this->app->make(WhatsAppDispatchService::class);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        parent::tearDown();
    }

    #[Test]
    public function bridge_health_and_independent_sessions(): void
    {
        $health = $this->bridge->health();
        $this->assertTrue($health['ok']);

        $this->bridge->startSession('primary', 'candidate');
        $this->bridge->mockComplete('primary', 'candidate', '+97450000001');
        $this->bridge->startSession('secondary', 'candidate');
        $this->bridge->mockComplete('secondary', 'candidate', '+97450000002');

        $this->assertSame('+97450000001', $this->bridge->accountStatus('primary', 'candidate')['phone_e164']);
        $this->assertSame('+97450000002', $this->bridge->accountStatus('secondary', 'candidate')['phone_e164']);
        $this->assertNotSame(
            $this->bridge->accountStatus('primary', 'candidate')['phone_e164'],
            $this->bridge->accountStatus('secondary', 'candidate')['phone_e164'],
        );
    }

    #[Test]
    public function primary_link_verify_approve_enable_and_send_without_secondary(): void
    {
        $actor = $this->actor();
        $this->linkApproveEnable('primary', '+97451110001', $actor);

        $this->bridge->setSendBehavior('secondary', false, 'should_not_be_called');
        $result = $this->dispatch->sendWhatsApp('+97470000001', 'hello', 'operational_notice', 'test');
        $this->assertTrue($result['ok']);
        $this->assertSame('primary', $result['slot']);
        $this->assertNull($result['failover_reason']);
    }

    #[Test]
    public function failover_to_secondary_when_primary_fails(): void
    {
        $actor = $this->actor();
        $this->linkApproveEnable('primary', '+97451110001', $actor);
        $this->linkApproveEnable('secondary', '+97451110002', $actor);
        DB::connection('mysql')->table('platform_whatsapp_settings')->where('id', 1)->update([
            'failover_enabled' => true,
            'otp_enabled' => true,
            'updated_at' => now(),
        ]);

        $this->bridge->setSendBehavior('primary', false, 'send_failed');
        $this->bridge->setSendBehavior('secondary', true);
        $result = $this->dispatch->sendWhatsApp('+97470000001', 'hello', 'registration_otp', 'registration_otp', 'idem-1');
        $this->assertTrue($result['ok']);
        $this->assertSame('secondary', $result['slot']);
        $this->assertNotNull($result['failover_reason']);

        // Idempotency: same key does not double-send path differently
        $again = $this->dispatch->sendWhatsApp('+97470000001', 'hello', 'registration_otp', 'registration_otp', 'idem-1');
        $this->assertSame($result['dispatch_id'], $again['dispatch_id']);
    }

    #[Test]
    public function secondary_not_approved_cannot_be_used_or_enabled(): void
    {
        $actor = $this->actor();
        $this->linkApproveEnable('primary', '+97451110001', $actor);
        $this->expectException(RuntimeException::class);
        $this->accounts->updateSettings($actor, ['secondary_enabled' => true, 'failover_enabled' => true]);
    }

    #[Test]
    public function email_fallback_when_both_whatsapp_fail(): void
    {
        $actor = $this->actor();
        $this->linkApproveEnable('primary', '+97451110001', $actor);
        $this->linkApproveEnable('secondary', '+97451110002', $actor);
        DB::connection('mysql')->table('platform_whatsapp_settings')->where('id', 1)->update([
            'failover_enabled' => true,
            'notifications_enabled' => true,
            'email_fallback_enabled' => true,
            'updated_at' => now(),
        ]);
        $this->bridge->setSendBehavior('primary', false, 'send_failed');
        $this->bridge->setSendBehavior('secondary', false, 'send_failed');

        $emailUsed = false;
        $result = $this->dispatch->sendOperationalNotice('+97470000001', 'notice', 'doc_request', true, function () use (&$emailUsed) {
            $emailUsed = true;
        });
        $this->assertTrue($result['ok']);
        $this->assertSame('email', $result['channel']);
        $this->assertTrue($emailUsed);
    }

    #[Test]
    public function operational_notice_rejects_unverified_recipient(): void
    {
        $result = $this->dispatch->sendOperationalNotice('+97470000001', 'x', 'invite', false);
        $this->assertFalse($result['ok']);
        $this->assertSame('recipient_unverified', $result['result']);
    }

    #[Test]
    public function otp_disabled_without_sender(): void
    {
        $result = $this->dispatch->sendRegistrationOtp('+97470000001', '123456', 'challenge:1');
        $this->assertFalse($result['whatsapp_ok']);
        $this->assertSame('otp_disabled', $result['result']);
    }

    #[Test]
    public function same_otp_challenge_uses_failover_without_new_code_path(): void
    {
        $actor = $this->actor();
        $this->linkApproveEnable('primary', '+97451110001', $actor);
        $this->linkApproveEnable('secondary', '+97451110002', $actor);
        DB::connection('mysql')->table('platform_whatsapp_settings')->where('id', 1)->update([
            'failover_enabled' => true,
            'otp_enabled' => true,
            'updated_at' => now(),
        ]);
        $this->bridge->setSendBehavior('primary', false, 'send_failed');
        $out = $this->dispatch->sendRegistrationOtp('+97470000001', '654321', 'challenge:42');
        $this->assertTrue($out['whatsapp_ok']);
        $this->assertSame('secondary', $out['slot']);
    }

    #[Test]
    public function replacement_keeps_active_until_approve_and_supports_rollback(): void
    {
        $actor = $this->actor();
        $this->linkApproveEnable('primary', '+97451110001', $actor);
        $this->linkApproveEnable('secondary', '+97451110002', $actor);

        $this->accounts->startLink($actor, 'primary', true);
        $this->assertTrue((bool) DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', 'primary')->value('replacement_pending'));
        $this->assertTrue($this->accounts->isSendReady('secondary'));

        $this->bridge->mockComplete('primary', 'candidate', '+97451119999');
        $this->accounts->syncCandidateIdentity($actor, 'primary');
        $this->accounts->approve($actor, 'primary');

        $approved = DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', 'primary')->value('approved_sender_e164');
        $this->assertSame('+97451119999', $approved);

        // Rollback restores previous backup identity in fake bridge
        DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', 'primary')->update([
            'enabled' => true,
            'lifecycle_state' => 'active',
            'updated_at' => now(),
        ]);
        $this->accounts->rollback($actor, 'primary');
        $restored = DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', 'primary')->value('active_sender_e164');
        $this->assertSame('+97451110001', $restored);
    }

    #[Test]
    public function identity_mismatch_rejected(): void
    {
        $actor = $this->actor();
        $this->accounts->startLink($actor, 'primary', false);
        $this->bridge->mockComplete('primary', 'candidate', '+97451110001');
        $this->expectException(RuntimeException::class);
        $this->accounts->syncCandidateIdentity($actor, 'primary', '+97451110009');
    }

    #[Test]
    public function unknown_timeout_does_not_blindly_failover(): void
    {
        $actor = $this->actor();
        $this->linkApproveEnable('primary', '+97451110001', $actor);
        $this->linkApproveEnable('secondary', '+97451110002', $actor);
        DB::connection('mysql')->table('platform_whatsapp_settings')->where('id', 1)->update([
            'failover_enabled' => true,
            'updated_at' => now(),
        ]);
        $this->bridge->setSendBehavior('primary', false, 'timeout');
        $result = $this->dispatch->sendWhatsApp('+97470000001', 'x', 'operational_notice', 't');
        $this->assertFalse($result['ok']);
        $this->assertSame('unknown', $result['result']);
        $this->assertSame('primary', $result['slot']);
    }

    #[Test]
    public function connection_test_distinguishes_bridge_and_send_ready(): void
    {
        $actor = $this->actor();
        $out = $this->accounts->testConnection($actor, 'primary');
        $this->assertFalse($out['results']['primary']['send_ready']);
        $this->assertTrue($out['results']['primary']['bridge_reachable']);
    }

    #[Test]
    public function audit_records_replacement_phones(): void
    {
        $actor = $this->actor();
        $this->linkApproveEnable('primary', '+97451110001', $actor);
        $this->accounts->startLink($actor, 'primary', true);
        $this->bridge->mockComplete('primary', 'candidate', '+97451118888');
        $this->accounts->syncCandidateIdentity($actor, 'primary');
        $this->accounts->approve($actor, 'primary');

        $row = DB::connection('mysql')->table('platform_whatsapp_ops_audit')
            ->where('event', 'platform.whatsapp.replacement_completed')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('+97451110001', $row->previous_phone_e164);
        $this->assertSame('+97451118888', $row->new_phone_e164);
        $this->assertSame($actor->id, (int) $row->actor_id);
    }

    private function linkApproveEnable(string $slot, string $phone, PlatformUser $actor): void
    {
        $this->accounts->startLink($actor, $slot, false);
        $this->bridge->mockComplete($slot, 'candidate', $phone);
        $this->accounts->syncCandidateIdentity($actor, $slot);
        $this->accounts->approve($actor, $slot);
        // After approve, enable explicitly
        $this->accounts->updateSettings($actor, [
            $slot === 'primary' ? 'primary_enabled' : 'secondary_enabled' => true,
        ]);
        // When enabling second account, may need both
        if ($slot === 'secondary') {
            $this->accounts->updateSettings($actor, [
                'primary_enabled' => true,
                'secondary_enabled' => true,
            ]);
        }
    }

    private function actor(): PlatformUser
    {
        $id = DB::connection('mysql')->table('platform_users')->insertGetId([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('secret'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PlatformUser::on('mysql')->findOrFail($id);
    }

    private function createSchema(): void
    {
        Schema::connection('mysql')->create('platform_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection('mysql')->create('platform_whatsapp_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->boolean('otp_enabled')->default(false);
            $table->boolean('notifications_enabled')->default(false);
            $table->boolean('failover_enabled')->default(false);
            $table->boolean('email_fallback_enabled')->default(true);
            $table->unsignedTinyInteger('primary_priority')->default(1);
            $table->unsignedTinyInteger('secondary_priority')->default(2);
            $table->string('configured_sender_e164', 32)->nullable();
            $table->timestamps();
        });
        DB::connection('mysql')->table('platform_whatsapp_settings')->insert([
            'id' => 1,
            'enabled' => false,
            'otp_enabled' => false,
            'notifications_enabled' => false,
            'failover_enabled' => false,
            'email_fallback_enabled' => true,
            'primary_priority' => 1,
            'secondary_priority' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::connection('mysql')->create('platform_whatsapp_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('slot', 16)->unique();
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
        });
        foreach (['primary', 'secondary'] as $slot) {
            DB::connection('mysql')->table('platform_whatsapp_accounts')->insert([
                'slot' => $slot,
                'lifecycle_state' => 'disconnected',
                'enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

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
            $table->string('channel_final', 16)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::connection('mysql')->create('platform_whatsapp_ops_audit', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event', 120);
            $table->string('slot', 16)->nullable();
            $table->string('previous_phone_e164', 32)->nullable();
            $table->string('new_phone_e164', 32)->nullable();
            $table->string('result', 32)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }
}
