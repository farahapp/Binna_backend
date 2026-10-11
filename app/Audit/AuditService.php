<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * AuditService — Public Capability: logEvent(event, actor, context).
 *
 * This is the single authorized entry point for writing Audit Trail records
 * as defined by MOD-03 (A1) and TASK-001C.
 *
 * Architectural constraints:
 *  - Does NOT perform authentication, authorization, or tenant resolution.
 *  - Does NOT emit domain events (MOD-03: لا يُطلق Events — يستقبل فقط).
 *  - Does NOT implement Queue, Worker, or Durable Async Retry (NEW-003 deferred).
 *  - Tenant identity comes from the caller via $context['tenant_slug'] —
 *    the established tenant context mechanism (OD-001 / TASK-001A).
 *  - Actor identity comes from the caller via $actor — IAM User id (TASK-001B).
 *  - Synchronous persistence only. This does NOT satisfy NEW-003's
 *    Async Mandatory Durable Retry requirement; that is a deferred
 *    infrastructure capability dependent on Queue/Worker implementation.
 *
 * @see App\Audit\AuditEntry
 * @see docs/BINAA2_MODULE_BOUNDARIES.md MOD-03
 * @see docs/BINAA2_NEW003_AUDIT_WRITE_FAILURE_SEMANTICS_DECISION.md
 */
class AuditService
{
    /**
     * Log an audit event by creating an immutable AuditEntry.
     *
     * Contract (MOD-03 / A1):
     *   logEvent(event, actor, context)
     *
     * @param string      $event   The event name/type (e.g. 'auth.login', 'iam.permission_changed').
     * @param string|null $actor   The IAM actor_id (UUID of the User). Nullable for system events.
     * @param array{
     *     tenant_slug:   string,
     *     subject_type?: string|null,
     *     subject_id?:   string|null,
     *     before?:       array<string,mixed>|null,
     *     after?:        array<string,mixed>|null,
     * } $context  Contextual data for the event. tenant_slug is required.
     *
     * @throws \InvalidArgumentException If required context fields are missing.
     * @throws \Throwable                If AuditEntry persistence fails (not silently swallowed —
     *                                   failure behavior is propagated to the caller per TASK-001C
     *                                   scope; NEW-003 Durable Retry is a deferred capability).
     */
    public function logEvent(string $event, string|null $actor, array $context): AuditEntry
    {
        $this->validateContext($context);

        return AuditEntry::create([
            'event'        => $event,
            'actor_id'     => $actor,
            'tenant_slug'  => $context['tenant_slug'],
            'subject_type' => $context['subject_type'] ?? null,
            'subject_id'   => $context['subject_id']   ?? null,
            'before'       => $context['before']        ?? null,
            'after'        => $context['after']         ?? null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate that required context fields are present.
     *
     * @param array<string,mixed> $context
     * @throws \InvalidArgumentException
     */
    private function validateContext(array $context): void
    {
        if (empty($context['tenant_slug'])) {
            throw new \InvalidArgumentException(
                'AuditService::logEvent() requires a non-empty tenant_slug in $context.'
            );
        }
    }
}
