<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Browser navigations to /api/* send Accept: text/html. Guests must get 401,
 * not a 500 from redirecting to a missing named login route.
 */
class PlatformApiGuestAuthResponseTest extends TestCase
{
    #[Test]
    public function unauthenticated_html_request_to_platform_roles_returns_401_not_500(): void
    {
        $response = $this->get('/api/platform/roles', [
            'Accept' => 'text/html,application/xhtml+xml',
        ]);

        $response->assertUnauthorized();
        $response->assertJsonFragment(['message' => 'Unauthenticated.']);
    }

    #[Test]
    public function unauthenticated_json_request_to_platform_roles_returns_401(): void
    {
        $this->getJson('/api/platform/roles')
            ->assertUnauthorized()
            ->assertJsonFragment(['message' => 'Unauthenticated.']);
    }
}
