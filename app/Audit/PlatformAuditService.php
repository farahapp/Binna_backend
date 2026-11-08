<?php

declare(strict_types=1);

namespace App\Audit;

use App\Foundation\PlatformUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PlatformAuditService
{
    /**
     * Persist authentication events when the Platform Audit schema is available.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function recordIfAvailable(
        ?PlatformUser $actor,
        string $event,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        ?array $before = null,
        ?array $after = null,
    ): ?PlatformAuditEntry {
        if (! Schema::connection('mysql')->hasTable('platform_audit_entries')) {
            Log::warning('Platform audit event was not persisted because the audit schema is not installed.', [
                'event' => $event,
                'actor_id' => $actor?->getKey(),
            ]);

            return null;
        }

        return $this->record($actor, $event, $subjectType, $subjectId, $before, $after);
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        ?PlatformUser $actor,
        string $event,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        ?array $before = null,
        ?array $after = null,
    ): PlatformAuditEntry {
        return PlatformAuditEntry::create([
            'actor_id' => $actor?->getKey(),
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
