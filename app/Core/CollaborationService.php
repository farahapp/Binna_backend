<?php

declare(strict_types=1);

namespace App\Core;

use App\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Shared project collaboration: submit → consultant review → owner decision.
 * Consultant recommendations do NOT auto-become owner decisions.
 */
class CollaborationService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    public function submit(array $data, string $actorId, string $tenantSlug): ProjectSubmission
    {
        $identityId = $data['project_identity_id'];
        $this->assertActiveParticipation($identityId, $tenantSlug, ['contractor', 'main_contractor', 'subcontractor']);

        $submission = ProjectSubmission::create([
            'project_identity_id' => $identityId,
            'submitter_tenant_slug' => $tenantSlug,
            'submitter_participation_id' => $data['submitter_participation_id'] ?? null,
            'submission_type' => $data['submission_type'],
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'payload' => $data['payload'] ?? null,
            'status' => 'submitted',
        ]);

        $this->auditService->logEvent('core.submission.created', $actorId, [
            'tenant_slug' => $tenantSlug,
            'subject_type' => 'ProjectSubmission',
            'subject_id' => $submission->id,
            'after' => ['type' => $submission->submission_type, 'status' => 'submitted'],
        ]);

        return $submission;
    }

    public function review(string $submissionId, array $data, string $actorId, string $tenantSlug): ProjectReview
    {
        $submission = ProjectSubmission::findOrFail($submissionId);
        $this->assertActiveParticipation($submission->project_identity_id, $tenantSlug, ['consultant']);

        if ($submission->submitter_tenant_slug === $tenantSlug) {
            throw new \RuntimeException('Contractors cannot approve or review their own submissions as consultants.');
        }

        $outcome = $data['outcome'];
        $requiresOwner = (bool) ($data['requires_owner_decision'] ?? in_array($outcome, ['recommend_approve', 'recommend_reject'], true));

        return DB::connection('mysql')->transaction(function () use ($submission, $data, $actorId, $tenantSlug, $outcome, $requiresOwner) {
            $review = ProjectReview::create([
                'submission_id' => $submission->id,
                'project_identity_id' => $submission->project_identity_id,
                'reviewer_tenant_slug' => $tenantSlug,
                'reviewer_org_type' => 'consultant',
                'outcome' => $outcome,
                'comments' => $data['comments'] ?? null,
                'requires_owner_decision' => $requiresOwner,
            ]);

            $submission->status = $requiresOwner ? 'owner_pending' : 'recommended';
            $submission->save();

            $this->auditService->logEvent('core.submission.reviewed', $actorId, [
                'tenant_slug' => $tenantSlug,
                'subject_type' => 'ProjectReview',
                'subject_id' => $review->id,
                'after' => [
                    'outcome' => $outcome,
                    'requires_owner_decision' => $requiresOwner,
                    'submission_status' => $submission->status,
                ],
            ]);

            return $review;
        });
    }

    public function ownerDecide(string $submissionId, array $data, string $actorId, string $tenantSlug): ProjectOwnerDecision
    {
        $submission = ProjectSubmission::findOrFail($submissionId);
        $identity = ProjectIdentity::findOrFail($submission->project_identity_id);

        $isOwner = $identity->verified_owner_tenant_slug === $tenantSlug
            || ProjectParticipation::query()
                ->where('project_identity_id', $identity->id)
                ->where('participant_tenant_slug', $tenantSlug)
                ->where('organization_type', 'owner')
                ->where('status', ProjectParticipation::STATUS_ACTIVE)
                ->exists();

        if (! $isOwner) {
            throw new \RuntimeException('Only the verified owner may make this decision.');
        }

        if (! in_array($data['decision'], ['approve', 'reject', 'return'], true)) {
            throw new \InvalidArgumentException('Invalid decision.');
        }

        return DB::connection('mysql')->transaction(function () use ($submission, $data, $actorId, $tenantSlug) {
            $decision = ProjectOwnerDecision::create([
                'submission_id' => $submission->id,
                'review_id' => $data['review_id'] ?? null,
                'project_identity_id' => $submission->project_identity_id,
                'owner_tenant_slug' => $tenantSlug,
                'decision' => $data['decision'],
                'comments' => $data['comments'] ?? null,
            ]);

            $submission->status = match ($data['decision']) {
                'approve' => 'approved',
                'reject' => 'rejected',
                default => 'returned',
            };
            $submission->save();

            $this->auditService->logEvent('core.submission.owner_decided', $actorId, [
                'tenant_slug' => $tenantSlug,
                'subject_type' => 'ProjectOwnerDecision',
                'subject_id' => $decision->id,
                'after' => ['decision' => $decision->decision, 'submission_status' => $submission->status],
            ]);

            return $decision;
        });
    }

    public function listSubmissions(string $identityId, string $tenantSlug)
    {
        $this->assertCanViewProject($identityId, $tenantSlug);

        return ProjectSubmission::query()
            ->where('project_identity_id', $identityId)
            ->with(['reviews', 'decisions'])
            ->orderByDesc('created_at')
            ->get();
    }

    private function assertActiveParticipation(string $identityId, string $tenantSlug, array $roles): void
    {
        $ok = ProjectParticipation::query()
            ->where('project_identity_id', $identityId)
            ->where('participant_tenant_slug', $tenantSlug)
            ->where('status', ProjectParticipation::STATUS_ACTIVE)
            ->where(function ($q) use ($roles) {
                $q->whereIn('project_role', $roles)
                    ->orWhereIn('organization_type', $roles);
            })
            ->exists();

        if (! $ok) {
            throw new \RuntimeException('You are not an authorized active participant for this action.');
        }
    }

    private function assertCanViewProject(string $identityId, string $tenantSlug): void
    {
        $identity = ProjectIdentity::findOrFail($identityId);
        if ($identity->created_by_tenant_slug === $tenantSlug
            || $identity->verified_owner_tenant_slug === $tenantSlug) {
            return;
        }

        $ok = ProjectParticipation::query()
            ->where('project_identity_id', $identityId)
            ->where('participant_tenant_slug', $tenantSlug)
            ->whereIn('status', [
                ProjectParticipation::STATUS_ACTIVE,
                ProjectParticipation::STATUS_INVITED,
            ])
            ->exists();

        if (! $ok) {
            throw new \RuntimeException('You are not authorized to view submissions for this project.');
        }
    }
}
