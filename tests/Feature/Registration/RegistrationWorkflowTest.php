<?php

declare(strict_types=1);

namespace Tests\Feature\Registration;

use App\Foundation\PlatformUser;
use App\Foundation\Tenant;
use App\Foundation\TenantIdentifier;
use App\Mail\RegistrationOtpMail;
use App\Mail\RegistrationStatusChangedMail;
use App\Registration\Applicant;
use App\Registration\RegistrationTokenIssuer;
use App\Registration\Representative;
use App\Registration\RegistrationAuditService;
use App\Registration\TenantRegistrationEvent;
use App\Registration\TenantRegistrationRequest;
use App\Registration\TenantRegistrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationWorkflowTest extends TestCase
{
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
        ]);
        DB::purge('mysql');
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        parent::tearDown();
    }

    #[Test]
    public function approved_request_waits_for_manual_provisioning_without_creating_a_tenant(): void
    {
        $applicant = Applicant::on('mysql')->create([
            'email' => 'owner@example.test',
            'email_verified_at' => now(),
        ]);
        $request = $this->service()->createDraft([
            ...$this->companyData(),
            'applicant_email' => $applicant->email,
        ], $applicant);
        $actor = $this->platformUser();

        $this->service()->submit($request, 'applicant', $applicant->id);
        $request = $this->service()->review($request, $actor, 'start_review', 'Documents received.');
        $request = $this->service()->review($request, $actor, 'approve', 'Verified against official source.', 'verification-ref-123');

        $this->assertSame('approved_pending_provisioning', $request->status);
        $this->assertSame(0, Tenant::on('mysql')->count());
        $this->assertNull($request->tenant_id);
        $this->assertSame('valid', $request->identifiers()->where('type', 'cr')->value('validity_status'));
        $this->assertDatabaseHas('tenant_registration_events', [
            'request_id' => $request->id,
            'event' => 'review.approve',
            'to_status' => 'approved_pending_provisioning',
        ], 'mysql');
        $this->assertDatabaseMissing('tenant_registration_events', [
            'request_id' => $request->id,
            'event' => 'provisioning.completed',
        ], 'mysql');
    }

    #[Test]
    public function review_actions_notify_applicant_of_status_changes(): void
    {
        Mail::fake();
        $applicant = Applicant::on('mysql')->create([
            'email' => 'notify-owner@example.test',
            'email_verified_at' => now(),
        ]);
        $request = $this->service()->createDraft([
            ...$this->companyData(),
            'identifiers' => [['type' => 'cr', 'number' => 'CR-NOTIFY-1']],
            'applicant_email' => $applicant->email,
        ], $applicant);
        $actor = $this->platformUser();

        $this->service()->submit($request, 'applicant', $applicant->id);
        $request = $this->service()->review($request, $actor, 'start_review', 'Documents received.');
        Mail::assertSent(RegistrationStatusChangedMail::class, function (RegistrationStatusChangedMail $mail) use ($request): bool {
            return $mail->hasTo('notify-owner@example.test')
                && $mail->toStatus === 'under_review'
                && $mail->requestNumber === $request->request_number;
        });

        $request = $this->service()->review($request, $actor, 'needs_information', 'Upload clearer CR scan.');
        Mail::assertSent(RegistrationStatusChangedMail::class, function (RegistrationStatusChangedMail $mail): bool {
            return $mail->toStatus === 'needs_information'
                && $mail->note === 'Upload clearer CR scan.';
        });
    }

    #[Test]
    public function duplicate_cr_is_rejected_across_open_registration_requests(): void
    {
        $firstApplicant = Applicant::on('mysql')->create([
            'email' => 'first@example.test', 'email_verified_at' => now(),
        ]);
        $secondApplicant = Applicant::on('mysql')->create([
            'email' => 'second@example.test', 'email_verified_at' => now(),
        ]);
        $this->service()->createDraft([
            ...$this->companyData(),
            'applicant_email' => $firstApplicant->email,
        ], $firstApplicant);

        try {
            $this->service()->createDraft([
                ...$this->companyData(),
                'applicant_email' => $secondApplicant->email,
            ], $secondApplicant);
            $this->fail('A duplicate CR must be rejected before submission.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('identifiers', $exception->errors());
        }
    }

    #[Test]
    public function identifier_uniqueness_includes_rejected_requests_and_existing_tenants(): void
    {
        TenantIdentifier::on('mysql')->create([
            'tenant_id' => 999,
            'registration_request_id' => null,
            'type' => 'cr',
            'number' => 'cr-tenant-existing-123',
            'validity_status' => 'valid',
        ]);
        TenantIdentifier::on('mysql')->create([
            'tenant_id' => null,
            'registration_request_id' => 777,
            'type' => 'qid',
            'number' => '27800123456',
            'validity_status' => 'invalid',
        ]);

        foreach ([
            ['type' => 'cr', 'number' => 'CR TENANT EXISTING 123'],
            ['type' => 'qid', 'number' => '٢٧٨٠٠١٢٣٤٥٦'],
        ] as $identifier) {
            $applicant = Applicant::on('mysql')->create([
                'email' => uniqid('duplicate-').'@example.test',
                'email_verified_at' => now(),
            ]);
            try {
                $this->service()->createDraft([
                    ...$this->companyData(),
                    'identifiers' => [$identifier],
                    'applicant_email' => $applicant->email,
                ], $applicant);
                $this->fail('A globally registered identifier must not be reused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('identifiers', $exception->errors());
            }
        }
    }

    #[Test]
    public function concurrent_identifier_claim_is_translated_to_validation_failure(): void
    {
        $applicant = Applicant::on('mysql')->create([
            'email' => 'race-owner@example.test',
            'email_verified_at' => now(),
        ]);
        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            $sql = strtolower($query->sql);
            if ($injected || ! str_contains($sql, 'select exists') || ! str_contains($sql, 'tenant_identifiers')) {
                return;
            }

            $injected = true;
            DB::connection('mysql')->table('tenant_identifiers')->insert([
                'tenant_id' => null,
                'registration_request_id' => null,
                'type' => 'cr',
                'number' => 'CR-RACE-UNIQUE',
                'normalized_number' => 'CRRACEUNIQUE',
                'expires_at' => null,
                'validity_status' => 'unverified',
                'document_path' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $this->service()->createDraft([
                ...$this->companyData(),
                'identifiers' => [['type' => 'cr', 'number' => 'CR RACE UNIQUE']],
                'applicant_email' => $applicant->email,
            ], $applicant);
            $this->fail('The database unique constraint must reject a concurrent claim.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('identifiers', $exception->errors());
        }

        $this->assertTrue($injected);
        $this->assertSame(
            0,
            TenantIdentifier::on('mysql')->where('normalized_number', 'CRRACEUNIQUE')->count(),
            'The losing transaction must roll back without retaining partial registration data.',
        );
    }

    #[Test]
    public function individual_qid_is_required_and_expiry_and_attachments_remain_optional(): void
    {
        $applicant = Applicant::on('mysql')->create([
            'email' => 'individual@example.test', 'email_verified_at' => now(),
        ]);
        $data = $this->companyData();
        $data['legal_type'] = 'individual';
        $data['activity_type'] = 'contractor';
        $data['identifiers'] = [['type' => 'qid', 'number' => '27800123456']];
        $request = $this->service()->createDraft($data, $applicant);

        $submitted = $this->service()->submit($request, 'applicant', $applicant->id);

        $this->assertSame('submitted', $submitted->status);
        $this->assertNull($submitted->identifiers->first()->expires_at);
        $this->assertCount(0, $submitted->attachments);
    }

    #[Test]
    public function consultancy_requires_cr_and_professional_license(): void
    {
        $applicant = Applicant::on('mysql')->create([
            'email' => 'consultant@example.test', 'email_verified_at' => now(),
        ]);
        $data = $this->companyData();
        $data['activity_type'] = 'consultant';
        $request = $this->service()->createDraft($data, $applicant);

        try {
            $this->service()->submit($request, 'applicant', $applicant->id);
            $this->fail('An engineering consultancy must provide both required identifiers.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('identifiers', $exception->errors());
        }

        $data['identifiers'][] = ['type' => 'professional_license', 'number' => 'ENG-2345'];
        $complete = $this->service()->updateDraft($request, $data, 'applicant', $applicant->id);
        $this->assertSame('submitted', $this->service()->submit($complete, 'applicant', $applicant->id)->status);
    }

    #[Test]
    public function expired_identifier_cannot_be_submitted_or_approved(): void
    {
        $applicant = Applicant::on('mysql')->create([
            'email' => 'expired-identifier@example.test',
            'email_verified_at' => now(),
        ]);
        $data = $this->companyData();
        $data['identifiers'][0]['expires_at'] = now()->subDay()->toDateString();
        $request = $this->service()->createDraft([
            ...$data,
            'applicant_email' => $applicant->email,
        ], $applicant);

        try {
            $this->service()->submit($request, 'applicant', $applicant->id);
            $this->fail('An expired registration identifier cannot be submitted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('identifiers', $exception->errors());
        }

        $this->assertSame('draft', $request->fresh()->status);
    }

    #[Test]
    public function information_response_cannot_persist_identifier_incompatible_entity_type(): void
    {
        $applicant = Applicant::on('mysql')->create([
            'email' => 'type-change@example.test',
            'email_verified_at' => now(),
        ]);
        $request = $this->service()->createDraft([
            ...$this->companyData(),
            'applicant_email' => $applicant->email,
        ], $applicant);
        $this->service()->submit($request, 'applicant', $applicant->id);
        $reviewer = $this->platformUser();
        $this->service()->review($request, $reviewer, 'start_review', 'Review started.');
        $this->service()->review($request, $reviewer, 'needs_information', 'Please confirm entity type.');
        $token = app(RegistrationTokenIssuer::class)
            ->issue('registration_applicant_tokens', 'applicant_id', $applicant->id)['token'];

        $this->withToken($token)->postJson("/api/registration/applicant/requests/{$request->id}/information", [
            'declaration_confirmed' => true,
            'name' => 'Changed to Individual',
            'legal_type' => 'individual',
            'activity_type' => 'contractor',
            'responsible_name' => 'Accountable Owner',
            'identifiers' => [['type' => 'cr', 'number' => 'CR-1234567']],
            'response' => 'Please update this request.',
        ])->assertUnprocessable();

        $request->refresh();
        $this->assertSame('organization', $request->legal_type);
        $this->assertSame('needs_information', $request->status);
        $this->assertSame('cr', $request->identifiers()->value('type'));
    }

    #[Test]
    public function an_assisted_request_requires_recorded_owner_consent(): void
    {
        $representative = Representative::on('mysql')->create([
            'name' => 'Representative',
            'email' => 'rep@example.test',
            'status' => 'active',
            'password' => Hash::make('a secure test password'),
        ]);

        try {
            $this->service()->createDraft([
                ...$this->companyData(),
                'applicant_email' => 'owner@example.test',
                'owner_consent' => false,
            ], null, $representative);
            $this->fail('Assisted registration must require explicit owner consent.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('owner_consent', $exception->errors());
        }

        $request = $this->service()->createDraft([
            ...$this->companyData(),
            'applicant_email' => 'owner@example.test',
            'owner_consent' => true,
        ], null, $representative);
        $this->assertSame('representative', $request->source);
        $this->assertNotNull($request->owner_consent_at);
        $this->assertSame('tenant-registration-v1', $request->owner_consent_version);

        $owner = Applicant::on('mysql')->create([
            'email' => 'owner@example.test',
            'email_verified_at' => now(),
        ]);
        $this->assertSame(1, $this->service()->claimAssistedDrafts($owner));
        $claimed = $request->fresh();
        $this->assertSame($owner->id, $claimed->applicant_id);
        $this->assertDatabaseHas('tenant_registration_events', [
            'request_id' => $request->id,
            'event' => 'request.claimed_by_verified_owner',
        ], 'mysql');
    }

    #[Test]
    public function request_cannot_be_approved_twice_or_edited_after_approval(): void
    {
        $applicant = Applicant::on('mysql')->create([
            'email' => 'approval@example.test', 'email_verified_at' => now(),
        ]);
        $request = $this->service()->createDraft([
            ...$this->companyData(),
            'applicant_email' => $applicant->email,
        ], $applicant);
        $actor = $this->platformUser();
        $this->service()->submit($request, 'applicant', $applicant->id);
        $this->service()->review($request, $actor, 'start_review', 'Reviewed.');
        $approved = $this->service()->review($request, $actor, 'approve', 'Verified.', 'ref-987');

        $this->expectException(ValidationException::class);
        $this->service()->review($approved, $actor, 'approve', 'Duplicate approval.', 'ref-987');
    }

    #[Test]
    public function applicant_http_otp_binds_identity_and_registration_access_to_verified_owner(): void
    {
        Mail::fake();
        $email = 'owner-http@example.test';
        $this->postJson('/api/registration/applicant/otp/request', ['email' => $email])
            ->assertOk()
            ->assertJsonMissingPath('code')
            ->assertJsonMissingPath('token');

        $code = null;
        Mail::assertSent(RegistrationOtpMail::class, function (RegistrationOtpMail $mail) use (&$code, $email): bool {
            $this->assertTrue($mail->hasTo($email), 'OTP must be sent to the requesting Applicant email.');
            $code = $mail->code;

            return true;
        });
        $this->assertNotNull($code);
        $wrongCode = $code === '000000' ? '000001' : '000000';

        $this->postJson('/api/registration/applicant/otp/verify', [
            'email' => $email,
            'code' => $wrongCode,
        ])->assertUnprocessable();

        $verified = $this->postJson('/api/registration/applicant/otp/verify', [
            'email' => strtoupper($email),
            'code' => $code,
        ])->assertOk()->assertJsonPath('applicant.email', $email);
        $token = $verified->json('token');
        $this->assertNotEmpty($token);

        $this->withToken($token)->getJson('/api/registration/applicant/me')
            ->assertOk()
            ->assertJsonPath('applicant.email', $email);

        $created = $this->withToken($token)->postJson('/api/registration/applicant/requests', [
            ...$this->companyData(),
            'owner_consent' => true,
        ])->assertCreated();
        $requestId = $created->json('request.id');

        $this->withToken($token)->postJson("/api/registration/applicant/requests/{$requestId}/submit", [
            'declaration_confirmed' => true,
        ])->assertOk()->assertJsonPath('request.status', 'submitted');

        $individualData = $this->companyData();
        $individualData['name'] = 'Individual Contractor';
        $individualData['legal_type'] = 'individual';
        $individualData['identifiers'] = [['type' => 'qid', 'number' => '27800987654']];
        $individual = $this->withToken($token)->postJson('/api/registration/applicant/requests', $individualData)
            ->assertCreated()
            ->assertJsonPath('request.legal_type', 'individual')
            ->assertJsonPath('request.identifiers.0.type', 'qid')
            ->json('request.id');

        $this->withToken($token)->getJson('/api/registration/applicant/requests')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $requestId])
            ->assertJsonFragment(['id' => $individual]);

        $other = Applicant::on('mysql')->create([
            'email' => 'other-http@example.test',
            'email_verified_at' => now(),
        ]);
        $otherToken = app(RegistrationTokenIssuer::class)
            ->issue('registration_applicant_tokens', 'applicant_id', $other->id)['token'];
        Auth::forgetGuards();
        $this->withToken($otherToken)->getJson("/api/registration/applicant/requests/{$requestId}")
            ->assertNotFound();
        Auth::forgetGuards();
        $this->withToken($otherToken)->getJson('/api/registration/applicant/requests')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->clearOtpLimits($email);
        $this->postJson('/api/registration/applicant/otp/verify', [
            'email' => $email,
            'code' => $code,
        ])->assertUnprocessable();
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/registration/applicant/logout')->assertNoContent();
        Auth::forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson("/api/registration/applicant/requests/{$requestId}")
            ->assertUnauthorized();
    }

    #[Test]
    public function applicant_otp_expiry_attempt_cap_and_route_rate_limit_are_enforced(): void
    {
        Mail::fake();
        $email = 'locked-http@example.test';
        $this->clearOtpLimits($email);
        $this->postJson('/api/registration/applicant/otp/request', ['email' => $email])->assertOk();
        $code = null;
        Mail::assertSent(RegistrationOtpMail::class, function (RegistrationOtpMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });
        $wrongCode = $code === '000000' ? '000001' : '000000';
        $this->clearOtpLimits($email);
        $this->postJson('/api/registration/applicant/otp/request', ['email' => $email])
            ->assertTooManyRequests();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->clearOtpLimits($email);
            $this->postJson('/api/registration/applicant/otp/verify', [
                'email' => $email,
                'code' => $wrongCode,
            ])->assertUnprocessable();
        }
        $this->clearOtpLimits($email);
        $this->postJson('/api/registration/applicant/otp/verify', [
            'email' => $email,
            'code' => $code,
        ])->assertUnprocessable();

        $expiredEmail = 'expired-http@example.test';
        $this->clearOtpLimits($expiredEmail);
        $this->postJson('/api/registration/applicant/otp/request', ['email' => $expiredEmail])->assertOk();
        DB::connection('mysql')->table('registration_otp_challenges')
            ->where('email', $expiredEmail)->update(['expires_at' => now()->subSecond()]);
        $expiredCode = Mail::sent(RegistrationOtpMail::class)->last()->code;
        $this->clearOtpLimits($expiredEmail);
        $this->postJson('/api/registration/applicant/otp/verify', [
            'email' => $expiredEmail,
            'code' => $expiredCode,
        ])->assertUnprocessable();

        $this->clearOtpLimits('rate-0@example.test');
        for ($index = 0; $index < 5; $index++) {
            $this->postJson('/api/registration/applicant/otp/request', [
                'email' => "rate-{$index}@example.test",
            ])->assertOk();
        }
        $this->postJson('/api/registration/applicant/otp/request', [
            'email' => 'rate-limit@example.test',
        ])->assertTooManyRequests();
    }

    #[Test]
    public function applicant_otp_rejects_log_mail_transport_outside_testing(): void
    {
        $originalEnvironment = $this->app['env'];
        $originalDefaultMailer = config('mail.default');
        $originalLogMailer = config('mail.mailers.log');
        $this->app['env'] = 'production';
        config([
            'mail.default' => 'log',
            'mail.mailers.log.transport' => 'log',
        ]);

        try {
            $this->postJson('/api/registration/applicant/otp/request', [
                'email' => 'no-log-otp@example.test',
            ])->assertServiceUnavailable()
                ->assertJsonMissingPath('code')
                ->assertJsonMissingPath('token');
        } finally {
            $this->app['env'] = $originalEnvironment;
            config([
                'mail.default' => $originalDefaultMailer,
                'mail.mailers.log' => $originalLogMailer,
            ]);
        }
    }

    #[Test]
    public function applicant_otp_rate_limit_is_enforced_per_email_address(): void
    {
        $email = 'email-rate-limit@example.test';
        $this->clearOtpLimits($email);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/registration/applicant/otp/verify', [
                'email' => $email,
                'code' => '000000',
            ])->assertUnprocessable();
        }

        // Four requests are below the per-IP limit of five, so this 429 proves
        // the separate per-email limit of three is enforced.
        $this->postJson('/api/registration/applicant/otp/verify', [
            'email' => $email,
            'code' => '000000',
        ])->assertTooManyRequests();
    }

    #[Test]
    public function representative_http_session_is_scoped_and_cannot_review_registrations(): void
    {
        $representative = Representative::on('mysql')->create([
            'name' => 'Active Representative',
            'email' => 'rep-http@example.test',
            'status' => 'active',
            'password' => Hash::make('a secure representative password'),
        ]);
        $login = $this->postJson('/api/registration/representative/login', [
            'email' => strtoupper($representative->email),
            'password' => 'a secure representative password',
        ])->assertOk();
        $token = $login->json('token');
        $this->assertNotEmpty($token);

        $created = $this->withToken($token)->postJson('/api/registration/representative/requests', [
            ...$this->companyData(),
            'applicant_email' => 'owner-rep@example.test',
            'owner_consent' => true,
        ])->assertCreated();
        $requestId = $created->json('request.id');
        Storage::fake('registration_private');
        $this->withToken($token)->postJson("/api/registration/representative/requests/{$requestId}/attachments", [
            'file' => UploadedFile::fake()->create('company.pdf', 128, 'application/pdf'),
        ])->assertCreated();
        $this->withToken($token)->getJson('/api/registration/representative/requests')
            ->assertOk()
            ->assertJsonPath('data.0.id', $requestId);

        $other = Representative::on('mysql')->create([
            'name' => 'Other Representative',
            'email' => 'other-rep-http@example.test',
            'status' => 'active',
            'password' => Hash::make('another secure representative password'),
        ]);
        $otherToken = app(RegistrationTokenIssuer::class)
            ->issue('registration_representative_tokens', 'representative_id', $other->id)['token'];
        Auth::forgetGuards();
        $this->withToken($otherToken)->getJson("/api/registration/representative/requests/{$requestId}")
            ->assertNotFound();
        Auth::forgetGuards();
        $this->withToken($token)->postJson("/api/platform/tenant-registrations/{$requestId}/review", [
            'action' => 'approve',
            'note' => 'Not authorized.',
            'verification_reference' => 'test-reference',
        ])->assertUnauthorized();

        $admin = $this->platformUser();
        $adminToken = $this->issuePlatformToken($admin, ['platform.registration_representatives.suspend']);
        Auth::forgetGuards();
        $this->withToken($adminToken)->postJson("/api/platform/registration-representatives/{$representative->id}/suspend")
            ->assertOk()
            ->assertJsonPath('representative.status', 'suspended');
        $this->assertDatabaseMissing('registration_representative_tokens', [
            'representative_id' => $representative->id,
        ], 'mysql');

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/registration/representative/me')
            ->assertUnauthorized();

        $suspendedToken = app(RegistrationTokenIssuer::class)
            ->issue('registration_representative_tokens', 'representative_id', $representative->id)['token'];
        Auth::forgetGuards();
        $this->withToken($suspendedToken)->getJson('/api/registration/representative/me')
            ->assertUnauthorized();
    }

    #[Test]
    public function platform_admin_can_review_representative_assisted_registration_after_owner_verification(): void
    {
        $representative = Representative::on('mysql')->create([
            'name' => 'Assisted Registration Representative',
            'email' => 'assisted-lifecycle-rep@example.test',
            'status' => 'active',
            'password' => Hash::make('a secure representative password'),
        ]);
        $representativeToken = app(RegistrationTokenIssuer::class)
            ->issue('registration_representative_tokens', 'representative_id', $representative->id)['token'];

        $created = $this->withToken($representativeToken)->postJson('/api/registration/representative/requests', [
            ...$this->companyData(),
            'applicant_email' => 'assisted-lifecycle-owner@example.test',
            'owner_consent' => true,
        ])->assertCreated();
        $requestId = $created->json('request.id');

        $owner = Applicant::on('mysql')->create([
            'email' => 'assisted-lifecycle-owner@example.test',
            'email_verified_at' => now(),
        ]);
        $this->assertSame(1, $this->service()->claimAssistedDrafts($owner));

        Auth::forgetGuards();
        $this->withToken($representativeToken)->postJson("/api/platform/tenant-registrations/{$requestId}/review", [
            'action' => 'approve',
            'note' => 'Representatives cannot approve requests.',
            'verification_reference' => 'not-authorized',
        ])->assertUnauthorized();

        $this->withToken($representativeToken)->postJson("/api/registration/representative/requests/{$requestId}/submit", [
            'declaration_confirmed' => true,
        ])->assertOk()->assertJsonPath('request.status', 'submitted');

        $admin = $this->platformUser();
        $adminToken = $this->issuePlatformToken($admin, [
            'platform.tenant_registrations.view',
            'platform.tenant_registrations.review',
            'platform.tenant_registrations.identifiers.view',
        ]);
        Auth::forgetGuards();
        $this->withToken($adminToken)->postJson("/api/platform/tenant-registrations/{$requestId}/review", [
            'action' => 'start_review',
            'note' => 'Assisted request review started.',
        ])->assertOk()->assertJsonPath('request.status', 'under_review');
        $this->withToken($adminToken)->postJson("/api/platform/tenant-registrations/{$requestId}/review", [
            'action' => 'needs_information',
            'note' => 'Please provide the missing registration confirmation.',
        ])->assertOk()->assertJsonPath('request.status', 'needs_information');

        Auth::forgetGuards();
        $this->withToken($representativeToken)->postJson("/api/registration/representative/requests/{$requestId}/submit", [
            'declaration_confirmed' => true,
            'response' => 'The owner confirmed the requested registration details.',
        ])->assertOk()->assertJsonPath('request.status', 'submitted');

        Auth::forgetGuards();
        $this->withToken($adminToken)->postJson("/api/platform/tenant-registrations/{$requestId}/review", [
            'action' => 'start_review',
            'note' => 'The response is ready for final review.',
        ])->assertOk()->assertJsonPath('request.status', 'under_review');
        $this->withToken($adminToken)->postJson("/api/platform/tenant-registrations/{$requestId}/review", [
            'action' => 'approve',
            'note' => 'Registration identifiers verified.',
            'verification_reference' => 'assisted-verification-ref',
        ])->assertOk()->assertJsonPath('request.status', 'approved_pending_provisioning');

        $request = TenantRegistrationRequest::on('mysql')->findOrFail($requestId);
        $this->assertSame('representative', $request->source);
        $this->assertSame($owner->id, $request->applicant_id);
        $this->assertNull($request->tenant_id);
        $this->assertSame(0, Tenant::on('mysql')->count());
        $this->assertDatabaseMissing('tenant_registration_events', [
            'request_id' => $requestId,
            'event' => 'provisioning.completed',
        ], 'mysql');
    }

    #[Test]
    public function platform_http_review_records_transitions_and_approval_does_not_create_a_tenant(): void
    {
        $applicant = Applicant::on('mysql')->create([
            'email' => 'review-owner@example.test',
            'email_verified_at' => now(),
        ]);
        $request = $this->service()->createDraft([
            ...$this->companyData(),
            'applicant_email' => $applicant->email,
        ], $applicant);
        $this->service()->submit($request, 'applicant', $applicant->id);

        $readOnlyAdmin = $this->platformUser();
        $readOnlyToken = $this->issuePlatformToken($readOnlyAdmin, ['platform.tenant_registrations.view']);
        $this->withToken($readOnlyToken)->postJson("/api/platform/tenant-registrations/{$request->id}/review", [
            'action' => 'approve',
            'note' => 'Missing review permissions.',
            'verification_reference' => 'not-authorized',
        ])->assertForbidden();

        $admin = $this->platformUser();
        $token = $this->issuePlatformToken($admin, [
            'platform.tenant_registrations.view',
            'platform.tenant_registrations.review',
            'platform.tenant_registrations.identifiers.view',
        ]);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/platform/tenant-registrations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $request->id);
        $this->withToken($token)->getJson("/api/platform/tenant-registrations/{$request->id}")
            ->assertOk();
        $this->withToken($token)->postJson("/api/platform/tenant-registrations/{$request->id}/review", [
            'action' => 'start_review',
            'note' => 'Review started.',
        ])->assertOk()->assertJsonPath('request.status', 'under_review');
        $this->withToken($token)->postJson("/api/platform/tenant-registrations/{$request->id}/review", [
            'action' => 'needs_information',
            'note' => 'Please confirm the registration details.',
        ])->assertOk()->assertJsonPath('request.status', 'needs_information');

        $ownerToken = app(RegistrationTokenIssuer::class)
            ->issue('registration_applicant_tokens', 'applicant_id', $applicant->id)['token'];
        $this->withToken($ownerToken)->postJson("/api/registration/applicant/requests/{$request->id}/information", [
            ...$this->companyData(),
            'declaration_confirmed' => true,
            'response' => 'The requested details are confirmed.',
        ])->assertOk()->assertJsonPath('request.status', 'submitted');

        $this->withToken($token)->postJson("/api/platform/tenant-registrations/{$request->id}/review", [
            'action' => 'start_review',
            'note' => 'Response reviewed.',
        ])->assertOk();
        $this->withToken($token)->postJson("/api/platform/tenant-registrations/{$request->id}/review", [
            'action' => 'approve',
            'note' => 'Official details verified.',
            'verification_reference' => 'synthetic-verification-ref',
        ])->assertOk()->assertJsonPath('request.status', 'approved_pending_provisioning');

        $this->assertSame(0, Tenant::on('mysql')->count());
        $this->assertDatabaseHas('tenant_registration_events', [
            'request_id' => $request->id,
            'event' => 'review.approve',
            'to_status' => 'approved_pending_provisioning',
        ], 'mysql');
        $this->assertDatabaseHas('tenant_registration_events', [
            'request_id' => $request->id,
            'event' => 'request.list_viewed',
        ], 'mysql');

        $rejectedApplicant = Applicant::on('mysql')->create([
            'email' => 'rejected-owner@example.test',
            'email_verified_at' => now(),
        ]);
        $rejectedData = $this->companyData();
        $rejectedData['identifiers'] = [['type' => 'cr', 'number' => 'CR-REJECT-UNIQUE']];
        $rejected = $this->service()->createDraft([
            ...$rejectedData,
            'applicant_email' => $rejectedApplicant->email,
        ], $rejectedApplicant);
        $this->service()->submit($rejected, 'applicant', $rejectedApplicant->id);
        $this->withToken($token)->postJson("/api/platform/tenant-registrations/{$rejected->id}/review", [
            'action' => 'start_review',
            'note' => 'Review started.',
        ])->assertOk();
        $this->withToken($token)->postJson("/api/platform/tenant-registrations/{$rejected->id}/review", [
            'action' => 'reject',
            'note' => 'Required evidence was not provided.',
        ])->assertOk()->assertJsonPath('request.status', 'rejected');
        $this->assertDatabaseHas('tenant_registration_events', [
            'request_id' => $rejected->id,
            'event' => 'review.reject',
            'to_status' => 'rejected',
        ], 'mysql');
        $this->assertSame(0, Tenant::on('mysql')->count());
    }

    private function service(): TenantRegistrationService
    {
        return new TenantRegistrationService(new RegistrationAuditService());
    }

    private function clearOtpLimits(string $email): void
    {
        RateLimiter::clear(md5('registration-otpregistration-otp-ip:127.0.0.1'));
        RateLimiter::clear(md5('registration-otpregistration-otp-email:'.hash('sha256', strtolower($email))));
    }

    private function platformUser(): PlatformUser
    {
        return PlatformUser::on('mysql')->create([
            'name' => 'Reviewer',
            'email' => 'reviewer-'.(PlatformUser::on('mysql')->count() + 1).'@example.test',
            'password' => Hash::make('secret'),
            'is_active' => true,
        ]);
    }

    /**
     * @param array<int, string> $permissions
     */
    private function issuePlatformToken(PlatformUser $user, array $permissions): string
    {
        $roleId = DB::connection('mysql')->table('platform_roles')->insertGetId([
            'name' => 'Registration reviewer',
            'slug' => 'registration-reviewer-'.(DB::connection('mysql')->table('platform_roles')->count() + 1),
            'description' => 'Test role',
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('platform_user_roles')->insert([
            'platform_user_id' => $user->id,
            'platform_role_id' => $roleId,
        ]);
        foreach ($permissions as $permission) {
            $permissionId = DB::connection('mysql')->table('platform_permissions')
                ->where('name', $permission)->value('id');
            if ($permissionId === null) {
                $permissionId = DB::connection('mysql')->table('platform_permissions')->insertGetId([
                    'name' => $permission,
                    'description' => 'Test permission',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::connection('mysql')->table('platform_role_permissions')->insert([
                'platform_role_id' => $roleId,
                'platform_permission_id' => $permissionId,
            ]);
        }

        return $user->createPlatformToken('registration-review-test')->plainTextToken;
    }

    private function companyData(): array
    {
        return [
            'name' => 'Sample Construction',
            'legal_type' => 'organization',
            'activity_type' => 'contractor',
            'responsible_name' => 'Accountable Owner',
            'identifiers' => [['type' => 'cr', 'number' => 'CR-1234567']],
        ];
    }

    private function createSchema(): void
    {
        Schema::connection('mysql')->create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('legal_type')->nullable();
            $table->string('activity_type')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('responsible_name')->nullable();
            $table->string('responsible_qid')->nullable();
            $table->date('qid_expires_at')->nullable();
            $table->string('status');
            $table->string('schema_name')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('registration_applicants', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('registration_representatives', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('status');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('registration_otp_challenges', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->index();
            $table->string('code_hash');
            $table->timestamp('expires_at')->index();
            $table->timestamp('sent_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('registration_applicant_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('applicant_id');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('registration_representative_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('representative_id');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('registration_representative_invites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('representative_id');
            $table->string('purpose');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('registration_identity_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('event');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
        });
        Schema::connection('mysql')->create('tenant_registration_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('representative_id');
            $table->unsignedBigInteger('assigned_by');
            $table->timestamp('assigned_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['request_id', 'representative_id']);
        });
        Schema::connection('mysql')->create('platform_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::connection('mysql')->create('platform_personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('platform_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
        Schema::connection('mysql')->create('platform_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
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
        Schema::connection('mysql')->create('tenant_registration_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_number')->unique();
            $table->string('slug')->unique();
            $table->unsignedBigInteger('applicant_id')->nullable();
            $table->unsignedBigInteger('representative_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable()->unique();
            $table->string('source');
            $table->string('status')->index();
            $table->string('verification_status')->default('not_started');
            $table->string('approval_status')->default('pending');
            $table->string('applicant_email')->index();
            $table->string('name');
            $table->string('legal_type');
            $table->string('activity_type');
            $table->string('phone')->nullable();
            $table->string('responsible_name');
            $table->string('responsible_qid')->nullable();
            $table->date('qid_expires_at')->nullable();
            $table->timestamp('owner_consent_at')->nullable();
            $table->string('owner_consent_version')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('registration_document_requirements', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64);
            $table->string('label_ar', 255);
            $table->string('label_en', 255)->nullable();
            $table->string('legal_type', 32);
            $table->string('activity_type', 32);
            $table->boolean('is_required')->default(true);
            $table->boolean('expires')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
            $table->unique(['code', 'legal_type', 'activity_type']);
        });
        Schema::connection('mysql')->create('tenant_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('registration_request_id')->nullable();
            $table->string('type');
            $table->string('number');
            $table->string('normalized_number', 128);
            $table->date('expires_at')->nullable();
            $table->string('validity_status')->default('unverified');
            $table->string('document_path')->nullable();
            $table->timestamps();
            $table->unique(['type', 'normalized_number']);
        });
        Schema::connection('mysql')->create('tenant_registration_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::connection('mysql')->create('tenant_registration_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->string('document_type', 64)->nullable();
            $table->string('uploaded_by_type');
            $table->unsignedBigInteger('uploaded_by_id')->nullable();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('review_status', 32)->default('pending');
            $table->text('review_note')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('replaces_attachment_id')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();
        });
    }
}
