<?php

declare(strict_types=1);

namespace App\Registration;

/**
 * Placeholder for future OCR assistance. No external/paid OCR is wired.
 * Reviewers remain the authority; this never auto-approves or auto-rejects.
 */
final class RegistrationOcrAssistance
{
    public function isAvailable(): bool
    {
        return false;
    }

    /**
     * @return array{available: bool, provider: null, message: string}
     */
    public function describe(): array
    {
        return [
            'available' => false,
            'provider' => null,
            'message' => 'OCR assistance is not configured. Human review remains required.',
        ];
    }
}
