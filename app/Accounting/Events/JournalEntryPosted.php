<?php
declare(strict_types=1);
namespace App\Accounting\Events;

/**
 * JournalEntryPosted — fired when a Journal Entry is posted to the GL.
 * TASK-014B — Accounting Application/Service Layer
 */
class JournalEntryPosted
{
    public function __construct(
        public readonly string $entryId,
        public readonly string $reference,
        public readonly string $periodId,
        public readonly float $totalDebit,
        public readonly string $tenantSlug,
    ) {}
}
