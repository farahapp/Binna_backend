<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\IAM\User;
use App\Notification\Notification;
use App\Notification\Jobs\SendNotificationJob;
use App\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * NotificationHttpApiTest — Feature tests for TASK-002B / CAP-210 Notifications HTTP API Layer.
 *
 * Tests:
 *  - GET  /api/v1/notifications                      (list, unread_only filter, 401)
 *  - PATCH /api/v1/notifications/{id}/read           (mark as read, 404, tenant isolation, 401)
 *  - PATCH /api/v1/notifications/read-all            (mark all as read, 401)
 *  - GET  /api/v1/notifications/preferences          (list preferences, 401)
 *  - PUT  /api/v1/notifications/preferences          (set preference, validation, 401)
 *  - Tenant isolation (cross-tenant access → 404)
 *  - Queue/tenant context (SendNotificationJob carries tenantSlug)
 *
 * TASK-002B — Notifications Shared Service HTTP API Layer
 */
class NotificationHttpApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSlug = 'notification-http-test';
    private string $token;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        [$this->user, $this->token] = $this->createUserAndToken();
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('tenant')->table('notification_preferences')->delete();
            DB::connection('tenant')->table('notification_history')->delete();
            DB::connection('tenant')->table('notifications')->delete();
            DB::connection('tenant')->table('personal_access_tokens')->delete();
            DB::connection('tenant')->table('users')->delete();
            DB::connection('mysql')->table('tenants')->where('slug', $this->tenantSlug)->delete();
        } catch (\Throwable) {}
        parent::tearDown();
    }

    // =========================================================================
    // SETUP HELPERS
    // =========================================================================

    private function setUpTenant(): void
    {
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => $this->tenantSlug,
            'name'        => 'Notification HTTP Test Tenant',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        Config::set('database.connections.tenant.driver', 'mysql');
        Config::set('database.connections.tenant.host', '127.0.0.1');
        Config::set('database.connections.tenant.port', '3306');
        Config::set('database.connections.tenant.database', 'binaa2_testing');
        Config::set('database.connections.tenant.username', config('database.connections.mysql.username'));
        Config::set('database.connections.tenant.password', config('database.connections.mysql.password'));
        Config::set('database.connections.tenant.charset', 'utf8mb4');
        Config::set('database.connections.tenant.collation', 'utf8mb4_unicode_ci');
        Config::set('database.connections.tenant.prefix', '');
        Config::set('database.connections.tenant.strict', true);
        DB::purge('tenant');
    }

    private function createUserAndToken(): array
    {
        $user = User::on('tenant')->create([
            'name'     => 'Notification Test User',
            'email'    => 'notiftest@example.com',
            'password' => Hash::make('password'),
        ]);
        $token = $user->createToken('test-token')->plainTextToken;
        return [$user, $token];
    }

    private function authHeaders(): array
    {
        return [
            'X-Tenant'      => $this->tenantSlug,
            'Authorization' => 'Bearer ' . $this->token,
        ];
    }

    private function tenantHeaders(): array
    {
        return ['X-Tenant' => $this->tenantSlug];
    }

    /**
     * Create a Notification record directly in the tenant DB for test setup.
     *
     * @param string $userId    The notifiable user UUID.
     * @param array  $overrides Column overrides.
     * @return Notification
     */
    private function createNotification(string $userId, array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'tenant_slug'     => $this->tenantSlug,
            'notifiable_id'   => $userId,
            'notifiable_type' => 'user',
            'type'            => 'test.notification',
            'channel'         => 'database',
            'data'            => ['message' => 'Test notification payload'],
            'status'          => 'pending',
            'read_at'         => null,
        ], $overrides));
    }

    // =========================================================================
    // UNAUTHENTICATED — 401
    // =========================================================================

    public function test_list_notifications_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/v1/notifications', $this->tenantHeaders());

        $response->assertStatus(401);
    }

    public function test_mark_as_read_unauthenticated_returns_401(): void
    {
        $notification = $this->createNotification($this->user->id);

        $response = $this->patchJson(
            "/api/v1/notifications/{$notification->id}/read",
            [],
            $this->tenantHeaders()
        );

        $response->assertStatus(401);
    }

    public function test_mark_all_as_read_unauthenticated_returns_401(): void
    {
        $response = $this->patchJson('/api/v1/notifications/read-all', [], $this->tenantHeaders());

        $response->assertStatus(401);
    }

    public function test_get_preferences_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/v1/notifications/preferences', $this->tenantHeaders());

        $response->assertStatus(401);
    }

    public function test_set_preference_unauthenticated_returns_401(): void
    {
        $response = $this->putJson('/api/v1/notifications/preferences', [
            'notification_type' => 'approval.granted',
            'channel'           => 'database',
            'enabled'           => true,
        ], $this->tenantHeaders());

        $response->assertStatus(401);
    }

    // =========================================================================
    // GET /api/v1/notifications — LIST
    // =========================================================================

    public function test_list_notifications_authenticated_returns_200_with_data_meta(): void
    {
        $this->createNotification($this->user->id);
        $this->createNotification($this->user->id);

        $response = $this->getJson('/api/v1/notifications', $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'meta' => [
                'current_page',
                'last_page',
                'per_page',
                'total',
            ],
        ]);

        $this->assertIsArray($response->json('data'));
        $this->assertGreaterThanOrEqual(2, $response->json('meta.total'));
    }

    public function test_list_notifications_returns_only_current_user_notifications(): void
    {
        // Create notification for current user
        $this->createNotification($this->user->id, ['type' => 'user.own']);

        // Create a second user and their notification
        $otherUser = User::on('tenant')->create([
            'name'     => 'Other User',
            'email'    => 'other@example.com',
            'password' => Hash::make('password'),
        ]);
        $this->createNotification($otherUser->id, ['type' => 'user.other']);

        $response = $this->getJson('/api/v1/notifications', $this->authHeaders());

        $response->assertStatus(200);

        $types = collect($response->json('data'))->pluck('type')->all();
        $this->assertContains('user.own', $types);
        $this->assertNotContains('user.other', $types);
    }

    public function test_list_notifications_unread_only_filter_returns_only_unread(): void
    {
        // Create one unread and one read notification
        $this->createNotification($this->user->id, ['type' => 'unread.type', 'read_at' => null]);
        $this->createNotification($this->user->id, ['type' => 'read.type', 'read_at' => now()]);

        $response = $this->getJson('/api/v1/notifications?unread_only=1', $this->authHeaders());

        $response->assertStatus(200);

        $items = $response->json('data');
        foreach ($items as $item) {
            $this->assertNull($item['read_at'], 'Expected only unread notifications but found a read one.');
        }

        $types = collect($items)->pluck('type')->all();
        $this->assertContains('unread.type', $types);
        $this->assertNotContains('read.type', $types);
    }

    public function test_list_notifications_without_unread_only_returns_all(): void
    {
        $this->createNotification($this->user->id, ['type' => 'unread.type', 'read_at' => null]);
        $this->createNotification($this->user->id, ['type' => 'read.type', 'read_at' => now()]);

        $response = $this->getJson('/api/v1/notifications', $this->authHeaders());

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(2, $response->json('meta.total'));
    }

    // =========================================================================
    // PATCH /api/v1/notifications/{id}/read — MARK AS READ
    // =========================================================================

    public function test_mark_notification_as_read_sets_read_at(): void
    {
        $notification = $this->createNotification($this->user->id, ['read_at' => null]);

        $response = $this->patchJson(
            "/api/v1/notifications/{$notification->id}/read",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);

        // Verify read_at is now set in the database
        $updated = Notification::find($notification->id);
        $this->assertNotNull($updated->read_at, 'Expected read_at to be set after marking as read.');
    }

    public function test_mark_notification_as_read_returns_notification_data(): void
    {
        $notification = $this->createNotification($this->user->id, [
            'type'    => 'approval.granted',
            'read_at' => null,
        ]);

        $response = $this->patchJson(
            "/api/v1/notifications/{$notification->id}/read",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $notification->id);
    }

    public function test_mark_notification_as_read_non_existent_returns_404(): void
    {
        $response = $this->patchJson(
            '/api/v1/notifications/00000000-0000-0000-0000-000000000000/read',
            [],
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    public function test_mark_notification_as_read_belonging_to_another_user_returns_404(): void
    {
        // Create a second user and their notification
        $otherUser = User::on('tenant')->create([
            'name'     => 'Another User',
            'email'    => 'another@example.com',
            'password' => Hash::make('password'),
        ]);
        $otherNotification = $this->createNotification($otherUser->id);

        // Current user tries to mark the other user's notification as read
        $response = $this->patchJson(
            "/api/v1/notifications/{$otherNotification->id}/read",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    public function test_mark_already_read_notification_remains_idempotent(): void
    {
        $readAt = now()->subMinutes(5);
        $notification = $this->createNotification($this->user->id, ['read_at' => $readAt]);

        $response = $this->patchJson(
            "/api/v1/notifications/{$notification->id}/read",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(200);

        // read_at should not change (idempotent per markAsRead() implementation)
        $updated = Notification::find($notification->id);
        $this->assertNotNull($updated->read_at);
    }

    // =========================================================================
    // PATCH /api/v1/notifications/read-all — MARK ALL AS READ
    // =========================================================================

    public function test_mark_all_as_read_marks_all_unread_notifications(): void
    {
        $this->createNotification($this->user->id, ['read_at' => null]);
        $this->createNotification($this->user->id, ['read_at' => null]);
        $this->createNotification($this->user->id, ['read_at' => null]);

        $response = $this->patchJson('/api/v1/notifications/read-all', [], $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'All notifications marked as read.']);

        // Verify all notifications for this user are now read
        $unreadCount = Notification::where('tenant_slug', $this->tenantSlug)
            ->where('notifiable_id', $this->user->id)
            ->whereNull('read_at')
            ->count();

        $this->assertEquals(0, $unreadCount, 'Expected all notifications to be marked as read.');
    }

    public function test_mark_all_as_read_does_not_affect_other_users_notifications(): void
    {
        $otherUser = User::on('tenant')->create([
            'name'     => 'Other Mark User',
            'email'    => 'othermark@example.com',
            'password' => Hash::make('password'),
        ]);
        $otherNotification = $this->createNotification($otherUser->id, ['read_at' => null]);

        $this->patchJson('/api/v1/notifications/read-all', [], $this->authHeaders());

        // Other user's notification should remain unread
        $otherNotification->refresh();
        $this->assertNull($otherNotification->read_at, 'Other user\'s notification should not be affected.');
    }

    public function test_mark_all_as_read_with_no_unread_notifications_returns_200(): void
    {
        // All notifications already read
        $this->createNotification($this->user->id, ['read_at' => now()]);

        $response = $this->patchJson('/api/v1/notifications/read-all', [], $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'All notifications marked as read.']);
    }

    // =========================================================================
    // GET /api/v1/notifications/preferences — GET PREFERENCES
    // =========================================================================

    public function test_get_preferences_returns_200_with_data_structure(): void
    {
        $response = $this->getJson('/api/v1/notifications/preferences', $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
        $this->assertIsArray($response->json('data'));
    }

    public function test_get_preferences_returns_only_current_user_preferences(): void
    {
        // Set a preference for the current user via the API
        $this->putJson('/api/v1/notifications/preferences', [
            'notification_type' => 'approval.granted',
            'channel'           => 'database',
            'enabled'           => true,
        ], $this->authHeaders());

        // Create another user and set their preference directly
        $otherUser = User::on('tenant')->create([
            'name'     => 'Pref Other User',
            'email'    => 'prefother@example.com',
            'password' => Hash::make('password'),
        ]);
        \App\Notification\NotificationPreference::create([
            'tenant_slug'       => $this->tenantSlug,
            'user_id'           => $otherUser->id,
            'notification_type' => 'other.type',
            'channel'           => 'database',
            'enabled'           => true,
        ]);

        $response = $this->getJson('/api/v1/notifications/preferences', $this->authHeaders());

        $response->assertStatus(200);

        $notificationTypes = collect($response->json('data'))->pluck('notification_type')->all();
        $this->assertContains('approval.granted', $notificationTypes);
        $this->assertNotContains('other.type', $notificationTypes);
    }

    // =========================================================================
    // PUT /api/v1/notifications/preferences — SET PREFERENCE
    // =========================================================================

    public function test_set_preference_creates_preference_and_returns_200(): void
    {
        $response = $this->putJson('/api/v1/notifications/preferences', [
            'notification_type' => 'approval.granted',
            'channel'           => 'database',
            'enabled'           => true,
        ], $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
        $response->assertJsonPath('data.notification_type', 'approval.granted');
        $response->assertJsonPath('data.channel', 'database');
        $response->assertJsonPath('data.enabled', true);
    }

    public function test_set_preference_updates_existing_preference(): void
    {
        // Create initial preference
        $this->putJson('/api/v1/notifications/preferences', [
            'notification_type' => 'approval.granted',
            'channel'           => 'database',
            'enabled'           => true,
        ], $this->authHeaders());

        // Update it to disabled
        $response = $this->putJson('/api/v1/notifications/preferences', [
            'notification_type' => 'approval.granted',
            'channel'           => 'database',
            'enabled'           => false,
        ], $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJsonPath('data.enabled', false);

        // Verify only one record exists (updateOrCreate)
        $count = DB::connection('tenant')->table('notification_preferences')
            ->where('tenant_slug', $this->tenantSlug)
            ->where('user_id', $this->user->id)
            ->where('notification_type', 'approval.granted')
            ->where('channel', 'database')
            ->count();

        $this->assertEquals(1, $count, 'Expected exactly one preference record after update.');
    }

    public function test_set_preference_missing_notification_type_returns_422(): void
    {
        $response = $this->putJson('/api/v1/notifications/preferences', [
            // 'notification_type' intentionally omitted
            'channel' => 'database',
            'enabled' => true,
        ], $this->authHeaders());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['notification_type']);
    }

    public function test_set_preference_missing_channel_returns_422(): void
    {
        $response = $this->putJson('/api/v1/notifications/preferences', [
            'notification_type' => 'approval.granted',
            // 'channel' intentionally omitted
            'enabled' => true,
        ], $this->authHeaders());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['channel']);
    }

    public function test_set_preference_missing_enabled_returns_422(): void
    {
        $response = $this->putJson('/api/v1/notifications/preferences', [
            'notification_type' => 'approval.granted',
            'channel'           => 'database',
            // 'enabled' intentionally omitted
        ], $this->authHeaders());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['enabled']);
    }

    // =========================================================================
    // TENANT ISOLATION
    // =========================================================================

    public function test_user_from_tenant_a_cannot_read_notifications_of_tenant_b(): void
    {
        $tenantBSlug = 'notification-http-test-tenant-b';

        // Insert a second tenant record pointing to the same test DB
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => $tenantBSlug,
            'name'        => 'Notification HTTP Test Tenant B',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        try {
            // Create a notification that belongs to tenant B
            $tenantBNotification = Notification::create([
                'tenant_slug'     => $tenantBSlug,
                'notifiable_id'   => $this->user->id,
                'notifiable_type' => 'user',
                'type'            => 'tenant.b.notification',
                'channel'         => 'database',
                'data'            => ['message' => 'Tenant B notification'],
                'status'          => 'pending',
                'read_at'         => null,
            ]);

            // Tenant A user tries to mark tenant B's notification as read
            // The X-Tenant header identifies tenant A, so the controller scopes by tenant A's slug
            $response = $this->patchJson(
                "/api/v1/notifications/{$tenantBNotification->id}/read",
                [],
                $this->authHeaders() // X-Tenant = notification-http-test (tenant A)
            );

            // Must return 404 — tenant A cannot see tenant B's notification
            $response->assertStatus(404);
        } finally {
            // Clean up tenant B records
            DB::connection('tenant')->table('notifications')
                ->where('tenant_slug', $tenantBSlug)
                ->delete();
            DB::connection('mysql')->table('tenants')
                ->where('slug', $tenantBSlug)
                ->delete();
        }
    }

    public function test_list_notifications_only_returns_current_tenant_notifications(): void
    {
        $tenantBSlug = 'notification-http-test-tenant-b-list';

        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => $tenantBSlug,
            'name'        => 'Notification HTTP Test Tenant B List',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        try {
            // Create a notification for tenant A (current user)
            $this->createNotification($this->user->id, ['type' => 'tenant.a.type']);

            // Create a notification for tenant B (same user ID, different tenant_slug)
            Notification::create([
                'tenant_slug'     => $tenantBSlug,
                'notifiable_id'   => $this->user->id,
                'notifiable_type' => 'user',
                'type'            => 'tenant.b.type',
                'channel'         => 'database',
                'data'            => [],
                'status'          => 'pending',
                'read_at'         => null,
            ]);

            $response = $this->getJson('/api/v1/notifications', $this->authHeaders());

            $response->assertStatus(200);

            $types = collect($response->json('data'))->pluck('type')->all();
            $this->assertContains('tenant.a.type', $types);
            $this->assertNotContains('tenant.b.type', $types);
        } finally {
            DB::connection('tenant')->table('notifications')
                ->where('tenant_slug', $tenantBSlug)
                ->delete();
            DB::connection('mysql')->table('tenants')
                ->where('slug', $tenantBSlug)
                ->delete();
        }
    }

    // =========================================================================
    // QUEUE / TENANT CONTEXT — SendNotificationJob carries tenantSlug
    // =========================================================================

    public function test_send_notification_dispatches_job_with_correct_tenant_slug(): void
    {
        Bus::fake();

        /** @var NotificationService $service */
        $service = app(NotificationService::class);

        $service->send(
            tenantSlug: $this->tenantSlug,
            notifiableId: $this->user->id,
            notifiableType: 'user',
            type: 'approval.granted',
            channel: 'database',
            data: ['subject' => 'Test approval'],
        );

        Bus::assertDispatched(SendNotificationJob::class, function (SendNotificationJob $job): bool {
            return $job->tenantSlug === $this->tenantSlug;
        });
    }

    public function test_send_notification_job_carries_notification_id(): void
    {
        Bus::fake();

        /** @var NotificationService $service */
        $service = app(NotificationService::class);

        $notification = $service->send(
            tenantSlug: $this->tenantSlug,
            notifiableId: $this->user->id,
            notifiableType: 'user',
            type: 'task.assigned',
            channel: 'database',
            data: [],
        );

        Bus::assertDispatched(SendNotificationJob::class, function (SendNotificationJob $job) use ($notification): bool {
            return $job->notificationId === $notification->id
                && $job->tenantSlug === $this->tenantSlug;
        });
    }

    public function test_send_notification_job_dispatched_once_per_send_call(): void
    {
        Bus::fake();

        /** @var NotificationService $service */
        $service = app(NotificationService::class);

        $service->send(
            tenantSlug: $this->tenantSlug,
            notifiableId: $this->user->id,
            notifiableType: 'user',
            type: 'reminder.due',
            channel: 'database',
            data: [],
        );

        Bus::assertDispatchedTimes(SendNotificationJob::class, 1);
    }

    public function test_notification_service_creates_notification_record_in_db(): void
    {
        Bus::fake();

        /** @var NotificationService $service */
        $service = app(NotificationService::class);

        $notification = $service->send(
            tenantSlug: $this->tenantSlug,
            notifiableId: $this->user->id,
            notifiableType: 'user',
            type: 'document.uploaded',
            channel: 'database',
            data: ['filename' => 'report.pdf'],
        );

        $this->assertNotNull($notification->id);
        $this->assertEquals($this->tenantSlug, $notification->tenant_slug);
        $this->assertEquals($this->user->id, $notification->notifiable_id);
        $this->assertEquals('document.uploaded', $notification->type);
        $this->assertEquals('pending', $notification->status);

        // Verify it was persisted
        $found = Notification::find($notification->id);
        $this->assertNotNull($found);
        $this->assertEquals('document.uploaded', $found->type);
    }
}
