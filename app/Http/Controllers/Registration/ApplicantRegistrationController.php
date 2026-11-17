<?php

declare(strict_types=1);

namespace App\Http\Controllers\Registration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\StoreTenantRegistrationRequest;
use App\Registration\Applicant;
use App\Registration\RegistrationAuditService;
use App\Registration\RegistrationAttachmentService;
use App\Registration\RegistrationDocumentRequirementService;
use App\Registration\TenantRegistrationAttachment;
use App\Registration\TenantRegistrationRequest as Registration;
use App\Registration\TenantRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApplicantRegistrationController extends Controller
{
    public function index(Request $request, RegistrationAuditService $audit): JsonResponse
    {
        /** @var Applicant $applicant */
        $applicant = $request->user();
        $items = Registration::on('mysql')->where('applicant_id', $applicant->id)
            ->latest('id')->paginate(min((int) $request->query('per_page', 20), 50));
        foreach ($items->items() as $item) {
            $audit->record($item->id, 'applicant', $applicant->id, 'request.list_viewed', $item->status, $item->status);
        }

        return response()->json($items);
    }

    public function store(
        StoreTenantRegistrationRequest $request,
        TenantRegistrationService $registrations,
    ): JsonResponse {
        /** @var Applicant $applicant */
        $applicant = $request->user();
        if (! $applicant->email_verified_at) {
            abort(403, 'A verified applicant email is required.');
        }
        $registration = $registrations->createDraft($request->validated(), $applicant);

        return response()->json(['request' => $this->payload($registration)], 201);
    }

    public function show(Request $request, int $id, RegistrationAuditService $audit): JsonResponse
    {
        /** @var Applicant $applicant */
        $applicant = $request->user();
        $registration = $this->owned($applicant, $id)->load(['identifiers', 'attachments', 'events']);
        $audit->record($registration->id, 'applicant', $applicant->id, 'request.viewed', $registration->status, $registration->status);

        return response()->json(['request' => $this->payload($registration, true)]);
    }

    public function update(
        StoreTenantRegistrationRequest $request,
        int $id,
        TenantRegistrationService $registrations,
    ): JsonResponse {
        /** @var Applicant $applicant */
        $applicant = $request->user();
        $registration = $this->owned($applicant, $id);
        if (! in_array($registration->status, ['draft', 'needs_information'], true)) {
            throw ValidationException::withMessages(['status' => ['This request can no longer be edited.']]);
        }

        $registration = $registrations->updateDraft(
            $registration,
            $request->validated(),
            'applicant',
            $applicant->id,
        );

        return response()->json(['request' => $this->payload($registration)]);
    }

    public function claim(Request $request, TenantRegistrationService $registrations): JsonResponse
    {
        $request->validate(['owner_consent_confirmed' => ['accepted']]);
        /** @var Applicant $applicant */
        $applicant = $request->user();
        $count = $registrations->claimAssistedDrafts($applicant);

        return response()->json(['claimed' => $count]);
    }

    public function submit(
        Request $request,
        int $id,
        TenantRegistrationService $registrations,
    ): JsonResponse {
        $request->validate(['declaration_confirmed' => ['accepted']]);
        /** @var Applicant $applicant */
        $applicant = $request->user();
        $registration = $this->owned($applicant, $id);
        $registration = $registrations->submit($registration, 'applicant', $applicant->id);

        return response()->json(['request' => $this->payload($registration)]);
    }

    public function respond(
        Request $request,
        int $id,
        TenantRegistrationService $registrations,
        RegistrationAuditService $audit,
    ): JsonResponse {
        $data = $request->validate([
            'declaration_confirmed' => ['accepted'],
            'name' => ['required', 'string', 'max:255'],
            'legal_type' => ['required', 'in:individual,organization'],
            'activity_type' => ['required', 'in:contractor,consultant,both,owner'],
            'phone' => ['nullable', 'string', 'max:32'],
            'responsible_name' => ['required', 'string', 'max:255'],
            'responsible_qid' => ['nullable', 'string', 'max:32'],
            'qid_expires_at' => ['nullable', 'date'],
            'identifiers' => ['required', 'array', 'min:1', 'max:5'],
            'identifiers.*.type' => ['required', 'in:cr,professional_license,qid,establishment,commercial_license,engineering_consultancy_registration'],
            'identifiers.*.number' => ['required', 'string', 'max:128'],
            'identifiers.*.expires_at' => ['nullable', 'date'],
            'response' => ['required', 'string', 'min:3', 'max:4000'],
        ]);
        /** @var Applicant $applicant */
        $applicant = $request->user();
        $registration = $this->owned($applicant, $id);
        if ($registration->status !== 'needs_information') {
            throw ValidationException::withMessages(['status' => ['This request is not awaiting information.']]);
        }
        $responseText = trim($data['response']);
        unset($data['declaration_confirmed'], $data['response']);
        $registration = $registrations->updateDraft($registration, $data, 'applicant', $applicant->id);
        $registration = $registrations->submit($registration, 'applicant', $applicant->id);
        $audit->record(
            $registration->id,
            'applicant',
            $applicant->id,
            'request.information_provided',
            'needs_information',
            'submitted',
            $responseText,
        );

        return response()->json(['request' => $this->payload($registration)]);
    }

    public function checklist(
        Request $request,
        int $id,
        RegistrationDocumentRequirementService $requirements,
        RegistrationAuditService $audit,
    ): JsonResponse {
        /** @var Applicant $applicant */
        $applicant = $request->user();
        $registration = $this->owned($applicant, $id);
        $audit->record($registration->id, 'applicant', $applicant->id, 'documents.checklist_viewed', $registration->status, $registration->status);

        return response()->json([
            'request_id' => $registration->id,
            'status' => $registration->status,
            'verification_status' => $registration->verification_status,
            'approval_status' => $registration->approval_status,
            'checklist' => $requirements->checklist($registration),
        ]);
    }

    public function upload(Request $request, int $id, RegistrationAttachmentService $attachments): JsonResponse
    {
        /** @var Applicant $applicant */
        $applicant = $request->user();
        $registration = $this->owned($applicant, $id);
        if (! in_array($registration->status, ['draft', 'needs_information'], true)) {
            throw ValidationException::withMessages(['status' => ['لا يمكن تعديل المرفقات في هذه الحالة.']]);
        }
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'document_type' => ['nullable', 'string', 'max:64', 'alpha_dash'],
        ]);

        $attachment = $attachments->store(
            $data['file'],
            $registration,
            'applicant',
            $applicant->id,
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
        /** @var Applicant $applicant */
        $applicant = $request->user();
        $registration = $this->owned($applicant, $id);
        $attachment = TenantRegistrationAttachment::on('mysql')
            ->where('request_id', $registration->id)->findOrFail($attachmentId);
        $audit->record($registration->id, 'applicant', $applicant->id, 'attachment.viewed', $registration->status, $registration->status);

        return $attachments->download($attachment);
    }

    private function owned(Applicant $applicant, int $id): Registration
    {
        return Registration::on('mysql')->where('applicant_id', $applicant->id)->findOrFail($id);
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
            'checklist' => app(RegistrationDocumentRequirementService::class)->checklist($registration),
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

    private function attachmentPayload($attachment): array
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
