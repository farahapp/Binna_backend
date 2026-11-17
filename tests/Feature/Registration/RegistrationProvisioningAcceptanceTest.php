<?php

declare(strict_types=1);

namespace Tests\Feature\Registration;

use App\Foundation\Tenant;
use App\Foundation\DomainMigrator;
use App\Foundation\IamMigrator;
use App\Foundation\ProvisioningConnectionAccess;
use App\Foundation\TenantIdentifier;
use App\Foundation\TenantMigration;
use App\Foundation\TenantSchemaGrantor;
use App\Foundation\PlatformUser;
use App\IAM\User;
use App\Mail\RegistrationOtpMail;
use App\Registration\Applicant;
use App\Registration\RegistrationAuditService;
use App\Registration\TenantRegistrationRequest;
use App\Registration\TenantRegistrationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationProvisioningAcceptanceTest extends TestCase
{
    private ?int $requestId = null;

    private ?int $applicantId = null;

    private ?int $platformUserId = null;

    private ?int $tenantId = null;

    private ?string $schemaName = null;

    private array $additionalRequestIds = [];

    private array $additionalSchemaNames = [];

    protected function tearDown(): void
    {
        config(['database.connections.tenant.database' => config('database.connections.mysql.database')]);
        DB::purge('tenant');

        foreach ($this->additionalRequestIds as $requestId) {
            $tenantId = DB::connection('mysql')->table('tenant_registration_requests')
                ->where('id', $requestId)->value('tenant_id');
            DB::connection('mysql')->table('tenant_registration_events')
                ->where('request_id', $requestId)->delete();
            $identifiers = DB::connection('mysql')->table('tenant_identifiers')
                ->where('registration_request_id', $requestId);
            if ($tenantId !== null) {
                $identifiers->orWhere('tenant_id', $tenantId);
                DB::connection('mysql')->table('registration_identity_audit_events')
                    ->where('subject_type', 'tenant')->where('subject_id', $tenantId)->delete();
                DB::connection('mysql')->table('tenant_settings')->where('tenant_id', $tenantId)->delete();
                TenantMigration::on('mysql')->where('tenant_id', $tenantId)->delete();
            }
            $identifiers->delete();
            DB::connection('mysql')->table('tenant_registration_requests')->where('id', $requestId)->delete();
            if ($tenantId !== null) {
                Tenant::on('mysql')->whereKey($tenantId)->delete();
            }
        }

        if ($this->requestId !== null) {
            $tenantId = DB::connection('mysql')
                ->table('tenant_registration_requests')
                ->where('id', $this->requestId)
                ->value('tenant_id');
            $this->tenantId ??= $tenantId === null ? null : (int) $tenantId;

            DB::connection('mysql')->table('tenant_registration_events')
                ->where('request_id', $this->requestId)->delete();
            $identifiers = DB::connection('mysql')->table('tenant_identifiers')
                ->where('registration_request_id', $this->requestId);
            if ($this->tenantId !== null) {
                $identifiers->orWhere('tenant_id', $this->tenantId);
            }
            $identifiers->delete();

            if ($this->tenantId !== null) {
                DB::connection('mysql')->table('registration_identity_audit_events')
                    ->where('subject_type', 'tenant')
                    ->where('subject_id', $this->tenantId)
                    ->delete();
                DB::connection('mysql')->table('tenant_settings')
                    ->where('tenant_id', $this->tenantId)->delete();
                TenantMigration::on('mysql')->where('tenant_id', $this->tenantId)->delete();
            }

            DB::connection('mysql')->table('tenant_registration_requests')
                ->where('id', $this->requestId)->delete();

            if ($this->applicantId !== null) {
                DB::connection('mysql')->table('registration_identity_audit_events')
                    ->where('subject_type', 'applicant')
                    ->where('subject_id', $this->applicantId)
                    ->delete();
                Applicant::on('mysql')->whereKey($this->applicantId)->delete();
            }
            if ($this->platformUserId !== null) {
                PlatformUser::on('mysql')->whereKey($this->platformUserId)->delete();
            }

            if ($this->tenantId !== null) {
                Tenant::on('mysql')->whereKey($this->tenantId)->delete();
            }
        }

        $schemasToDrop = array_values(array_unique(array_filter([
            $this->schemaName,
            ...$this->additionalSchemaNames,
        ])));
        if ($schemasToDrop !== []) {
            $this->withRegistrationCommandArgv(function () use ($schemasToDrop): void {
                $this->app->make(ProvisioningConnectionAccess::class)
                    ->runForRegistrationCommand(function () use ($schemasToDrop): void {
                        $target = DB::connection('mysql_provisioning')->selectOne(
                            'SELECT @@port AS port, DATABASE() AS database_name',
                        );
                        $this->assertSame(3307, (int) $target->port);
                        $this->assertSame('binaa2_testing', $target->database_name);
                        foreach ($schemasToDrop as $schemaName) {
                            $this->assertMatchesRegularExpression('/^binaa2_tenant_[a-z0-9_]+$/', $schemaName);
                            DB::connection('mysql_provisioning')
                                ->statement("DROP DATABASE IF EXISTS `{$schemaName}`");
                        }
                    });
            });
        }

        parent::tearDown();
    }

    #[Test]
    public function approved_registration_is_provisioned_and_activated_by_the_authorized_command(): void
    {
        $this->assertLocalTestDatabaseTargets();

        $runtime = DB::connection('mysql')->selectOne(
            'SELECT @@port AS port, DATABASE() AS database_name',
        );
        $this->assertSame(3307, (int) $runtime->port);
        $this->assertSame('binaa2_testing', $runtime->database_name);

        $tenantConnection = DB::connection('tenant')->selectOne(
            'SELECT @@port AS port, DATABASE() AS database_name',
        );
        $this->assertSame(3307, (int) $tenantConnection->port);
        $this->assertSame('binaa2_testing', $tenantConnection->database_name);

        $provisioningConfig = config('database.provisioning_connection');
        $this->assertSame('127.0.0.1', $provisioningConfig['host']);
        $this->assertSame('3307', (string) $provisioningConfig['port']);
        $this->assertSame('binaa2_testing', $provisioningConfig['database']);
        $this->assertTrue(Schema::connection('mysql')->hasTable('tenant_registration_requests'));
        $this->assertTrue(Schema::connection('mysql')->hasTable('tenant_migrations'));

        $applicant = Applicant::on('mysql')->create([
            'email' => 'acceptance-'.strtolower(str()->random(8)).'@example.test',
            'email_verified_at' => now(),
        ]);
        $this->applicantId = $applicant->id;
        $registrationService = new TenantRegistrationService(new RegistrationAuditService());
        $registration = $registrationService->createDraft([
            'name' => 'Local Acceptance '.str()->random(8),
            'legal_type' => 'organization',
            'activity_type' => 'contractor',
            'responsible_name' => 'Test Owner',
            'identifiers' => [[
                'type' => 'cr',
                'number' => 'CR-ACCEPT-'.strtoupper(str()->random(8)),
            ]],
        ], $applicant);
        $this->requestId = $registration->id;
        $this->schemaName = Tenant::deriveSchemaName($registration->slug);
        $slug = $registration->slug;
        $registration = $registrationService->submit($registration, 'applicant', $applicant->id);
        $platformUser = PlatformUser::on('mysql')->create([
            'name' => 'Acceptance Reviewer',
            'email' => 'acceptance-reviewer-'.strtolower(str()->random(8)).'@example.test',
            'password' => 'a local test-only reviewer password',
            'is_active' => true,
        ]);
        $this->platformUserId = $platformUser->id;
        $registration = $registrationService->review(
            $registration,
            $platformUser,
            'start_review',
            'Local acceptance review started.',
        );
        $registration = $registrationService->review(
            $registration,
            $platformUser,
            'approve',
            'Synthetic local verification completed.',
            'local-test-reference',
        );
        $this->assertSame('approved_pending_provisioning', $registration->status);
        $this->assertNull($registration->tenant_id);
        $this->assertSame(0, Tenant::on('mysql')->where('slug', $slug)->count());

        $existingSchema = DB::connection('mysql')->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$this->schemaName],
        );
        $this->assertNull($existingSchema);

        $identifier = TenantIdentifier::on('mysql')
            ->where('registration_request_id', $registration->id)->firstOrFail();

        $grantVerifier = Mockery::mock(TenantSchemaGrantor::class);
        $grantVerifier->shouldReceive('verifyGrantedPrivileges')
            ->once()
            ->with(
                $this->schemaName,
                config('database.connections.tenant.username'),
                config('database.connections.mysql.host', '127.0.0.1'),
            )
            ->andThrow(new \RuntimeException('GRANT verification failed: mocked missing privileges.'));
        $grantVerifier->shouldReceive('verifyGrantedPrivileges')
            ->once()
            ->with(
                $this->schemaName,
                config('database.connections.tenant.username'),
                config('database.connections.mysql.host', '127.0.0.1'),
            )
            ->andReturnNull();
        $this->app->instance(TenantSchemaGrantor::class, $grantVerifier);
        $this->app->forgetInstance(TenantProvisioner::class);

        $hadArgv = array_key_exists('argv', $_SERVER);
        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'tenant:provision-registration'];

        try {
            $this->artisan('tenant:provision-registration', ['request' => $registration->id])
                ->assertExitCode(1);

            $registration->refresh();
            $identifier->refresh();
            $pendingTenant = Tenant::on('mysql')->findOrFail($registration->tenant_id);
            $this->tenantId = $pendingTenant->id;
            $this->assertSame('approved_pending_provisioning', $registration->status);
            $this->assertSame('pending', $pendingTenant->status);
            $this->assertSame($registration->id, $identifier->registration_request_id);
            $this->assertNull($identifier->tenant_id);
            $this->assertDatabaseMissing('tenant_registration_events', [
                'request_id' => $registration->id,
                'event' => 'provisioning.completed',
            ], 'mysql');

            $this->artisan('tenant:provision-registration', ['request' => $registration->id])
                ->assertSuccessful()
                ->expectsOutputToContain("Tenant {$slug} is active.");
            $this->artisan('tenant:provision-registration', ['request' => $registration->id])
                ->assertExitCode(1);
        } finally {
            if ($hadArgv) {
                $_SERVER['argv'] = $previousArgv;
            } else {
                unset($_SERVER['argv']);
            }
        }

        $registration->refresh();
        $identifier->refresh();
        $tenant = Tenant::on('mysql')->findOrFail($registration->tenant_id);
        $this->tenantId = $tenant->id;

        $this->assertSame('provisioned', $registration->status);
        $this->assertSame('active', $tenant->status);
        $this->assertSame($tenant->id, $identifier->tenant_id);
        $this->assertSame(1, Tenant::on('mysql')->whereKey($tenant->id)->count());
        $this->assertNull($identifier->registration_request_id);
        $this->assertDatabaseHas('tenant_registration_events', [
            'request_id' => $registration->id,
            'event' => 'provisioning.completed',
            'to_status' => 'provisioned',
        ], 'mysql');
        $this->assertDatabaseHas('registration_identity_audit_events', [
            'subject_type' => 'tenant',
            'subject_id' => $tenant->id,
            'event' => 'tenant.provisioned_from_registration',
        ], 'mysql');
        $this->assertNotNull(DB::connection('mysql')->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$this->schemaName],
        ));
        $this->assertGreaterThan(
            0,
            DB::connection('mysql')->table('tenant_migrations')
                ->where('tenant_id', $tenant->id)
                ->where('status', 'applied')
                ->count(),
        );
    }

    #[Test]
    public function individual_qid_registration_completes_otp_review_provisioning_and_tenant_login(): void
    {
        $this->assertLocalTestDatabaseTargets();
        Mail::fake();

        $email = 'individual-acceptance-'.strtolower(str()->random(8)).'@example.test';
        $this->postJson('/api/registration/applicant/otp/request', ['email' => $email])
            ->assertOk()
            ->assertJsonMissingPath('code')
            ->assertJsonMissingPath('token');

        $otp = null;
        Mail::assertSent(RegistrationOtpMail::class, function (RegistrationOtpMail $mail) use (&$otp): bool {
            $otp = $mail->code;

            return true;
        });
        $this->assertNotNull($otp);

        $verified = $this->postJson('/api/registration/applicant/otp/verify', [
            'email' => $email,
            'code' => $otp,
        ])->assertOk()
            ->assertJsonMissingPath('code')
            ->assertJsonMissingPath('email_verification_code');
        $token = $verified->json('token');
        $this->assertNotEmpty($token);
        $applicant = Applicant::on('mysql')->where('email', $email)->firstOrFail();
        $this->applicantId = $applicant->id;

        $draft = $this->withToken($token)->postJson('/api/registration/applicant/requests', [
            'name' => 'Individual Acceptance '.str()->random(8),
            'legal_type' => 'individual',
            'activity_type' => 'contractor',
            'responsible_name' => 'Verified Individual',
            'identifiers' => [[
                'type' => 'qid',
                'number' => '278'.random_int(10000000, 99999999),
            ]],
        ])->assertCreated()
            ->assertJsonPath('request.legal_type', 'individual')
            ->assertJsonPath('request.identifiers.0.type', 'qid');
        $requestId = (int) $draft->json('request.id');
        $this->requestId = $requestId;
        $registration = TenantRegistrationRequest::on('mysql')->findOrFail($requestId);
        $this->schemaName = Tenant::deriveSchemaName($registration->slug);
        $service = new TenantRegistrationService(new RegistrationAuditService());

        $this->withToken($token)->postJson("/api/registration/applicant/requests/{$requestId}/submit", [
            'declaration_confirmed' => true,
        ])->assertOk()->assertJsonPath('request.status', 'submitted');

        $reviewer = PlatformUser::on('mysql')->create([
            'name' => 'Individual Acceptance Reviewer',
            'email' => 'individual-reviewer-'.strtolower(str()->random(8)).'@example.test',
            'password' => Hash::make(str()->random(32)),
            'is_active' => true,
        ]);
        $this->platformUserId = $reviewer->id;
        $registration = $service->review($registration, $reviewer, 'start_review', 'Individual review started.');
        $registration = $service->review(
            $registration,
            $reviewer,
            'needs_information',
            'Please confirm the individual QID.',
        );
        $this->assertSame('needs_information', $registration->status);

        $this->withToken($token)->postJson("/api/registration/applicant/requests/{$requestId}/information", [
            'declaration_confirmed' => true,
            'name' => $registration->name,
            'legal_type' => 'individual',
            'activity_type' => 'contractor',
            'responsible_name' => $registration->responsible_name,
            'identifiers' => [[
                'type' => 'qid',
                'number' => $registration->identifiers()->where('type', 'qid')->value('number'),
            ]],
            'response' => 'The individual registration details are confirmed.',
        ])->assertOk()->assertJsonPath('request.status', 'submitted');

        $registration->refresh();
        $registration = $service->review($registration, $reviewer, 'start_review', 'Response reviewed.');
        $registration = $service->review(
            $registration,
            $reviewer,
            'approve',
            'Individual QID verified for local acceptance.',
            'local-individual-test-reference',
        );
        $this->assertSame('approved_pending_provisioning', $registration->status);
        $this->assertNull($registration->tenant_id);
        $this->assertSame(0, Tenant::on('mysql')->where('slug', $registration->slug)->count());
        $this->assertSame('valid', $registration->identifiers()->where('type', 'qid')->value('validity_status'));
        $this->assertNull(DB::connection('mysql')->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$this->schemaName],
        ));

        $grantVerifier = Mockery::mock(TenantSchemaGrantor::class);
        $grantVerifier->shouldReceive('verifyGrantedPrivileges')
            ->twice()
            ->andReturnNull();
        $this->app->instance(TenantSchemaGrantor::class, $grantVerifier);
        $this->app->forgetInstance(TenantProvisioner::class);

        $hadArgv = array_key_exists('argv', $_SERVER);
        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'tenant:provision-registration'];
        try {
            $this->artisan('tenant:provision-registration', ['request' => $requestId])
                ->assertSuccessful()
                ->expectsOutputToContain("Tenant {$registration->slug} is active.");
        } finally {
            if ($hadArgv) {
                $_SERVER['argv'] = $previousArgv;
            } else {
                unset($_SERVER['argv']);
            }
        }

        $registration->refresh();
        $tenant = Tenant::on('mysql')->findOrFail($registration->tenant_id);
        $this->tenantId = $tenant->id;
        $this->assertSame('provisioned', $registration->status);
        $this->assertSame('active', $tenant->status);
        $this->assertSame(
            $tenant->id,
            TenantIdentifier::on('mysql')->where('tenant_id', $tenant->id)->where('type', 'qid')->value('tenant_id'),
        );
        $this->assertGreaterThan(
            0,
            TenantMigration::on('mysql')->where('tenant_id', $tenant->id)->where('status', 'applied')->count(),
        );
        $this->assertDatabaseHas('tenant_registration_events', [
            'request_id' => $requestId,
            'event' => 'provisioning.completed',
            'to_status' => 'provisioned',
        ], 'mysql');

        config(['database.connections.tenant.database' => $tenant->schema_name]);
        DB::purge('tenant');
        foreach ([
            'schema_info',
            'users',
            'roles',
            'permissions',
            'model_has_roles',
            'personal_access_tokens',
            'audit_entries',
        ] as $table) {
            $this->assertTrue(Schema::connection('tenant')->hasTable($table), "Tenant baseline is missing {$table}.");
        }
        $this->assertSame(
            '001',
            DB::connection('tenant')->table('schema_info')->where('key', 'baseline_version')->value('value'),
        );
        $migrationBatches = TenantMigration::on('mysql')->where('tenant_id', $tenant->id)
            ->where('status', 'applied')->distinct()->orderBy('batch')->pluck('batch')->map(fn ($batch) => (int) $batch)->all();
        $this->assertSame([1, 2, 3], $migrationBatches);
        $this->assertSame(1, DB::connection('tenant')->table('roles')->where('name', 'tenant_admin')->count());
        $this->assertSame(0, DB::connection('tenant')->table('model_has_roles')->count());

        $tenantUserEmail = 'tenant-user-'.strtolower(str()->random(8)).'@example.test';
        User::on('tenant')->create([
            'name' => 'Individual Tenant User',
            'email' => $tenantUserEmail,
            'password' => Hash::make('local-acceptance-password'),
        ]);
        $tenantUser = User::on('tenant')->where('email', $tenantUserEmail)->firstOrFail();

        Auth::forgetGuards();
        $login = $this->postJson('/api/auth/login', [
            'email' => $tenantUser->email,
            'password' => 'local-acceptance-password',
        ], ['X-Tenant' => $tenant->slug])
            ->assertOk()
            ->assertJsonPath('tenant_slug', $tenant->slug);
        $tenantToken = $login->json('token');
        $this->assertNotEmpty($tenantToken);

        Auth::forgetGuards();
        $this->withToken($tenantToken)->getJson('/api/auth/me', [
            'X-Tenant' => $tenant->slug,
        ])->assertOk()
            ->assertJsonPath('id', $tenantUser->id)
            ->assertJsonPath('tenant_slug', $tenant->slug);
        $this->assertFalse(app()->bound(\App\Foundation\TenantContext::class));

        config(['database.connections.tenant.database' => config('database.connections.mysql.database')]);
        DB::purge('tenant');
        $this->assertLocalTestDatabaseTargets();

        $otherRegistration = $service->createDraft([
            'name' => 'Second Isolation Tenant '.str()->random(8),
            'legal_type' => 'organization',
            'activity_type' => 'contractor',
            'responsible_name' => 'Second Tenant Owner',
            'identifiers' => [[
                'type' => 'cr',
                'number' => 'CR-ISOLATION-'.strtoupper(str()->random(8)),
            ]],
        ], $applicant);
        $this->additionalRequestIds[] = $otherRegistration->id;
        $otherSchemaName = Tenant::deriveSchemaName($otherRegistration->slug);
        $this->additionalSchemaNames[] = $otherSchemaName;
        $service->submit($otherRegistration, 'applicant', $applicant->id);
        $otherRegistration = $service->review(
            $otherRegistration,
            $reviewer,
            'start_review',
            'Second tenant review started.',
        );
        $otherRegistration = $service->review(
            $otherRegistration,
            $reviewer,
            'approve',
            'Second tenant CR verified for local acceptance.',
            'local-second-tenant-reference',
        );
        $this->assertSame('approved_pending_provisioning', $otherRegistration->status);

        $hadArgv = array_key_exists('argv', $_SERVER);
        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'tenant:provision-registration'];
        try {
            $this->artisan('tenant:provision-registration', ['request' => $otherRegistration->id])
                ->assertSuccessful();
        } finally {
            if ($hadArgv) {
                $_SERVER['argv'] = $previousArgv;
            } else {
                unset($_SERVER['argv']);
            }
        }

        $otherRegistration->refresh();
        $otherTenant = Tenant::on('mysql')->findOrFail($otherRegistration->tenant_id);
        $this->assertSame('provisioned', $otherRegistration->status);
        $this->assertSame('active', $otherTenant->status);
        $this->assertNotSame($tenant->schema_name, $otherTenant->schema_name);

        $hadArgv = array_key_exists('argv', $_SERVER);
        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'tenant:provision-registration'];
        try {
            $this->app->make(ProvisioningConnectionAccess::class)
                ->runForRegistrationCommand(function () use ($tenant, $otherTenant): void {
                    $tables = DB::connection('mysql_provisioning')->select(
                        'SELECT TABLE_SCHEMA, TABLE_NAME
                         FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA IN (?, ?)
                           AND TABLE_NAME IN (?, ?, ?)',
                        [$tenant->schema_name, $otherTenant->schema_name, 'users', 'personal_access_tokens', 'audit_entries'],
                    );
                    $this->assertCount(6, $tables);

                    $crossSchemaForeignKeys = DB::connection('mysql_provisioning')->selectOne(
                        'SELECT COUNT(*) AS aggregate
                         FROM information_schema.KEY_COLUMN_USAGE
                         WHERE TABLE_SCHEMA IN (?, ?)
                           AND REFERENCED_TABLE_NAME IS NOT NULL
                           AND REFERENCED_TABLE_SCHEMA <> TABLE_SCHEMA',
                        [$tenant->schema_name, $otherTenant->schema_name],
                    );
                    $this->assertSame(0, (int) $crossSchemaForeignKeys->aggregate);
                });
        } finally {
            if ($hadArgv) {
                $_SERVER['argv'] = $previousArgv;
            } else {
                unset($_SERVER['argv']);
            }
        }

        $otherUserEmail = 'tenant-user-'.strtolower(str()->random(8)).'@example.test';
        config(['database.connections.tenant.database' => $otherTenant->schema_name]);
        DB::purge('tenant');
        User::on('tenant')->create([
            'name' => 'Second Tenant User',
            'email' => $otherUserEmail,
            'password' => Hash::make('second-local-acceptance-password'),
        ]);
        $otherLogin = $this->postJson('/api/auth/login', [
            'email' => $otherUserEmail,
            'password' => 'second-local-acceptance-password',
        ], ['X-Tenant' => $otherTenant->slug])
            ->assertOk()
            ->assertJsonPath('tenant_slug', $otherTenant->slug);
        $otherToken = $otherLogin->json('token');

        Auth::forgetGuards();
        $this->withToken($otherToken)->getJson('/api/auth/me', [
            'X-Tenant' => $otherTenant->slug,
        ])->assertOk()->assertJsonPath('tenant_slug', $otherTenant->slug);

        Auth::forgetGuards();
        $this->withToken($tenantToken)->getJson('/api/auth/me', [
            'X-Tenant' => $otherTenant->slug,
        ])->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($otherToken)->getJson('/api/auth/me', [
            'X-Tenant' => $tenant->slug,
        ])->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($tenantToken)->getJson('/api/auth/me')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($tenantToken)->getJson('/api/auth/me', [
            'X-Tenant' => 'nonexistent-'.str()->random(6),
        ])->assertNotFound();
    }

    #[Test]
    public function iam_and_domain_migration_failures_remain_pending_and_cli_retry_completes(): void
    {
        $this->assertLocalTestDatabaseTargets();

        $runtime = DB::connection('mysql')->selectOne('SELECT @@port AS port, DATABASE() AS database_name');
        $this->assertSame(3307, (int) $runtime->port);
        $this->assertSame('binaa2_testing', $runtime->database_name);
        $provisioningConfig = config('database.provisioning_connection');
        $this->assertSame('127.0.0.1', $provisioningConfig['host']);
        $this->assertSame('3307', (string) $provisioningConfig['port']);
        $this->assertSame('binaa2_testing', $provisioningConfig['database']);

        $slug = 'retry-'.strtolower(str()->random(8));
        $this->schemaName = Tenant::deriveSchemaName($slug);
        $this->assertNull(DB::connection('mysql')->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$this->schemaName],
        ));

        $registration = TenantRegistrationRequest::on('mysql')->create([
            'request_number' => 'TEST-MIGRATION-'.strtoupper(str()->random(8)),
            'slug' => $slug,
            'source' => 'self_service',
            'status' => 'approved_pending_provisioning',
            'applicant_email' => 'migration-retry@example.test',
            'name' => 'Migration Retry Tenant',
            'legal_type' => 'organization',
            'activity_type' => 'contractor',
            'responsible_name' => 'Test Owner',
        ]);
        $this->requestId = $registration->id;
        $identifier = TenantIdentifier::on('mysql')->create([
            'registration_request_id' => $registration->id,
            'type' => 'cr',
            'number' => 'CR-MIGRATION-'.strtoupper(str()->random(8)),
            'validity_status' => 'valid',
        ]);

        $iamMigrator = Mockery::mock(IamMigrator::class);
        $iamMigrator->shouldReceive('run')
            ->once()
            ->andThrow(new \RuntimeException('Injected local IAM initialization failure.'));
        $firstDomainMigrator = Mockery::mock(DomainMigrator::class);
        $firstDomainMigrator->shouldNotReceive('run');
        $this->app->instance(IamMigrator::class, $iamMigrator);
        $this->app->instance(DomainMigrator::class, $firstDomainMigrator);
        $grantVerifier = Mockery::mock(TenantSchemaGrantor::class);
        $grantVerifier->shouldReceive('verifyGrantedPrivileges')
            ->once()
            ->with(
                $this->schemaName,
                config('database.connections.tenant.username'),
                config('database.connections.mysql.host', '127.0.0.1'),
            )
            ->andReturnNull();
        $this->app->instance(TenantSchemaGrantor::class, $grantVerifier);
        $this->app->forgetInstance(\App\Foundation\TenantProvisioner::class);

        $hadArgv = array_key_exists('argv', $_SERVER);
        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'tenant:provision-registration'];
        try {
            $this->artisan('tenant:provision-registration', ['request' => $registration->id])
                ->assertExitCode(1);

            $registration->refresh();
            $identifier->refresh();
            $pendingTenant = Tenant::on('mysql')->findOrFail($registration->tenant_id);
            $this->tenantId = $pendingTenant->id;
            $this->assertSame('approved_pending_provisioning', $registration->status);
            $this->assertSame('pending', $pendingTenant->status);
            $this->assertSame($registration->id, $identifier->registration_request_id);
            $this->assertTrue(
                TenantMigration::on('mysql')->where('tenant_id', $pendingTenant->id)->exists(),
            );
            $this->assertDatabaseMissing('tenant_registration_events', [
                'request_id' => $registration->id,
                'event' => 'provisioning.completed',
            ], 'mysql');

            $domainMigrator = Mockery::mock(DomainMigrator::class);
            $domainMigrator->shouldReceive('run')
                ->once()
                ->andThrow(new \RuntimeException('Injected local domain migration failure.'));
            $this->app->instance(IamMigrator::class, new IamMigrator());
            $this->app->instance(DomainMigrator::class, $domainMigrator);
            $this->app->forgetInstance(\App\Foundation\TenantProvisioner::class);

            $this->artisan('tenant:provision-registration', ['request' => $registration->id])
                ->assertExitCode(1);
            $registration->refresh();
            $pendingTenant = Tenant::on('mysql')->findOrFail($registration->tenant_id);
            $this->assertSame('approved_pending_provisioning', $registration->status);
            $this->assertSame('pending', $pendingTenant->status);
            $this->assertGreaterThan(
                1,
                TenantMigration::on('mysql')->where('tenant_id', $pendingTenant->id)->where('status', 'applied')->count(),
            );
            $this->assertDatabaseMissing('tenant_registration_events', [
                'request_id' => $registration->id,
                'event' => 'provisioning.completed',
            ], 'mysql');

            $this->app->instance(DomainMigrator::class, new DomainMigrator());
            $this->app->forgetInstance(\App\Foundation\TenantProvisioner::class);

            $this->artisan('tenant:provision-registration', ['request' => $registration->id])
                ->assertSuccessful()
                ->expectsOutputToContain("Tenant {$slug} is active.");
        } finally {
            if ($hadArgv) {
                $_SERVER['argv'] = $previousArgv;
            } else {
                unset($_SERVER['argv']);
            }
        }

        $registration->refresh();
        $identifier->refresh();
        $tenant = Tenant::on('mysql')->findOrFail($registration->tenant_id);
        $this->tenantId = $tenant->id;
        $this->assertSame('provisioned', $registration->status);
        $this->assertSame('active', $tenant->status);
        $this->assertSame($tenant->id, $identifier->tenant_id);
        $this->assertNull($identifier->registration_request_id);
        $this->assertDatabaseHas('tenant_registration_events', [
            'request_id' => $registration->id,
            'event' => 'provisioning.completed',
            'to_status' => 'provisioned',
        ], 'mysql');
    }

    #[Test]
    public function unlinked_existing_schema_collision_fails_without_creating_a_tenant(): void
    {
        $this->assertLocalTestDatabaseTargets();

        $runtime = DB::connection('mysql')->selectOne('SELECT @@port AS port, DATABASE() AS database_name');
        $this->assertSame(3307, (int) $runtime->port);
        $this->assertSame('binaa2_testing', $runtime->database_name);
        $provisioningConfig = config('database.provisioning_connection');
        $this->assertSame('127.0.0.1', $provisioningConfig['host']);
        $this->assertSame('3307', (string) $provisioningConfig['port']);
        $this->assertSame('binaa2_testing', $provisioningConfig['database']);

        $slug = 'collision-'.strtolower(str()->random(8));
        $this->schemaName = Tenant::deriveSchemaName($slug);
        $this->assertNull(DB::connection('mysql')->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$this->schemaName],
        ));

        $hadArgv = array_key_exists('argv', $_SERVER);
        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'tenant:provision-registration'];
        try {
            $this->app->make(ProvisioningConnectionAccess::class)->runForRegistrationCommand(
                fn () => DB::connection('mysql_provisioning')->statement(
                    "CREATE DATABASE `{$this->schemaName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                ),
            );
        } finally {
            if ($hadArgv) {
                $_SERVER['argv'] = $previousArgv;
            } else {
                unset($_SERVER['argv']);
            }
        }

        $registration = TenantRegistrationRequest::on('mysql')->create([
            'request_number' => 'TEST-COLLISION-'.strtoupper(str()->random(8)),
            'slug' => $slug,
            'source' => 'self_service',
            'status' => 'approved_pending_provisioning',
            'applicant_email' => 'collision@example.test',
            'name' => 'Schema Collision Tenant',
            'legal_type' => 'organization',
            'activity_type' => 'contractor',
            'responsible_name' => 'Test Owner',
        ]);
        $this->requestId = $registration->id;
        TenantIdentifier::on('mysql')->create([
            'registration_request_id' => $registration->id,
            'type' => 'cr',
            'number' => 'CR-COLLISION-'.strtoupper(str()->random(8)),
            'validity_status' => 'valid',
        ]);

        $_SERVER['argv'] = ['artisan', 'tenant:provision-registration'];
        try {
            $this->artisan('tenant:provision-registration', ['request' => $registration->id])
                ->assertExitCode(1);
        } finally {
            if ($hadArgv) {
                $_SERVER['argv'] = $previousArgv;
            } else {
                unset($_SERVER['argv']);
            }
        }

        $registration->refresh();
        $this->assertSame('approved_pending_provisioning', $registration->status);
        $this->assertNull($registration->tenant_id);
        $this->assertSame(0, Tenant::on('mysql')->where('slug', $slug)->count());
    }

    #[Test]
    public function schema_creation_race_fails_closed_without_activating_the_tenant(): void
    {
        $this->assertLocalTestDatabaseTargets();

        $slug = 'create-race-'.strtolower(str()->random(8));
        $this->schemaName = Tenant::deriveSchemaName($slug);
        $registration = TenantRegistrationRequest::on('mysql')->create([
            'request_number' => 'TEST-CREATE-RACE-'.strtoupper(str()->random(8)),
            'slug' => $slug,
            'source' => 'self_service',
            'status' => 'approved_pending_provisioning',
            'applicant_email' => 'create-race@example.test',
            'name' => 'Schema Creation Race Tenant',
            'legal_type' => 'organization',
            'activity_type' => 'contractor',
            'responsible_name' => 'Test Owner',
        ]);
        $this->requestId = $registration->id;
        TenantIdentifier::on('mysql')->create([
            'registration_request_id' => $registration->id,
            'type' => 'cr',
            'number' => 'CR-CREATE-RACE-'.strtoupper(str()->random(8)),
            'validity_status' => 'valid',
        ]);

        $schemaChecks = 0;
        $injectedSchema = false;
        DB::listen(function (QueryExecuted $query) use (&$schemaChecks, &$injectedSchema): void {
            if (
                $injectedSchema
                || ! str_contains(strtolower($query->sql), 'information_schema.schemata')
                || ! in_array($this->schemaName, $query->bindings, true)
            ) {
                return;
            }

            $schemaChecks++;
            if ($schemaChecks === 2) {
                $schema = (string) $query->bindings[0];
                $query->connection->statement(
                    "CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
                );
                $injectedSchema = true;
            }
        });

        $hadArgv = array_key_exists('argv', $_SERVER);
        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'tenant:provision-registration'];
        try {
            $this->artisan('tenant:provision-registration', ['request' => $registration->id])
                ->assertExitCode(1);
        } finally {
            if ($hadArgv) {
                $_SERVER['argv'] = $previousArgv;
            } else {
                unset($_SERVER['argv']);
            }
        }

        $registration->refresh();
        $tenant = Tenant::on('mysql')->findOrFail($registration->tenant_id);
        $this->assertTrue($injectedSchema);
        $this->assertSame('approved_pending_provisioning', $registration->status);
        $this->assertSame('pending', $tenant->status);
        $this->assertSame(0, TenantMigration::on('mysql')->where('tenant_id', $tenant->id)->count());
        $this->assertNotNull(DB::connection('mysql')->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$this->schemaName],
        ));
    }

    #[Test]
    public function create_database_failure_leaves_registration_retryable_and_reuses_its_pending_tenant(): void
    {
        $this->assertLocalTestDatabaseTargets();
        $slug = 'create-failure-'.strtolower(str()->random(8));
        $this->schemaName = Tenant::deriveSchemaName($slug);

        $registration = TenantRegistrationRequest::on('mysql')->create([
            'request_number' => 'TEST-CREATE-FAILURE-'.strtoupper(str()->random(8)),
            'slug' => $slug,
            'source' => 'self_service',
            'status' => 'approved_pending_provisioning',
            'applicant_email' => 'create-failure@example.test',
            'name' => 'Create Database Failure Retry',
            'legal_type' => 'organization',
            'activity_type' => 'contractor',
            'responsible_name' => 'Test Owner',
        ]);
        $this->requestId = $registration->id;
        $identifier = TenantIdentifier::on('mysql')->create([
            'registration_request_id' => $registration->id,
            'type' => 'cr',
            'number' => 'CR-CREATE-FAILURE-'.strtoupper(str()->random(8)),
            'validity_status' => 'valid',
        ]);

        $injectedFailure = false;
        $this->withRegistrationCommandArgv(function () use ($registration, &$injectedFailure): void {
            $this->app->make(ProvisioningConnectionAccess::class)
                ->runForRegistrationCommand(function () use ($registration, &$injectedFailure): void {
                    DB::connection('mysql_provisioning')->beforeExecuting(
                        function (string $query) use (&$injectedFailure): void {
                            if (
                                ! $injectedFailure
                                && str_starts_with(strtoupper(ltrim($query)), 'CREATE DATABASE')
                                && str_contains($query, $this->schemaName)
                            ) {
                                $injectedFailure = true;
                                throw new \RuntimeException('Injected CREATE DATABASE failure.');
                            }
                        },
                    );

                    try {
                        $this->app->make(\App\Foundation\TenantProvisioner::class)
                            ->provisionRegistration($registration->id);
                        $this->fail('Provisioning must fail when CREATE DATABASE is rejected.');
                    } catch (\RuntimeException $exception) {
                        $this->assertStringContainsString(
                            'Injected CREATE DATABASE failure.',
                            $exception->getMessage(),
                        );
                    }
                });
        });

        $registration->refresh();
        $tenant = Tenant::on('mysql')->findOrFail($registration->tenant_id);
        $this->assertTrue($injectedFailure);
        $this->assertSame('approved_pending_provisioning', $registration->status);
        $this->assertSame('pending', $tenant->status);
        $this->assertSame($tenant->id, $registration->tenant_id);
        $this->assertSame($registration->id, $identifier->fresh()->registration_request_id);
        $this->assertSame(0, TenantMigration::on('mysql')->where('tenant_id', $tenant->id)->count());
        $this->assertNull(DB::connection('mysql')->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$this->schemaName],
        ));

        $grantVerifier = Mockery::mock(TenantSchemaGrantor::class);
        $grantVerifier->shouldReceive('verifyGrantedPrivileges')
            ->once()
            ->with(
                $this->schemaName,
                config('database.connections.tenant.username'),
                config('database.connections.mysql.host', '127.0.0.1'),
            )
            ->andReturnNull();
        $this->app->instance(TenantSchemaGrantor::class, $grantVerifier);
        $this->app->forgetInstance(\App\Foundation\TenantProvisioner::class);

        $this->withRegistrationCommandArgv(function () use ($registration, $slug): void {
            $this->artisan('tenant:provision-registration', ['request' => $registration->id])
                ->assertSuccessful()
                ->expectsOutputToContain("Tenant {$slug} is active.");
        });

        $registration->refresh();
        $tenant->refresh();
        $identifier->refresh();
        $this->assertSame('provisioned', $registration->status);
        $this->assertSame('active', $tenant->status);
        $this->assertSame($tenant->id, $identifier->tenant_id);
        $this->assertNull($identifier->registration_request_id);
        $this->assertSame(1, Tenant::on('mysql')->where('slug', $slug)->count());
        $this->assertNotNull(DB::connection('mysql')->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$this->schemaName],
        ));
        $migrationCount = TenantMigration::on('mysql')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'applied')
            ->count();
        $this->assertGreaterThan(0, $migrationCount);

        config(['database.connections.tenant.database' => $this->schemaName]);
        DB::purge('tenant');
        $this->assertSame(
            '001',
            DB::connection('tenant')->table('schema_info')->where('key', 'baseline_version')->value('value'),
        );
        $this->assertTrue(Schema::connection('tenant')->hasTable('users'));

        $this->withRegistrationCommandArgv(function () use ($registration, $tenant, $migrationCount): void {
            $this->artisan('tenant:provision-registration', ['request' => $registration->id])
                ->assertExitCode(1);
            $this->assertSame($tenant->id, $registration->fresh()->tenant_id);
            $this->assertSame('provisioned', $registration->fresh()->status);
            $this->assertSame('active', $tenant->fresh()->status);
            $this->assertSame(
                $migrationCount,
                TenantMigration::on('mysql')
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'applied')
                    ->count(),
            );
        });
    }

    private function assertLocalTestDatabaseTargets(): void
    {
        foreach (['mysql', 'tenant'] as $name) {
            $target = DB::connection($name)->selectOne(
                'SELECT @@port AS port, DATABASE() AS database_name',
            );
            $this->assertSame(3307, (int) $target->port);
            $this->assertSame('binaa2_testing', $target->database_name);
        }

        $config = config('database.provisioning_connection');
        $this->assertSame('127.0.0.1', $config['host'] ?? null);
        $this->assertSame('3307', (string) ($config['port'] ?? ''));
        $this->assertSame('binaa2_testing', $config['database'] ?? null);

        $hadArgv = array_key_exists('argv', $_SERVER);
        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'tenant:provision-registration'];
        try {
            $this->app->make(ProvisioningConnectionAccess::class)
                ->runForRegistrationCommand(function (): void {
                    $target = DB::connection('mysql_provisioning')->selectOne(
                        'SELECT @@port AS port, DATABASE() AS database_name',
                    );
                    $this->assertSame(3307, (int) $target->port);
                    $this->assertSame('binaa2_testing', $target->database_name);
                });
        } finally {
            if ($hadArgv) {
                $_SERVER['argv'] = $previousArgv;
            } else {
                unset($_SERVER['argv']);
            }
        }
    }

    private function withRegistrationCommandArgv(callable $callback): mixed
    {
        $hadArgv = array_key_exists('argv', $_SERVER);
        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'tenant:provision-registration'];
        try {
            return $callback();
        } finally {
            if ($hadArgv) {
                $_SERVER['argv'] = $previousArgv;
            } else {
                unset($_SERVER['argv']);
            }
        }
    }
}
