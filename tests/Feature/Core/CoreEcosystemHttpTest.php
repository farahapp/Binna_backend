<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Property;
use App\Foundation\Tenant;
use App\IAM\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Core Ecosystem HTTP smoke — Property / Identity / isolation.
 * Requires phpunit MySQL harness (3307). Live 3306 probes used when harness blocked.
 */
class CoreEcosystemHttpTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSlug = 'core-eco-test';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Use the active test tenant DB (phpunit.xml 3307 / phpunit.local.xml 3306),
        // never a hard-coded foreign schema the harness user cannot USE.
        $schema = (string) config('database.connections.tenant.database', config('database.connections.mysql.database'));

        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug' => $this->tenantSlug,
            'name' => 'Core Eco Test',
            'schema_name' => $schema,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::on('tenant')->create([
            'name' => 'Core Tester',
            'email' => 'core-eco@example.com',
            'password' => Hash::make('password'),
            'tenant_slug' => $this->tenantSlug,
        ]);
        $this->token = $user->createToken('core-test')->plainTextToken;
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('mysql')->table('project_participations')->where('participant_tenant_slug', $this->tenantSlug)->delete();
            DB::connection('mysql')->table('project_identities')->where('created_by_tenant_slug', $this->tenantSlug)->delete();
            DB::connection('mysql')->table('properties')->where('owner_tenant_slug', $this->tenantSlug)->delete();
            DB::connection('tenant')->table('personal_access_tokens')->delete();
            DB::connection('tenant')->table('users')->where('email', 'core-eco@example.com')->delete();
            DB::connection('mysql')->table('tenants')->where('slug', $this->tenantSlug)->delete();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token,
            'X-Tenant' => $this->tenantSlug,
            'Accept' => 'application/json',
        ];
    }

    /** @test */
    public function owner_classification_is_property_owner(): void
    {
        $result = Tenant::classificationFor('organization', 'owner');
        $this->assertSame(Tenant::CLASSIFICATION_PROPERTY_OWNER, $result['classification']);
    }

    /** @test */
    public function create_property_and_identity_and_deny_foreign_property(): void
    {
        $create = $this->withHeaders($this->headers())
            ->postJson('/api/v1/core/properties', [
                'name' => 'Test Plot',
                'city' => 'Doha',
            ]);
        $create->assertStatus(201);
        $propertyId = $create->json('data.id');

        $identity = $this->withHeaders($this->headers())
            ->postJson('/api/v1/core/project-identities', [
                'name' => 'Test Project',
                'org_type' => 'owner',
                'property_id' => $propertyId,
            ]);
        $identity->assertStatus(201);
        $identity->assertJsonPath('data.verified_owner_tenant_slug', $this->tenantSlug);

        $foreign = Property::create([
            'owner_tenant_slug' => 'other-tenant',
            'name' => 'Foreign',
        ]);

        $denied = $this->withHeaders($this->headers())
            ->getJson('/api/v1/core/properties/'.$foreign->id);
        $denied->assertStatus(403);

        $foreign->delete();
    }
}
