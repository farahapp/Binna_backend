<?php

declare(strict_types=1);

namespace App\Registration;

class RegistrationAuditService
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function record(
        int $requestId,
        string $actorType,
        int|string|null $actorId,
        string $event,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $message = null,
        ?array $metadata = null,
    ): TenantRegistrationEvent {
        return TenantRegistrationEvent::create([
            'request_id' => $requestId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'message' => $message,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function recordIdentity(
        string $actorType,
        int|string|null $actorId,
        string $subjectType,
        int|string $subjectId,
        string $event,
        ?array $metadata = null,
    ): void {
        TenantRegistrationIdentityAuditEvent::create([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event' => $event,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
