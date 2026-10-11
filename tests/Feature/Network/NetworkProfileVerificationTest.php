<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Audit\AuditEntry;
use App\Network\NetworkProfile;
use App\Network\NetworkProfileVerification;
use App\Network\NetworkProfileVerificationAttempt;
use App\Notification\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NetworkProfileVerificationTest extends TestCase
{
    private array $tenantCtx = [];
    private string $token = '';
    private string $tenantSlug = '';
    private string $password = 'NetworkVerificationTest!';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../bootstrap_iam_tenant.php';
        $this->tenantCtx = bootstrapAuthSmokeTenant($this->password);
        $this->tenantSlug = $this->tenantCtx['tenant']->slug;

        $migrator = app('migrator');
        if (! Schema::connection('mysql')->hasTable('network_profile_verifications')) {
            $migrator->setConnection('mysql');
            $migrator->run(database_path('migrations/network'));
        }
        if (! Schema::connection('tenant')->hasTable('notifications')) {
            $migrator->setConnection('tenant');
            $migrator->run(database_path('migrations/notification'));
        }

        $login = $this->withHeaders(['X-Tenant' => $this->tenantSlug])->postJson('/api/auth/login', [
            'email' => $this->tenantCtx['user']->email,
            'password' => $this->password,
        ]);
        $this->token = (string) $login->json('token');
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('mysql')->table('network_profile_verification_attempts')->delete();
            DB::connection('mysql')->table('network_profile_verifications')->delete();
            DB::connection('mysql')->table('network_profiles')->where('managed_by_tenant_slug', $this->tenantSlug)->delete();
            cleanupAuthSmokeTenant();
        } catch (\Throwable) {}
        parent::tearDown();
    }

    private function headers(): array
    {
        return ['X-Tenant' => $this->tenantSlug, 'Authorization' => 'Bearer ' . $this->token];
    }

    private function profile(string $type = 'supplier', ?string $tenant = null): NetworkProfile
    {
        return NetworkProfile::create([
            'company_name' => 'Verification Company',
            'profile_type' => $type,
            'status' => 'active',
            'managed_by_tenant_slug' => $tenant ?? $this->tenantSlug,
        ]);
    }

    private function evidence(string $type = 'supplier'): array
    {
        $evidence = ['business_registration' => 'document:registration-001'];
        if (in_array($type, ['engineer', 'contractor', 'main_contractor'], true)) {
            $evidence['professional_license'] = 'document:license-001';
        }
        return ['evidence' => $evidence];
    }

    private function grantReviewer(): void
    {
        $this->tenantCtx['user']->grantPermission('network.profile.verify');
    }

    private function submit(NetworkProfile $profile, string $type = 'supplier'): void
    {
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/network/profiles/{$profile->id}/verification", $this->evidence($type))
            ->assertCreated()->assertJsonPath('data.status', 'pending');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $profile = $this->profile();
        $this->withHeader('X-Tenant', $this->tenantSlug)
            ->postJson("/api/v1/network/profiles/{$profile->id}/verification", $this->evidence())
            ->assertUnauthorized();
    }

    public function test_authorized_owner_can_submit_unverified_to_pending(): void
    {
        $profile = $this->profile();
        $this->submit($profile);
        $this->assertDatabaseHas('network_profile_verifications', ['network_profile_id' => $profile->id, 'status' => 'pending'], 'mysql');
        $this->assertDatabaseHas('network_profile_verification_attempts', ['network_profile_id' => $profile->id, 'attempt_number' => 1, 'status' => 'pending'], 'mysql');
    }

    public function test_non_owner_cannot_submit_or_view_private_verification(): void
    {
        $profile = $this->profile('supplier', 'another-tenant');
        $this->withHeaders($this->headers())->postJson("/api/v1/network/profiles/{$profile->id}/verification", $this->evidence())->assertForbidden();
        $this->withHeaders($this->headers())->getJson("/api/v1/network/profiles/{$profile->id}/verification")->assertForbidden();
    }

    public function test_missing_business_registration_is_rejected(): void
    {
        $profile = $this->profile();
        $this->withHeaders($this->headers())->postJson("/api/v1/network/profiles/{$profile->id}/verification", ['evidence' => []])->assertUnprocessable();
    }

    public function test_professional_evidence_is_required_for_relevant_type(): void
    {
        $profile = $this->profile('contractor');
        $this->withHeaders($this->headers())->postJson("/api/v1/network/profiles/{$profile->id}/verification", ['evidence' => ['business_registration' => 'doc']])->assertUnprocessable();
    }

    public function test_unauthorized_reviewer_is_rejected(): void
    {
        $profile = $this->profile();
        $this->submit($profile);
        $this->withHeaders($this->headers())->patchJson("/api/v1/network/verification-requests/{$profile->id}/approve")->assertForbidden();
    }

    public function test_pending_can_be_approved_by_authorized_reviewer(): void
    {
        $profile = $this->profile();
        $this->submit($profile);
        $this->grantReviewer();
        $this->withHeaders($this->headers())->patchJson("/api/v1/network/verification-requests/{$profile->id}/approve")->assertOk()->assertJsonPath('data.status', 'verified');
    }

    public function test_pending_can_be_rejected_and_reason_is_private(): void
    {
        $profile = $this->profile();
        $this->submit($profile);
        $this->grantReviewer();
        $this->withHeaders($this->headers())->patchJson("/api/v1/network/verification-requests/{$profile->id}/reject", ['reason' => 'Registration mismatch'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->withHeaders($this->headers())->getJson("/api/v1/network/profiles/{$profile->id}/verification/status")
            ->assertOk()->assertJsonMissing(['decision_reason' => 'Registration mismatch']);
        $this->withHeaders($this->headers())->getJson("/api/v1/network/profiles/{$profile->id}/verification")
            ->assertOk()->assertJsonFragment(['decision_reason' => 'Registration mismatch']);
    }

    public function test_rejected_profile_can_resubmit_without_overwriting_history(): void
    {
        $profile = $this->profile();
        $this->submit($profile);
        $this->grantReviewer();
        $this->withHeaders($this->headers())->patchJson("/api/v1/network/verification-requests/{$profile->id}/reject", ['reason' => 'Mismatch'])->assertOk();
        $this->submit($profile);
        $this->assertSame(2, NetworkProfileVerificationAttempt::where('network_profile_id', $profile->id)->count());
        $this->assertDatabaseHas('network_profile_verification_attempts', ['network_profile_id' => $profile->id, 'attempt_number' => 1, 'status' => 'rejected'], 'mysql');
    }

    public function test_verified_profile_can_be_revoked_to_unverified(): void
    {
        $profile = $this->profile();
        $this->submit($profile);
        $this->grantReviewer();
        $this->withHeaders($this->headers())->patchJson("/api/v1/network/verification-requests/{$profile->id}/approve")->assertOk();
        $this->withHeaders($this->headers())->patchJson("/api/v1/network/verification-requests/{$profile->id}/revoke", ['reason' => 'Identity changed'])->assertOk()->assertJsonPath('data.status', 'unverified');
        $this->assertDatabaseHas('network_profiles', ['id' => $profile->id, 'status' => 'active'], 'mysql');
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $profile = $this->profile();
        $this->submit($profile);
        $this->withHeaders($this->headers())->postJson("/api/v1/network/profiles/{$profile->id}/verification", $this->evidence())->assertUnprocessable();
        $this->grantReviewer();
        $this->withHeaders($this->headers())->patchJson("/api/v1/network/verification-requests/{$profile->id}/revoke", ['reason' => 'Invalid'])->assertUnprocessable();
    }

    public function test_reviewer_can_view_evidence_but_public_status_cannot(): void
    {
        $profile = $this->profile();
        $this->submit($profile);
        $this->grantReviewer();
        $this->withHeaders($this->headers())->getJson("/api/v1/network/verification-requests/{$profile->id}")->assertOk()->assertJsonFragment(['business_registration' => 'document:registration-001']);
        $this->withHeaders($this->headers())->getJson("/api/v1/network/profiles/{$profile->id}/verification/status")->assertOk()->assertJsonMissing(['business_registration' => 'document:registration-001']);
    }

    public function test_required_audit_events_and_notifications_are_created(): void
    {
        $profile = $this->profile();
        $this->submit($profile);
        $this->grantReviewer();
        $this->withHeaders($this->headers())->patchJson("/api/v1/network/verification-requests/{$profile->id}/reject", ['reason' => 'Mismatch'])->assertOk();
        $this->submit($profile);
        $this->withHeaders($this->headers())->patchJson("/api/v1/network/verification-requests/{$profile->id}/approve")->assertOk();
        $this->withHeaders($this->headers())->patchJson("/api/v1/network/verification-requests/{$profile->id}/revoke", ['reason' => 'Changed'])->assertOk();

        foreach (['requested', 'rejected', 'resubmitted', 'approved', 'revoked'] as $action) {
            $event = "network.profile.verification.{$action}";
            $this->assertTrue(AuditEntry::where('event', $event)->exists(), "Missing audit {$event}");
            $this->assertTrue(Notification::where('type', $event)->exists(), "Missing notification {$event}");
        }
    }

    public function test_verification_state_does_not_change_profile_operational_state(): void
    {
        $profile = $this->profile();
        $this->submit($profile);
        $this->assertSame('active', $profile->fresh()->status);
        $this->assertSame('pending', NetworkProfileVerification::where('network_profile_id', $profile->id)->value('status'));
    }
}
