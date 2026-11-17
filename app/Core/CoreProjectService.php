<?php

declare(strict_types=1);

namespace App\Core;

use App\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CoreProjectService — Property, ProjectIdentity, Participation, Link, Engagement.
 * Shared-schema authority for Core Ecosystem multi-org projects.
 */
class CoreProjectService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    public function createProperty(array $data, string $actorId, string $tenantSlug): Property
    {
        $property = Property::create([
            'owner_tenant_slug' => $tenantSlug,
            'owner_network_profile_id' => $data['owner_network_profile_id'] ?? null,
            'name' => $data['name'],
            'property_type' => $data['property_type'] ?? 'real_estate',
            'address_line' => $data['address_line'] ?? null,
            'city' => $data['city'] ?? null,
            'region' => $data['region'] ?? null,
            'country' => $data['country'] ?? 'QA',
            'plot_number' => $data['plot_number'] ?? null,
            'title_deed_ref' => $data['title_deed_ref'] ?? null,
            'ownership_status' => $data['ownership_status'] ?? 'claimed',
            'metadata' => $data['metadata'] ?? null,
        ]);

        $this->auditService->logEvent('core.property.created', $actorId, [
            'tenant_slug' => $tenantSlug,
            'subject_type' => 'Property',
            'subject_id' => $property->id,
            'after' => ['name' => $property->name, 'ownership_status' => $property->ownership_status],
        ]);

        return $property;
    }

    public function listProperties(string $tenantSlug)
    {
        return Property::query()
            ->where('owner_tenant_slug', $tenantSlug)
            ->orderByDesc('created_at')
            ->paginate(20);
    }

    public function getProperty(string $id, string $tenantSlug): Property
    {
        $property = Property::findOrFail($id);
        if (! $property->isOwnedBy($tenantSlug)) {
            throw new \RuntimeException('You are not authorized to access this Property.');
        }

        return $property;
    }

    /**
     * Create a canonical project identity.
     * Owner may attach a property; contractor/consultant create execution/service identities without claiming ownership.
     *
     * @param  array{name:string,org_type:string,property_id?:string,description?:string,region?:string,canonical_code?:string}  $data
     */
    public function createProjectIdentity(array $data, string $actorId, string $tenantSlug): ProjectIdentity
    {
        $orgType = $data['org_type'];
        if (! in_array($orgType, ['owner', 'contractor', 'consultant'], true)) {
            throw new \InvalidArgumentException('org_type must be owner, contractor, or consultant.');
        }

        $propertyId = $data['property_id'] ?? null;
        $verifiedOwner = null;

        if ($propertyId !== null) {
            $property = Property::findOrFail($propertyId);
            if ($orgType === 'owner') {
                if (! $property->isOwnedBy($tenantSlug)) {
                    throw new \RuntimeException('You are not authorized to use this Property.');
                }
                $verifiedOwner = $tenantSlug;
            } else {
                // Non-owners may reference a property only as a match key candidate — not ownership.
                if (! $property->isOwnedBy($tenantSlug)) {
                    // Store as match evidence later; do not set verified owner.
                    $propertyId = $property->id;
                }
            }
        }

        if ($orgType === 'owner' && $propertyId === null) {
            // Owner may create identity without property, but ownership is not implied.
            $verifiedOwner = $tenantSlug;
        }

        return DB::connection('mysql')->transaction(function () use ($data, $actorId, $tenantSlug, $orgType, $propertyId, $verifiedOwner) {
            $identity = ProjectIdentity::create([
                'property_id' => $propertyId,
                'canonical_code' => $data['canonical_code'] ?? ('BINAA-'.strtoupper(Str::random(8))),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'region' => $data['region'] ?? null,
                'status' => 'active',
                'created_by_tenant_slug' => $tenantSlug,
                'created_by_org_type' => $orgType,
                'verified_owner_tenant_slug' => $verifiedOwner,
                'match_keys' => array_filter([
                    'property_id' => $propertyId,
                    'canonical_code' => $data['canonical_code'] ?? null,
                ]),
            ]);

            // Creator becomes an active participant in their role.
            $role = match ($orgType) {
                'owner' => 'owner',
                'consultant' => 'consultant',
                default => 'main_contractor',
            };

            ProjectParticipation::create([
                'project_identity_id' => $identity->id,
                'participant_tenant_slug' => $tenantSlug,
                'organization_type' => $orgType,
                'project_role' => $role,
                'status' => ProjectParticipation::STATUS_ACTIVE,
                'source' => $orgType.'_created',
                'effective_from' => now(),
                'access_scope' => ['workspace' => true, 'private_erp' => true],
            ]);

            $this->auditService->logEvent('core.project_identity.created', $actorId, [
                'tenant_slug' => $tenantSlug,
                'subject_type' => 'ProjectIdentity',
                'subject_id' => $identity->id,
                'after' => [
                    'name' => $identity->name,
                    'org_type' => $orgType,
                    'verified_owner' => $verifiedOwner,
                ],
            ]);

            return $identity->fresh(['participations']);
        });
    }

    public function listProjectIdentities(string $tenantSlug)
    {
        return ProjectIdentity::query()
            ->where(function ($q) use ($tenantSlug) {
                $q->where('created_by_tenant_slug', $tenantSlug)
                    ->orWhere('verified_owner_tenant_slug', $tenantSlug)
                    ->orWhereHas('participations', function ($p) use ($tenantSlug) {
                        $p->where('participant_tenant_slug', $tenantSlug)
                            ->whereIn('status', [
                                ProjectParticipation::STATUS_ACTIVE,
                                ProjectParticipation::STATUS_INVITED,
                                ProjectParticipation::STATUS_PENDING,
                            ]);
                    });
            })
            ->with('participations')
            ->orderByDesc('created_at')
            ->paginate(20);
    }

    public function getProjectIdentity(string $id, string $tenantSlug): ProjectIdentity
    {
        $identity = ProjectIdentity::with('participations')->findOrFail($id);
        $this->assertCanViewIdentity($identity, $tenantSlug);

        return $identity;
    }

    /**
     * Invite an organization to participate. Does not grant access until accepted/verified.
     */
    public function inviteParticipant(string $identityId, array $data, string $actorId, string $tenantSlug): ProjectParticipation
    {
        $identity = ProjectIdentity::findOrFail($identityId);
        $this->assertCanManageParticipation($identity, $tenantSlug);

        $inviteeSlug = $data['participant_tenant_slug'];
        $orgType = $data['organization_type'];
        $role = $data['project_role'];

        if ($inviteeSlug === $tenantSlug) {
            throw new \InvalidArgumentException('Cannot invite self.');
        }

        $inviteeExists = \App\Foundation\Tenant::on('mysql')
            ->where('slug', $inviteeSlug)
            ->where('status', 'active')
            ->exists();
        if (! $inviteeExists) {
            throw new \InvalidArgumentException('Invitee tenant is not active or does not exist.');
        }

        $existing = ProjectParticipation::query()
            ->where('project_identity_id', $identityId)
            ->where('participant_tenant_slug', $inviteeSlug)
            ->where('project_role', $role)
            ->first();

        if ($existing !== null) {
            throw new \RuntimeException('Participation already exists for this role.');
        }

        $participation = ProjectParticipation::create([
            'project_identity_id' => $identityId,
            'participant_tenant_slug' => $inviteeSlug,
            'participant_network_profile_id' => $data['participant_network_profile_id'] ?? null,
            'organization_type' => $orgType,
            'project_role' => $role,
            'status' => ProjectParticipation::STATUS_INVITED,
            'source' => 'invitation',
            'access_scope' => $data['access_scope'] ?? ['workspace' => true, 'private_erp' => false],
        ]);

        $this->auditService->logEvent('core.participation.invited', $actorId, [
            'tenant_slug' => $tenantSlug,
            'subject_type' => 'ProjectParticipation',
            'subject_id' => $participation->id,
            'after' => [
                'invitee' => $inviteeSlug,
                'role' => $role,
                'status' => $participation->status,
            ],
        ]);

        return $participation;
    }

    public function respondToParticipationInvitation(
        string $participationId,
        string $decision,
        string $actorId,
        string $tenantSlug
    ): ProjectParticipation {
        $participation = ProjectParticipation::findOrFail($participationId);

        if ($participation->participant_tenant_slug !== $tenantSlug) {
            throw new \RuntimeException('You are not authorized to respond to this invitation.');
        }

        if ($participation->status !== ProjectParticipation::STATUS_INVITED) {
            throw new \RuntimeException('Invitation is not pending.');
        }

        if (! in_array($decision, ['accept', 'decline'], true)) {
            throw new \InvalidArgumentException('decision must be accept or decline.');
        }

        if ($decision === 'accept') {
            $participation->status = ProjectParticipation::STATUS_ACTIVE;
            $participation->effective_from = now();
        } else {
            $participation->status = ProjectParticipation::STATUS_REJECTED;
        }
        $participation->save();

        $this->auditService->logEvent('core.participation.'.$decision, $actorId, [
            'tenant_slug' => $tenantSlug,
            'subject_type' => 'ProjectParticipation',
            'subject_id' => $participation->id,
            'after' => ['status' => $participation->status],
        ]);

        return $participation->fresh();
    }

    /**
     * Create a link request — never auto-merges on name alone.
     */
    public function createLinkRequest(array $data, string $actorId, string $tenantSlug): ProjectLinkRequest
    {
        $matchType = $data['match_type'] ?? 'manual';
        if (! in_array($matchType, ['exact', 'candidate', 'manual'], true)) {
            throw new \InvalidArgumentException('Invalid match_type.');
        }

        // Exact match requires strong evidence keys — never name alone.
        if ($matchType === 'exact') {
            $evidence = $data['evidence'] ?? [];
            $hasStrong = ! empty($evidence['property_id'])
                || ! empty($evidence['canonical_code'])
                || ! empty($evidence['contract_ref'])
                || ! empty($evidence['verified_invitation_id']);
            if (! $hasStrong) {
                throw new \InvalidArgumentException(
                    'Exact match requires property_id, canonical_code, contract_ref, or verified_invitation_id evidence.'
                );
            }
        }

        $request = ProjectLinkRequest::create([
            'source_project_identity_id' => $data['source_project_identity_id'] ?? null,
            'target_project_identity_id' => $data['target_project_identity_id'] ?? null,
            'property_id' => $data['property_id'] ?? null,
            'requester_tenant_slug' => $tenantSlug,
            'requester_org_type' => $data['requester_org_type'],
            'match_type' => $matchType,
            'evidence' => $data['evidence'] ?? null,
            'status' => 'pending',
        ]);

        $this->auditService->logEvent('core.project_link.requested', $actorId, [
            'tenant_slug' => $tenantSlug,
            'subject_type' => 'ProjectLinkRequest',
            'subject_id' => $request->id,
            'after' => ['match_type' => $matchType, 'status' => 'pending'],
        ]);

        return $request;
    }

    public function decideLinkRequest(
        string $requestId,
        string $decision,
        string $actorId,
        string $tenantSlug,
        ?string $reason = null
    ): ProjectLinkRequest {
        $request = ProjectLinkRequest::findOrFail($requestId);

        if ($request->status !== 'pending') {
            throw new \RuntimeException('Link request is not pending.');
        }

        // Verified owner of target identity (or requester's counterpart) may decide.
        $target = $request->target_project_identity_id
            ? ProjectIdentity::find($request->target_project_identity_id)
            : null;

        if ($target !== null && $target->verified_owner_tenant_slug !== null
            && $target->verified_owner_tenant_slug !== $tenantSlug) {
            throw new \RuntimeException('You are not authorized to decide this link request.');
        }

        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw new \InvalidArgumentException('decision must be approve or reject.');
        }

        return DB::connection('mysql')->transaction(function () use ($request, $decision, $actorId, $tenantSlug, $reason, $target) {
            $request->status = $decision === 'approve' ? 'approved' : 'rejected';
            $request->decided_by_tenant_slug = $tenantSlug;
            $request->decision_reason = $reason;
            $request->save();

            if ($decision === 'approve' && $target !== null && $request->source_project_identity_id) {
                $source = ProjectIdentity::find($request->source_project_identity_id);
                if ($source !== null && $source->id !== $target->id) {
                    // Re-point participations to target without violating unique
                    // (identity, tenant, role). Duplicates on target are closed on source.
                    $sourceParts = ProjectParticipation::where('project_identity_id', $source->id)->get();
                    foreach ($sourceParts as $part) {
                        $exists = ProjectParticipation::where('project_identity_id', $target->id)
                            ->where('participant_tenant_slug', $part->participant_tenant_slug)
                            ->where('project_role', $part->project_role)
                            ->exists();
                        if ($exists) {
                            $part->status = ProjectParticipation::STATUS_ENDED;
                            $part->effective_to = now();
                            $part->save();
                            continue;
                        }
                        $part->project_identity_id = $target->id;
                        $part->save();
                    }
                    $source->status = 'merged';
                    $source->save();
                }
            }

            $this->auditService->logEvent('core.project_link.'.$decision, $actorId, [
                'tenant_slug' => $tenantSlug,
                'subject_type' => 'ProjectLinkRequest',
                'subject_id' => $request->id,
                'after' => ['status' => $request->status, 'reason' => $reason],
            ]);

            return $request->fresh();
        });
    }

    /**
     * List link requests visible to the tenant (outgoing as requester, or involving owned identities).
     *
     * @param array{direction?: string, status?: string, per_page?: int} $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listLinkRequests(string $tenantSlug, array $filters = []): array
    {
        $ownedIds = ProjectIdentity::query()
            ->where(function ($q) use ($tenantSlug) {
                $q->where('verified_owner_tenant_slug', $tenantSlug)
                    ->orWhere('created_by_tenant_slug', $tenantSlug);
            })
            ->pluck('id');

        $query = ProjectLinkRequest::query()->orderByDesc('created_at');

        $query->where(function ($q) use ($tenantSlug, $ownedIds) {
            $q->where('requester_tenant_slug', $tenantSlug);
            if ($ownedIds->isNotEmpty()) {
                $q->orWhereIn('target_project_identity_id', $ownedIds)
                    ->orWhereIn('source_project_identity_id', $ownedIds);
            }
        });

        $direction = $filters['direction'] ?? null;
        if ($direction === 'outgoing') {
            $query->where('requester_tenant_slug', $tenantSlug);
        } elseif ($direction === 'incoming') {
            $query->where('requester_tenant_slug', '!=', $tenantSlug);
            if ($ownedIds->isNotEmpty()) {
                $query->where(function ($q) use ($ownedIds) {
                    $q->whereIn('target_project_identity_id', $ownedIds)
                        ->orWhereIn('source_project_identity_id', $ownedIds);
                });
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));
        $page = $query->paginate($perPage);

        $rows = collect($page->items())->map(function (ProjectLinkRequest $row) use ($tenantSlug) {
            $arr = $row->toArray();
            $arr['direction'] = $row->requester_tenant_slug === $tenantSlug ? 'outgoing' : 'incoming';
            // Do not expand evidence secrets beyond what the party already submitted/sees.
            return $arr;
        })->values()->all();

        return [
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    /**
     * List engagements where the tenant is client or provider.
     *
     * @param array{status?: string, per_page?: int} $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listEngagements(string $tenantSlug, array $filters = []): array
    {
        $query = Engagement::query()
            ->where(function ($q) use ($tenantSlug) {
                $q->where('client_tenant_slug', $tenantSlug)
                    ->orWhere('provider_tenant_slug', $tenantSlug);
            })
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));
        $page = $query->paginate($perPage);

        $rows = collect($page->items())->map(function (Engagement $e) use ($tenantSlug) {
            $arr = $e->toArray();
            $arr['party_role'] = $e->client_tenant_slug === $tenantSlug ? 'client' : 'provider';
            return $arr;
        })->values()->all();

        return [
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    public function getEngagement(string $id, string $tenantSlug): Engagement
    {
        $engagement = Engagement::findOrFail($id);
        if ($engagement->client_tenant_slug !== $tenantSlug
            && $engagement->provider_tenant_slug !== $tenantSlug) {
            throw new \RuntimeException('You are not authorized to view this engagement.');
        }

        return $engagement;
    }

    public function createEngagement(array $data, string $actorId, string $tenantSlug): Engagement
    {
        // Client (owner) creates engagement after award/selection.
        if (($data['client_tenant_slug'] ?? $tenantSlug) !== $tenantSlug) {
            throw new \RuntimeException('Only the client tenant may create this engagement.');
        }

        $engagement = Engagement::create([
            'project_identity_id' => $data['project_identity_id'] ?? null,
            'network_award_id' => $data['network_award_id'] ?? null,
            'network_tender_id' => $data['network_tender_id'] ?? null,
            'engagement_type' => $data['engagement_type'],
            'client_tenant_slug' => $tenantSlug,
            'provider_tenant_slug' => $data['provider_tenant_slug'],
            'client_network_profile_id' => $data['client_network_profile_id'] ?? null,
            'provider_network_profile_id' => $data['provider_network_profile_id'] ?? null,
            'status' => $data['status'] ?? 'active',
            'contract_value' => $data['contract_value'] ?? null,
            'currency' => $data['currency'] ?? 'QAR',
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'terms' => $data['terms'] ?? null,
        ]);

        // Optionally activate provider participation when project identity present.
        if (! empty($data['project_identity_id']) && ! empty($data['provider_role'])) {
            $existing = ProjectParticipation::query()
                ->where('project_identity_id', $data['project_identity_id'])
                ->where('participant_tenant_slug', $data['provider_tenant_slug'])
                ->where('project_role', $data['provider_role'])
                ->first();

            if ($existing === null) {
                ProjectParticipation::create([
                    'project_identity_id' => $data['project_identity_id'],
                    'participant_tenant_slug' => $data['provider_tenant_slug'],
                    'participant_network_profile_id' => $data['provider_network_profile_id'] ?? null,
                    'organization_type' => $data['provider_org_type'] ?? 'contractor',
                    'project_role' => $data['provider_role'],
                    'status' => ProjectParticipation::STATUS_ACTIVE,
                    'engagement_id' => $engagement->id,
                    'source' => 'award',
                    'effective_from' => now(),
                    'access_scope' => ['workspace' => true, 'private_erp' => false],
                ]);
            } else {
                $existing->engagement_id = $engagement->id;
                $existing->status = ProjectParticipation::STATUS_ACTIVE;
                $existing->effective_from = $existing->effective_from ?? now();
                $existing->save();
            }
        }

        $this->auditService->logEvent('core.engagement.created', $actorId, [
            'tenant_slug' => $tenantSlug,
            'subject_type' => 'Engagement',
            'subject_id' => $engagement->id,
            'after' => [
                'type' => $engagement->engagement_type,
                'provider' => $engagement->provider_tenant_slug,
                'status' => $engagement->status,
            ],
        ]);

        return $engagement->fresh();
    }

    private function assertCanViewIdentity(ProjectIdentity $identity, string $tenantSlug): void
    {
        if ($identity->created_by_tenant_slug === $tenantSlug
            || $identity->verified_owner_tenant_slug === $tenantSlug) {
            return;
        }

        $ok = ProjectParticipation::query()
            ->where('project_identity_id', $identity->id)
            ->where('participant_tenant_slug', $tenantSlug)
            ->whereIn('status', [
                ProjectParticipation::STATUS_ACTIVE,
                ProjectParticipation::STATUS_INVITED,
                ProjectParticipation::STATUS_PENDING,
            ])
            ->exists();

        if (! $ok) {
            throw new \RuntimeException('You are not authorized to access this Project Identity.');
        }
    }

    private function assertCanManageParticipation(ProjectIdentity $identity, string $tenantSlug): void
    {
        if ($identity->verified_owner_tenant_slug === $tenantSlug
            || $identity->created_by_tenant_slug === $tenantSlug) {
            return;
        }

        throw new \RuntimeException('You are not authorized to manage participations for this Project Identity.');
    }
}
