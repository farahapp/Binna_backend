<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Audit\AuditEntry;
use App\Audit\AuditService;
use App\Http\Middleware\SwitchToTenantSchema;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantIdentifierLoginIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.connections.tenant' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('mysql');
        DB::purge('tenant');

        Schema::connection('mysql')->create('tenants', function ($table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('schema_name');
            $table->string('status');
            $table->string('legal_type');
            $table->timestamps();
        });
        Schema::connection('mysql')->create('tenant_identifiers', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('type', 64);
            $table->string('number', 128);
            $table->string('normalized_number', 128);
            $table->date('expires_at')->nullable();
            $table->string('validity_status', 32)->default('unverified');
            $table->string('document_path')->nullable();
            $table->timestamps();
            $table->unique(['type', 'normalized_number']);
        });
        Schema::connection('tenant')->create('users', function ($table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
        Schema::connection('tenant')->create('personal_access_tokens', function ($table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        $this->withoutMiddleware(SwitchToTenantSchema::class);
    }

    public function test_valid_company_cr_resolves_and_logs_in_with_server_tenant_slug(): void
    {
        $this->seedTenant(
            legalType: 'organization',
            identifierType: 'cr',
            identifier: 'CR-123456',
            validityStatus: 'valid',
        );
        DB::connection('tenant')->table('users')->insert([
            'id' => '2ddf359a-5001-4c8c-82d7-643e0a8877c1',
            'name' => 'Test Company User',
            'email' => 'user@company.test',
            'password' => Hash::make('test-password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $audit = \Mockery::mock(AuditService::class);
        $audit->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());
        $this->app->instance(AuditService::class, $audit);

        $resolution = $this->postJson('/api/auth/tenant/resolve', [
            'identifier_type' => 'cr',
            'identifier' => 'cr 123456',
        ])->assertOk()->assertJsonStructure(['resolution_token']);

        $login = $this->postJson('/api/auth/tenant/login', [
            'resolution_token' => $resolution->json('resolution_token'),
            'email' => 'user@company.test',
            'password' => 'test-password',
        ])->assertOk()
            ->assertJsonPath('tenant_slug', 'company-test')
            ->assertJsonStructure(['token', 'token_type', 'tenant_slug']);

        $this->assertNotEmpty($login->json('token'));
    }

    public function test_valid_individual_qid_resolves_to_its_tenant(): void
    {
        $this->seedTenant(
            legalType: 'individual',
            identifierType: 'qid',
            identifier: '28100123456',
            validityStatus: 'valid',
        );
        $this->seedUser('individual@company.test');

        $resolution = $this->postJson('/api/auth/tenant/resolve', [
            'identifier_type' => 'qid',
            'identifier' => '٢٨-١٠٠١٢٣٤٥٦',
        ])->assertOk()->assertJsonStructure(['resolution_token']);

        $audit = \Mockery::mock(AuditService::class);
        $audit->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());
        $this->app->instance(AuditService::class, $audit);

        $this->postJson('/api/auth/tenant/login', [
            'resolution_token' => $resolution->json('resolution_token'),
            'email' => 'individual@company.test',
            'password' => 'test-password',
        ])->assertOk()->assertJsonPath('tenant_slug', 'company-test');
    }

    public function test_legacy_flutter_company_login_requires_valid_cr_but_keeps_slug_contract(): void
    {
        $this->seedTenant(
            legalType: 'organization',
            identifierType: 'cr',
            identifier: 'CR-123456',
            validityStatus: 'valid',
        );
        $this->seedUser('legacy-company@company.test');

        $audit = \Mockery::mock(AuditService::class);
        $audit->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());
        $this->app->instance(AuditService::class, $audit);

        $this->postJson('/api/auth/login', [
            'email' => 'legacy-company@company.test',
            'password' => 'test-password',
        ], ['X-Tenant' => 'company-test'])
            ->assertOk()
            ->assertJsonPath('tenant_slug', 'company-test');
    }

    public function test_legacy_flutter_individual_login_requires_valid_qid(): void
    {
        $this->seedTenant(
            legalType: 'individual',
            identifierType: 'qid',
            identifier: '28100123456',
            validityStatus: 'valid',
        );
        $this->seedUser('legacy-individual@company.test');

        $audit = \Mockery::mock(AuditService::class);
        $audit->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());
        $this->app->instance(AuditService::class, $audit);

        $this->postJson('/api/auth/login', [
            'email' => 'legacy-individual@company.test',
            'password' => 'test-password',
        ], ['X-Tenant' => 'company-test'])
            ->assertOk()
            ->assertJsonPath('tenant_slug', 'company-test');
    }

    public function test_legacy_spa_login_fails_closed_without_a_valid_required_identifier(): void
    {
        $this->seedTenant(
            legalType: 'organization',
            identifierType: 'cr',
            identifier: 'CR-123456',
            validityStatus: 'unverified',
        );

        $this->postJson('/api/auth/spa/login', [
            'email' => 'user@company.test',
            'password' => 'test-password',
        ], [
            'X-Tenant' => 'company-test',
            'Origin' => 'http://localhost:3000',
        ])->assertUnprocessable();
    }

    public function test_legacy_flutter_login_rejects_unverified_expired_and_wrong_type_identifiers(): void
    {
        $cases = [
            ['unverified-company', 'organization', 'cr', '123456', 'unverified', null],
            ['expired-company', 'organization', 'cr', '234567', 'valid', now()->subDay()->toDateString()],
            ['wrong-type-company', 'organization', 'qid', '345678', 'valid', null],
        ];

        foreach ($cases as [$slug, $legalType, $identifierType, $identifier, $validityStatus, $expiresAt]) {
            $this->seedTenant(
                legalType: $legalType,
                identifierType: $identifierType,
                identifier: $identifier,
                validityStatus: $validityStatus,
                expiresAt: $expiresAt,
                slug: $slug,
            );
            App::forgetInstance(\App\Foundation\TenantContext::class);

            $this->postJson('/api/auth/login', [
                'email' => 'legacy@company.test',
                'password' => 'test-password',
            ], ['X-Tenant' => $slug])->assertUnprocessable();
        }
    }

    public function test_legacy_flutter_login_does_not_guess_an_unknown_tenant(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'legacy@company.test',
            'password' => 'test-password',
        ], ['X-Tenant' => 'unknown-tenant'])
            ->assertNotFound();
    }

    public function test_cr_cannot_resolve_an_individual_tenant(): void
    {
        $this->seedTenant(
            legalType: 'individual',
            identifierType: 'cr',
            identifier: '123456',
            validityStatus: 'valid',
        );

        $this->postJson('/api/auth/tenant/resolve', [
            'identifier_type' => 'cr',
            'identifier' => '123456',
        ])->assertUnprocessable();
    }

    public function test_unverified_identifier_cannot_resolve(): void
    {
        $this->seedTenant(
            legalType: 'organization',
            identifierType: 'cr',
            identifier: '123456',
            validityStatus: 'unverified',
        );

        $this->postJson('/api/auth/tenant/resolve', [
            'identifier_type' => 'cr',
            'identifier' => '123456',
        ])->assertUnprocessable();
    }

    public function test_invalid_identifier_cannot_resolve(): void
    {
        $this->seedTenant(
            legalType: 'organization',
            identifierType: 'cr',
            identifier: '123456',
            validityStatus: 'invalid',
        );

        $this->postJson('/api/auth/tenant/resolve', [
            'identifier_type' => 'cr',
            'identifier' => '123456',
        ])->assertUnprocessable();
    }

    public function test_expired_identifier_cannot_resolve_even_if_marked_valid(): void
    {
        $this->seedTenant(
            legalType: 'organization',
            identifierType: 'cr',
            identifier: '123456',
            validityStatus: 'valid',
            expiresAt: now()->subDay()->toDateString(),
        );

        $this->postJson('/api/auth/tenant/resolve', [
            'identifier_type' => 'cr',
            'identifier' => '123456',
        ])->assertUnprocessable();
    }

    public function test_supporting_license_cannot_replace_the_required_cr(): void
    {
        $this->seedTenant(
            legalType: 'organization',
            identifierType: 'professional_license',
            identifier: '123456',
            validityStatus: 'valid',
        );

        $this->postJson('/api/auth/tenant/resolve', [
            'identifier_type' => 'cr',
            'identifier' => '123456',
        ])->assertUnprocessable();
    }

    public function test_same_number_is_resolved_by_identifier_type_not_ambiguous_value(): void
    {
        $this->seedTenant(
            legalType: 'organization',
            identifierType: 'cr',
            identifier: '123456',
            validityStatus: 'valid',
            slug: 'company-test',
        );
        $this->seedTenant(
            legalType: 'individual',
            identifierType: 'qid',
            identifier: '123456',
            validityStatus: 'valid',
            slug: 'individual-test',
        );

        $this->postJson('/api/auth/tenant/resolve', [
            'identifier_type' => 'cr',
            'identifier' => '123456',
        ])->assertOk()->assertJsonStructure(['resolution_token']);
        $this->postJson('/api/auth/tenant/resolve', [
            'identifier_type' => 'qid',
            'identifier' => '123456',
        ])->assertOk()->assertJsonStructure(['resolution_token']);
    }

    public function test_identifier_type_is_required_and_limited_to_cr_or_qid(): void
    {
        $this->postJson('/api/auth/tenant/resolve', [
            'identifier' => '123456',
        ])->assertUnprocessable()->assertJsonValidationErrors('identifier_type');

        $this->postJson('/api/auth/tenant/resolve', [
            'identifier_type' => 'establishment',
            'identifier' => '123456',
        ])->assertUnprocessable()->assertJsonValidationErrors('identifier_type');
    }

    private function seedTenant(
        string $legalType,
        string $identifierType,
        string $identifier,
        string $validityStatus,
        ?string $expiresAt = null,
        string $slug = 'company-test',
    ): void {
        $tenantId = DB::connection('mysql')->table('tenants')->insertGetId([
            'slug' => $slug,
            'name' => 'Test Tenant',
            'schema_name' => 'tenant_test',
            'status' => 'active',
            'legal_type' => $legalType,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('mysql')->table('tenant_identifiers')->insert([
            'tenant_id' => $tenantId,
            'type' => $identifierType,
            'number' => $identifier,
            'normalized_number' => \App\Foundation\TenantIdentifier::normalize($identifier),
            'validity_status' => $validityStatus,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedUser(string $email): void
    {
        DB::connection('tenant')->table('users')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Test Tenant User',
            'email' => $email,
            'password' => Hash::make('test-password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
