<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Foundation\PlatformPermission;
use App\Foundation\PlatformUser;
use App\WhatsApp\Contracts\WhatsAppBridgeContract;
use App\WhatsApp\FakeWhatsAppBridge;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformWhatsAppManagementTest extends TestCase
{
    private FakeWhatsAppBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'whatsapp.use_fake_bridge' => true,
        ]);
        DB::purge('mysql');
        $this->createSchema();
        $this->bridge = new FakeWhatsAppBridge;
        $this->app->instance(WhatsAppBridgeContract::class, $this->bridge);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        parent::tearDown();
    }

    public function test_guest_cannot_access_management_api(): void
    {
        $this->getJson('/api/platform/integrations/whatsapp')->assertUnauthorized();
    }

    public function test_operator_without_view_permission_is_forbidden(): void
    {
        $operator = $this->makeOperator(['platform.users.view']);

        $this->actingAs($operator, 'platform-web')
            ->getJson('/api/platform/integrations/whatsapp')
            ->assertForbidden();
    }

    public function test_overview_returns_two_accounts_with_masked_phones_and_no_secrets(): void
    {
        $operator = $this->makeOperator(['platform.integrations.whatsapp.view']);
        DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', 'primary')->update([
            'active_sender_e164' => '+97451234567',
            'approved_sender_e164' => '+97451234567',
            'verified_phone_e164' => '+97451234567',
        ]);

        $response = $this->actingAs($operator, 'platform-web')
            ->getJson('/api/platform/integrations/whatsapp')
            ->assertOk();

        $response->assertJsonPath('summary.total_accounts', 2);
        $response->assertJsonCount(2, 'accounts');
        $response->assertJsonPath('accounts.0.phone_masked', '+974***567');
        $response->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control') ?? '');
        $raw = $response->getContent() ?: '';
        $this->assertStringNotContainsString('+97451234567', $raw);
        $this->assertStringNotContainsString('shared_secret', $raw);
        $this->assertStringNotContainsString('qr_data_url', $raw);
    }

    public function test_connect_reports_qr_for_one_account_and_audits_the_action(): void
    {
        $operator = $this->makeOperator([
            'platform.integrations.whatsapp.view',
            'platform.integrations.whatsapp.manage',
        ]);

        $this->actingAs($operator, 'platform-web')
            ->postJson('/api/platform/integrations/whatsapp/accounts/primary/connect')
            ->assertOk()
            ->assertJsonPath('account.slot', 'primary');

        $this->assertSame('qr_pending', $this->bridge->accountStatus('primary', 'candidate')['status']);
        $this->assertSame('disconnected', $this->bridge->accountStatus('secondary', 'candidate')['status']);

        $events = DB::connection('mysql')->table('platform_whatsapp_ops_audit')->pluck('event')->all();
        $this->assertContains('platform.whatsapp.connect_requested', $events);
        $this->assertContains('platform.whatsapp.pairing_started', $events);
    }

    public function test_qr_endpoint_is_no_store_and_scoped_to_requested_slot(): void
    {
        $operator = $this->makeOperator([
            'platform.integrations.whatsapp.view',
            'platform.integrations.whatsapp.manage',
        ]);
        $this->actingAs($operator, 'platform-web')
            ->postJson('/api/platform/integrations/whatsapp/accounts/secondary/connect')
            ->assertOk();

        $response = $this->actingAs($operator, 'platform-web')
            ->getJson('/api/platform/integrations/whatsapp/accounts/secondary/qr')
            ->assertOk();

        $response->assertJsonPath('slot', 'secondary');
        $response->assertJsonPath('role', 'candidate');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control') ?? '');
        $this->getJson('/api/platform/integrations/whatsapp/accounts/primary/qr')->assertNotFound();
    }

    public function test_unauthorized_disconnect_and_invalid_account_are_rejected(): void
    {
        $viewer = $this->makeOperator(['platform.integrations.whatsapp.view']);
        $this->actingAs($viewer, 'platform-web')
            ->postJson('/api/platform/integrations/whatsapp/accounts/primary/disconnect')
            ->assertForbidden();

        $manager = $this->makeOperator([
            'platform.integrations.whatsapp.view',
            'platform.integrations.whatsapp.manage',
        ]);
        $this->actingAs($manager, 'platform-web')
            ->getJson('/api/platform/integrations/whatsapp/accounts/rogue/status')
            ->assertUnprocessable();
    }

    public function test_events_are_isolated_per_account(): void
    {
        $operator = $this->makeOperator([
            'platform.integrations.whatsapp.view',
            'platform.integrations.whatsapp.manage',
        ]);
        $this->actingAs($operator, 'platform-web')
            ->postJson('/api/platform/integrations/whatsapp/accounts/primary/connect')
            ->assertOk();

        $primary = $this->actingAs($operator, 'platform-web')
            ->getJson('/api/platform/integrations/whatsapp/accounts/primary/events')
            ->assertOk()->json('events');
        $secondary = $this->actingAs($operator, 'platform-web')
            ->getJson('/api/platform/integrations/whatsapp/accounts/secondary/events')
            ->assertOk()->json('events');

        $this->assertNotEmpty($primary);
        $this->assertEmpty($secondary);
        foreach ($primary as $event) {
            $this->assertSame('primary', $event['slot']);
        }
    }

    /** @param list<string> $permissionNames */
    private function makeOperator(array $permissionNames): PlatformUser
    {
        $id = DB::connection('mysql')->table('platform_users')->insertGetId([
            'name' => 'WA Operator',
            'email' => 'wa-op-'.uniqid('', true).'@example.test',
            'password' => Hash::make('password-password'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $operator = PlatformUser::on('mysql')->findOrFail($id);

        $roleId = DB::connection('mysql')->table('platform_roles')->insertGetId([
            'name' => 'WA Role',
            'slug' => 'wa-role-'.uniqid('', true),
            'description' => null,
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($permissionNames as $name) {
            $permissionId = PlatformPermission::on('mysql')->firstOrCreate(
                ['name' => $name],
                ['description' => $name],
            )->getKey();
            DB::connection('mysql')->table('platform_role_permissions')->insertOrIgnore([
                'platform_role_id' => $roleId,
                'platform_permission_id' => $permissionId,
            ]);
        }
        DB::connection('mysql')->table('platform_user_roles')->insertOrIgnore([
            'platform_user_id' => $operator->getKey(),
            'platform_role_id' => $roleId,
        ]);

        return $operator->fresh();
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
        Schema::connection('mysql')->create('platform_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
        Schema::connection('mysql')->create('platform_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('description');
            $table->timestamps();
        });
        Schema::connection('mysql')->create('platform_role_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('platform_role_id');
            $table->unsignedBigInteger('platform_permission_id');
            $table->primary(['platform_role_id', 'platform_permission_id']);
        });
        Schema::connection('mysql')->create('platform_user_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('platform_user_id');
            $table->unsignedBigInteger('platform_role_id');
            $table->primary(['platform_user_id', 'platform_role_id']);
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
        DB::connection('mysql')->table('platform_whatsapp_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
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
            $table->boolean('replacement_pending')->default(false);
            $table->boolean('has_session_backup')->default(false);
            $table->timestamps();
        });
        foreach (['primary', 'secondary'] as $slot) {
            DB::connection('mysql')->table('platform_whatsapp_accounts')->insert([
                'slot' => $slot, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
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
