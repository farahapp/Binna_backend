<?php

declare(strict_types=1);

namespace Tests\Feature\Registration;

use App\Http\Controllers\Registration\RepresentativeAuthController;
use App\Registration\Representative;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

/**
 * Regression: sendInvite() must throw the *imported* Symfony ServiceUnavailableHttpException
 * when the configured frontend URL is invalid/non-HTTPS in production. Before the fix the
 * class was referenced unqualified with no `use` import, resolving to a non-existent local
 * class and causing a fatal Error instead of a controlled 503.
 */
class RepresentativeInviteDeliveryTest extends TestCase
{
    #[Test]
    public function non_https_frontend_url_in_production_throws_controlled_service_unavailable(): void
    {
        $originalEnvironment = $this->app['env'];
        $originalFrontendUrl = config('registration.frontend_url');
        $originalDefaultMailer = config('mail.default');

        $this->app['env'] = 'production';
        // A real (non-log) transport so the mail guard deliberately passes and the
        // frontend-URL validation branch is the one under test.
        config([
            'mail.default' => 'array',
            'registration.frontend_url' => 'http://insecure.example.test',
        ]);

        try {
            $representative = new Representative([
                'status' => 'active',
                'email' => 'rep@example.test',
            ]);

            $this->expectException(ServiceUnavailableHttpException::class);

            app(RepresentativeAuthController::class)->sendInvite($representative, 'password_reset');
        } finally {
            $this->app['env'] = $originalEnvironment;
            config([
                'registration.frontend_url' => $originalFrontendUrl,
                'mail.default' => $originalDefaultMailer,
            ]);
        }
    }
}
