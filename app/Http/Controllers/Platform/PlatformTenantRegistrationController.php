<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Foundation\PlatformUser;
use App\Http\Controllers\Controller;
use App\Registration\RegistrationAuditService;
use App\Registration\RegistrationAttachmentService;
use App\Registration\RegistrationDocumentRequirementService;
use App\Registration\RegistrationDocumentReviewService;
use App\Registration\RegistrationOcrAssistance;
use App\Registration\Representative;
use App\Registration\TenantRegistrationAttachment;
use App\Registration\TenantRegistrationRequest;
use App\Registration\TenantRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlatformTenantRegistrationController extends Controller
{
    public function index(Request $request, RegistrationAuditService $audit): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(TenantRegistrationRequest::STATUSES)],
            'legal_type' => ['nullable', Rule::in(['individual', 'organization'])],
            'activity_type' => ['nullable', Rule::in(['contractor', 'consultant', 'both', 'owner'])],
            'source' => ['nullable', Rule::in(['self_service', 'representative'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $query = TenantRegistrationRequest::on('mysql')->with(['identifiers', 'attachments', 'tenant']);
        foreach (['status', 'legal_type', 'activity_type', 'source'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }
        if ($request->filled('search')) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $request->query('search'))).'%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('request_number', 'like', $term)
                    ->orWhere('name', 'like', $term)
                    ->orWhere('applicant_email', 'like', $term);
            });
        }
        $items = $query->latest('id')->paginate(min(max((int) $request->query('per_page', 20), 1), 100));
        foreach ($items->items() as $item) {
            $audit->record($item->id, 'platform', $actor->id, 'request.list_viewed', $item->status, $item->status);
            if ($actor->hasPlatformPermission('platform.tenant_registrations.identifiers.view')) {
                $audit->record($item->id, 'platform', $actor->id, 'identifiers.viewed', $item->status, $item->status);
            }
        }
        $items->getCollection()->transform(fn (TenantRegistrationRequest $item): array => $this->payload($item, $actor, false));

        return response()->json($items);
    }

    public function show(
        Request $request,
        int $id,
        RegistrationAuditService $audit,
    ): JsonResponse {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $registration = TenantRegistrationRequest::on('mysql')
            ->with(['identifiers', 'attachments', 'events', 'tenant'])
            ->findOrFail($id);
        $audit->record($registration->id, 'platform', $actor->id, 'request.viewed', $registration->status, $registration->status);
        if ($actor->hasPlatformPermission('platform.tenant_registrations.identifiers.view')) {
            $audit->record($registration->id, 'platform', $actor->id, 'identifiers.viewed', $registration->status, $registration->status);
        }
        $payload = $this->payload($registration, $actor, true);
        $payload['representatives'] = DB::connection('mysql')->table('tenant_registration_assignments as assignments')
            ->join('registration_representatives as representatives', 'representatives.id', '=', 'assignments.representative_id')
            ->where('assignments.request_id', $registration->id)
            ->whereNull('assignments.revoked_at')
            ->get([
                'representatives.id',
                'representatives.name',
                'representatives.email',
                'assignments.assigned_at',
            ]);

        return response()->json(['request' => $payload]);
    }

    public function review(
        Request $request,
        int $id,
        TenantRegistrationService $registrations,
    ): JsonResponse {
        $data = $request->validate([
            'action' => ['required', Rule::in(['start_review', 'needs_information', 'approve', 'reject'])],
            'note' => ['required', 'string', 'min:3', 'max:4000'],
            'verification_reference' => ['required_if:action,approve', 'nullable', 'string', 'max:255'],
        ]);
        /** @var PlatformUser $actor */
        $actor = $request->user();
        if (
            $data['action'] === 'approve'
            && ! $actor->hasPlatformPermission('platform.tenant_registrations.identifiers.view')
        ) {
            abort(403, 'Identifier review permission is required for approval.');
        }
        $registration = TenantRegistrationRequest::on('mysql')->findOrFail($id);
        $registration = $registrations->review(
            $registration,
            $actor,
            $data['action'],
            $data['note'],
            $data['verification_reference'] ?? null,
        );

        return response()->json(['request' => $this->payload($registration, $actor, true)]);
    }

    public function assign(
        Request $request,
        int $id,
        RegistrationAuditService $audit,
    ): JsonResponse {
        $data = $request->validate([
            'representative_id' => ['required', 'integer', 'exists:mysql.registration_representatives,id'],
        ]);
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $registration = TenantRegistrationRequest::on('mysql')->findOrFail($id);
        $representative = Representative::on('mysql')->whereKey($data['representative_id'])
            ->where('status', 'active')->firstOrFail();
        DB::connection('mysql')->transaction(function () use ($registration, $representative, $actor, $audit): void {
            $assignment = DB::connection('mysql')->table('tenant_registration_assignments')
                ->where('request_id', $registration->id)
                ->where('representative_id', $representative->id)
                ->lockForUpdate()
                ->first();

            if (! $assignment) {
                DB::connection('mysql')->table('tenant_registration_assignments')->insert([
                    'request_id' => $registration->id,
                    'representative_id' => $representative->id,
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ($assignment->revoked_at !== null) {
                DB::connection('mysql')->table('tenant_registration_assignments')
                    ->where('id', $assignment->id)->update([
                        'assigned_by' => $actor->id,
                        'assigned_at' => now(),
                        'revoked_at' => null,
                        'updated_at' => now(),
                    ]);
            }
            $audit->record($registration->id, 'platform', $actor->id, 'representative.assigned', $registration->status, $registration->status, null, [
                'representative_id' => $representative->id,
            ]);
        });

        return response()->json(['message' => 'Representative assigned.']);
    }

    public function unassign(
        Request $request,
        int $id,
        int $representativeId,
        RegistrationAuditService $audit,
    ): JsonResponse {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $registration = TenantRegistrationRequest::on('mysql')->findOrFail($id);
        DB::connection('mysql')->transaction(function () use ($registration, $representativeId, $actor, $audit): void {
            $updated = DB::connection('mysql')->table('tenant_registration_assignments')
                ->where('request_id', $registration->id)
                ->where('representative_id', $representativeId)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
            abort_if($updated === 0, 404);
            $audit->record($registration->id, 'platform', $actor->id, 'representative.unassigned', $registration->status, $registration->status, null, [
                'representative_id' => $representativeId,
            ]);
        });

        return response()->json(status: 204);
    }

    public function reviewAttachment(
        Request $request,
        int $id,
        int $attachmentId,
        RegistrationDocumentReviewService $reviews,
    ): JsonResponse {
        $data = $request->validate([
            'action' => ['required', Rule::in(['accept', 'reject', 'request_completion'])],
            'note' => ['nullable', 'string', 'max:4000'],
        ]);
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $registration = TenantRegistrationRequest::on('mysql')->with(['identifiers', 'attachments', 'events', 'tenant'])->findOrFail($id);
        $attachment = TenantRegistrationAttachment::on('mysql')
            ->where('request_id', $registration->id)
            ->findOrFail($attachmentId);
        $reviews->reviewAttachment(
            $registration,
            $attachment,
            $actor,
            $data['action'],
            (string) ($data['note'] ?? ''),
        );
        $registration = $registration->fresh()->load(['identifiers', 'attachments', 'events', 'tenant']);

        return response()->json(['request' => $this->payload($registration, $actor, true)]);
    }

    public function downloadAttachment(
        Request $request,
        int $id,
        int $attachmentId,
        RegistrationAttachmentService $attachments,
        RegistrationAuditService $audit,
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        if (
            ! $actor->hasPlatformPermission('platform.tenant_registrations.documents.download')
            && ! $actor->hasPlatformPermission('platform.tenant_registrations.documents.view')
            && ! $actor->hasPlatformPermission('platform.tenant_registrations.view')
        ) {
            abort(403);
        }
        $registration = TenantRegistrationRequest::on('mysql')->findOrFail($id);
        $attachment = TenantRegistrationAttachment::on('mysql')
            ->where('request_id', $registration->id)->findOrFail($attachmentId);
        $audit->record($registration->id, 'platform', $actor->id, 'attachment.downloaded', $registration->status, $registration->status, null, [
            'attachment_id' => $attachment->id,
            'document_type' => $attachment->document_type,
        ]);

        return $attachments->download($attachment);
    }

    private function payload(
        TenantRegistrationRequest $registration,
        PlatformUser $actor,
        bool $includeHistory,
    ): array {
        $revealIdentifiers = $actor->hasPlatformPermission('platform.tenant_registrations.identifiers.view');
        $canViewDocuments = $actor->hasPlatformPermission('platform.tenant_registrations.documents.view')
            || $actor->hasPlatformPermission('platform.tenant_registrations.view');
        $result = [
            'id' => $registration->id,
            'request_number' => $registration->request_number,
            'slug' => $registration->slug,
            'source' => $registration->source,
            'status' => $registration->status,
            'verification_status' => $registration->verification_status,
            'approval_status' => $registration->approval_status,
            'applicant_email' => $registration->applicant_email,
            'name' => $registration->name,
            'legal_type' => $registration->legal_type,
            'activity_type' => $registration->activity_type,
            'phone' => $registration->phone,
            'responsible_name' => $registration->responsible_name,
            'responsible_qid' => $this->mask($registration->responsible_qid, $revealIdentifiers),
            'qid_expires_at' => $registration->qid_expires_at?->toDateString(),
            'identifiers' => $registration->identifiers->map(fn ($identifier) => [
                'id' => $identifier->id,
                'type' => $identifier->type,
                'number' => $this->mask($identifier->number, $revealIdentifiers),
                'expires_at' => $identifier->expires_at?->toDateString(),
                'validity_status' => $identifier->validity_status,
            ])->values(),
            'attachments' => $canViewDocuments
                ? $registration->attachments->map(fn ($attachment) => [
                    'id' => $attachment->id,
                    'document_type' => $attachment->document_type,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size' => $attachment->size,
                    'review_status' => $attachment->review_status ?? 'pending',
                    'review_note' => $attachment->review_note,
                    'is_current' => (bool) ($attachment->is_current ?? true),
                    'replaces_attachment_id' => $attachment->replaces_attachment_id,
                    'reviewed_by' => $attachment->reviewed_by,
                    'reviewed_at' => $attachment->reviewed_at?->toIso8601String(),
                ])->values()
                : [],
            'checklist' => $canViewDocuments
                ? app(RegistrationDocumentRequirementService::class)->checklist($registration)
                : null,
            'ocr' => app(RegistrationOcrAssistance::class)->describe(),
            'created_at' => $registration->created_at?->toIso8601String(),
            'submitted_at' => $registration->submitted_at?->toIso8601String(),
            'tenant' => $registration->tenant ? [
                'id' => $registration->tenant->id,
                'slug' => $registration->tenant->slug,
                'status' => $registration->tenant->status,
            ] : null,
        ];
        if ($includeHistory) {
            $result['history'] = $registration->events->map(fn ($event) => [
                'actor_type' => $event->actor_type,
                'actor_id' => $event->actor_id,
                'event' => $event->event,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'message' => $event->message,
                'metadata' => $event->metadata,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values();
        }

        return $result;
    }

    private function mask(?string $value, bool $reveal): ?string
    {
        if ($value === null || $reveal) {
            return $value;
        }

        return str_repeat('*', max(strlen($value) - 4, 0)).substr($value, -4);
    }
}
