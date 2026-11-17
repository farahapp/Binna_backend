<?php

declare(strict_types=1);

namespace App\Registration;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class RegistrationMailDeliveryGuard
{
    public function assertAvailable(): void
    {
        if (app()->environment('local', 'testing')) {
            return;
        }

        $pending = [(string) config('mail.default')];
        $visited = [];
        while ($mailerName = array_pop($pending)) {
            if (isset($visited[$mailerName])) {
                continue;
            }
            $visited[$mailerName] = true;
            $mailer = config('mail.mailers.'.$mailerName, []);
            if (($mailer['transport'] ?? null) === 'log') {
                throw new ServiceUnavailableHttpException(
                    null,
                    'Registration email delivery is temporarily unavailable.',
                );
            }
            foreach ($mailer['mailers'] ?? [] as $fallback) {
                $pending[] = (string) $fallback;
            }
        }
    }
}
