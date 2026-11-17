<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Network\NetworkProfile;
use App\Network\NetworkProfileService;
use Tests\TestCase;

/**
 * E.8 — Public-card isolation contract (no DB required).
 * Live cross-tenant denial remains covered by scripts/e2e_e7_file_isolation.php.
 */
class NetworkProfileDirectoryIsolationTest extends TestCase
{
    public function test_public_card_helper_omits_private_fields(): void
    {
        $profile = new NetworkProfile([
            'company_name' => 'E8 Isolation Co',
            'profile_type' => 'contractor',
            'status' => 'active',
            'region' => 'Qatar',
            'contact_email' => 'secret-e8@example.test',
            'contact_phone' => '+974111',
            'managed_by_tenant_slug' => 'e8-isolation-tenant',
            'website' => 'https://example.test',
            'description' => 'public blurb',
        ]);

        $card = app(NetworkProfileService::class)->toPublicCard($profile);

        $this->assertSame('E8 Isolation Co', $card['company_name']);
        $this->assertSame('contractor', $card['profile_type']);
        $this->assertArrayNotHasKey('contact_email', $card);
        $this->assertArrayNotHasKey('contact_phone', $card);
        $this->assertArrayNotHasKey('managed_by_tenant_slug', $card);
    }
}
