<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Audit\PlatformAuditEntry;
use App\Foundation\PlatformPermission;
use App\Foundation\PlatformRole;
use App\Foundation\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class PlatformDashboardAndAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePlatformAuditTable();
    }

    public function test_dashboard_returns_only_sections_allowed_by_permissions(): void
    {
        $operator = $this->makeOperator(['platform.tenants.view']);

        $this->actingAs($operator, 'platform-web')
            ->getJson('/api/platform/dashboard')
            ->assertOk()
            ->assertJsonStructure(['generated_at', 'sections'])
            ->assertJsonMissingPath('sections.users')
            ->assertJsonPath('sections.tenants.total', 0);
    }

    public function test_audit_index_requires_audit_view_permission(): void
    {
        $operator = $this->makeOperator(['platform.users.view']);

        $this->actingAs($operator, 'platform-web')
            ->getJson('/api/platform/audit-entries')
            ->assertForbidden();
    }

    public function test_audit_index_lists_entries_for_authorized_operator(): void
    {
        $operator = $this->makeOperator(['platform.audit.view']);
        PlatformAuditEntry::on('mysql')->create([
            'actor_id' => $operator->getKey(),
            'event' => 'platform.user.created',
            'subject_type' => 'platform_user',
            'subject_id' => (string) $operator->getKey(),
            'before' => null,
            'after' => ['ok' => true],
        ]);

        $this->actingAs($operator, 'platform-web')
            ->getJson('/api/platform/audit-entries')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.event', 'platform.user.created');
    }

    /** @param list<string> $permissionNames */
    private function makeOperator(array $permissionNames): PlatformUser
    {
        $operator = PlatformUser::on('mysql')->create([
            'name' => 'Dashboard Operator',
            'email' => 'dashboard-op-'.uniqid('', true).'@example.test',
            'password' => Hash::make('password-password'),
            'is_active' => true,
        ]);

        $role = PlatformRole::on('mysql')->create([
            'name' => 'Dashboard Role',
            'slug' => 'dashboard-role-'.uniqid(),
            'description' => null,
            'is_system' => false,
        ]);

        $permissionIds = [];
        foreach ($permissionNames as $name) {
            $permissionIds[] = PlatformPermission::on('mysql')->firstOrCreate(
                ['name' => $name],
                ['description' => $name],
            )->getKey();
        }
        $role->permissions()->sync($permissionIds);
        $operator->platformRoles()->sync([$role->getKey()]);

        return $operator->fresh();
    }

    private function ensurePlatformAuditTable(): void
    {
        if (Schema::connection('mysql')->hasTable('platform_audit_entries')) {
            return;
        }

        Schema::connection('mysql')->create('platform_audit_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }
}
