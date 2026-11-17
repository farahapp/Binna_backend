<?php

declare(strict_types=1);

namespace App\Http\Controllers\Registration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\StoreAssistedRegistrationRequest;
use App\Registration\RegistrationAttachmentService;
use App\Registration\RegistrationAuditService;
use App\Registration\Representative;
use App\Registration\TenantRegistrationAttachment;
use App\Registration\TenantRegistrationRequest as Registration;
use App\Registration\TenantRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RepresentativeRegistrationController extends Controller
{
    public function index(Request $request, RegistrationAuditService $audit): JsonResponse
    {
        /** @var Representative $representative */
        $representative = $request->user();
        $assignedIds = \Illuminate\Support\Facades\DB::connection('mysql')
            ->table('tenant_registration_assignments')
            ->where('representative_id', $representative->id)
            ->whereNull('revoked_at')
            ->pluck('request_id');
        $items = Registration::on('mysql')->where(function ($query) use ($representative, $assignedIds): void {
            $query->where('representative_id', $representative->id)
                ->orWhereIn('id', $assignedIds);
        })->latest('id')->paginate(min(max((int) $request->query('per_page', 20), 1), 50));
        foreach ($items->items() as $item) {
            $audit->record($item->id, 'representative', $representative->id, 'request.list_viewed', $item->status, $item->status);
        }

        return response()->json($items);
    }

    public function store(
        StoreAssistedRegistrationRequest $request,
        TenantRegistrationService $registrations,
    ): JsonResponse {
        /** @var Representative $representative */
        $representative = $request->user();
        $registration = $registrations->createDraft(
            $request->validated(),
            null,
            $representative,
        );

        return response()->json(['request' => $this->payload($registration)], 201);
    }

    public function show(Request $request, int $id, RegistrationAuditService $audit): JsonResponse
    {
        /** @var Representative $representative */
        $representative = $request->user();
        $registration = $this->accessible($representative, $id)->load(['identifiers', 'attachments', 'events']);
        $audit->record($registration->id, 'representative', $representative->id, 'request.viewed', $registration->status, $registration->status);

        return response()->json(['request' => $this->payload($registration, true)]);
    }

    public function update(
        StoreAssistedRegistrationRequest $request,
        int $id,
        TenantRegistrationService $registrations,
    ): JsonResponse {
        /** @var Representative $representative */
        $representative = $request->user();
        $registration = $this->createdBy($representative, $id);
        if (! in_array($registration->status, ['draft', 'needs_information'], true)) {
            throw ValidationException::withMessages(['status' => ['This request can no longer be edited.']]);
        }

        $registration = $registrations->updateDraft(
            $registration,
            $request->validated(),
            'representative',
            $representative->id,
        );

        return response()->json(['request' => $this->payload($registration)]);
    }

    public function submit(
        Request $request,
        int $id,
        TenantRegistrationService $registrations,
    ): JsonResponse {
        $request->validate(['declaration_confirmed' => ['accepted']]);
        /** @var Representative $representative */
        $representative = $request->user();
        $registration = $this->createdBy($representative, $id);
        $data = $request->validate(['response' => ['nullable', 'string', 'min:3', 'max:4000']]);
        if ($registration->status === 'needs_information' && empty(trim((string) ($data['response'] ?? '')))) {
            throw ValidationException::withMessages(['response' => ['A response is required before resubmitting this request.']]);
        }
        $registration = $registrations->submit(
            $registration,
            'representative',
            $representative->id,
            $registration->status === 'needs_information' ? ($data['response'] ?? null) : null,
        );

        return response()->json(['request' => $this->payload($registration)]);
    }

    public function upload(Request $request, int $id, RegistrationAttachmentService $attachments): JsonResponse
    {
        /** @var Representative $representative */
        $representative = $request->user();
        $registration = $this->createdBy($representative, $id);
        if (! in_array($registration->status, ['draft', 'needs_information'], true)) {
            throw ValidationException::withMessages(['status' => ['Attachments cannot be changed in this state.']]);
        }
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'document_type' => ['nullable', 'string', 'max:64', 'alpha_dash'],
        ]);
        $attachment = $attachments->store(
            $data['file'],
            $registration,
            'representative',
            $representative->id,
            $data['document_type'] ?? null,
        );

        return response()->json(['attachment' => $this->attachmentPayload($attachment)], 201);
    }

    public function downloadAttachment(
        Request $request,
        int $id,
        int $attachmentId,
        RegistrationAttachmentService $attachments,
        RegistrationAuditService $audit,
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        /** @var Representative $representative */
        $representative = $request->user();
        $registration = $this->accessible($representative, $id);
        $attachment = TenantRegistrationAttachment::on('mysql')
            ->where('request_id', $registration->id)->findOrFail($attachmentId);
        $audit->record($registration->id, 'representative', $representative->id, 'attachment.viewed', $registration->status, $registration->status);

        return $attachments->download($attachment);
    }

    private function createdBy(Representative $representative, int $id): Registration
    {
        return Registration::on('mysql')
            ->where('representative_id', $representative->id)
            ->findOrFail($id);
    }

    private function accessible(Representative $representative, int $id): Registration
    {
        $assigned = \Illuminate\Support\Facades\DB::connection('mysql')
            ->table('tenant_registration_assignments')
            ->where('request_id', $id)
            ->where('representative_id', $representative->id)
            ->whereNull('revoked_at')
            ->exists();
        $query = Registration::on('mysql')->whereKey($id);
        if (! $assigned) {
            $query->where('representative_id', $representative->id);
        }

        return $query->firstOrFail();
    }

    private function payload(Registration $registration, bool $includeHistory = false): array
    {
        $result = [
            'id' => $registration->id,
            'request_number' => $registration->request_number,
            'slug' => $registration->slug,
            'source' => $registration->source,
            'status' => $registration->status,
            'verification_status' => $registration->verification_status,
            'approval_status' => $registration->approval_status,
            'applicant_email' => $registration->applicant_email,
            'owner_email_verified' => $registration->applicant_id !== null,
            'name' => $registration->name,
            'legal_type' => $registration->legal_type,
            'activity_type' => $registration->activity_type,
            'phone' => $registration->phone,
            'responsible_name' => $registration->responsible_name,
            'responsible_qid' => $registration->responsible_qid,
            'qid_expires_at' => $registration->qid_expires_at?->toDateString(),
            'identifiers' => $registration->identifiers->map(fn ($identifier) => [
                'id' => $identifier->id,
                'type' => $identifier->type,
                'number' => $identifier->number,
                'expires_at' => $identifier->expires_at?->toDateString(),
                'validity_status' => $identifier->validity_status,
            ])->values(),
            'attachments' => $registration->attachments
                ->filter(fn ($attachment) => (bool) ($attachment->is_current ?? true))
                ->map(fn ($attachment) => $this->attachmentPayload($attachment))
                ->values(),
            'checklist' => app(\App\Registration\RegistrationDocumentRequirementService::class)->checklist($registration),
            'created_at' => $registration->created_at?->toIso8601String(),
            'submitted_at' => $registration->submitted_at?->toIso8601String(),
        ];
        if ($includeHistory) {
            $result['history'] = $registration->events->map(fn ($event) => [
                'event' => $event->event,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'message' => $event->message,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values();
        }

        return $result;
    }

    private function attachmentPayload(TenantRegistrationAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'document_type' => $attachment->document_type,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            'review_status' => $attachment->review_status ?? 'pending',
            'review_note' => $attachment->review_note,
            'is_current' => (bool) ($attachment->is_current ?? true),
            'replaces_attachment_id' => $attachment->replaces_attachment_id,
            'reviewed_at' => $attachment->reviewed_at?->toIso8601String(),
        ];
    }
}
