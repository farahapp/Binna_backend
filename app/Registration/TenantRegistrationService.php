<?php

declare(strict_types=1);

namespace App\Registration;

use App\Foundation\Tenant;
use App\Foundation\TenantIdentifier;
use App\Foundation\PlatformUser;
use App\Mail\RegistrationStatusChangedMail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TenantRegistrationService
{
    public function __construct(private readonly RegistrationAuditService $audit) {}

    public function assertReadyForProvisioning(TenantRegistrationRequest $request): void
    {
        $this->assertComplete($request);
        $requiredTypes = $request->legal_type === 'organization' ? ['cr'] : ['qid'];
        if (
            $request->legal_type === 'organization'
            && in_array($request->activity_type, ['consultant', 'both'], true)
        ) {
            $requiredTypes[] = 'professional_license';
        }

        $verifiedTypes = $request->identifiers()
            ->whereIn('type', $requiredTypes)
            ->where('validity_status', 'valid')
            ->pluck('type');
        if (count($requiredTypes) !== $verifiedTypes->unique()->count()) {
            throw ValidationException::withMessages([
                'identifiers' => ['Required registration identifiers must be valid before provisioning.'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createDraft(
        array $data,
        ?Applicant $applicant,
        ?Representative $representative = null,
    ): TenantRegistrationRequest {
        return DB::connection('mysql')->transaction(function () use ($data, $applicant, $representative): TenantRegistrationRequest {
            $applicantEmail = Str::lower(trim((string) ($applicant?->email ?? $data['applicant_email'])));
            if ($representative && empty($data['owner_consent'])) {
                throw ValidationException::withMessages([
                    'owner_consent' => ['Owner consent is required for assisted registration.'],
                ]);
            }

            $request = TenantRegistrationRequest::on('mysql')->create([
                'request_number' => $this->newRequestNumber(),
                'slug' => $this->newSlug($data['name']),
                'applicant_id' => $applicant?->getKey(),
                'representative_id' => $representative?->getKey(),
                'source' => $representative ? 'representative' : 'self_service',
                'status' => 'draft',
                ...RegistrationLifecycle::fromRegistrationStatus('draft'),
                'applicant_email' => $applicantEmail,
                'name' => trim($data['name']),
                'legal_type' => $data['legal_type'],
                'activity_type' => $data['activity_type'],
                'phone' => $data['phone'] ?? null,
                'responsible_name' => trim($data['responsible_name']),
                'responsible_qid' => $data['responsible_qid'] ?? null,
                'qid_expires_at' => $data['qid_expires_at'] ?? null,
                'owner_consent_at' => $representative ? now() : null,
                'owner_consent_version' => $representative ? 'tenant-registration-v1' : null,
            ]);

            $this->syncIdentifiers($request, $data['identifiers']);
            $this->audit->record(
                $request->id,
                $representative ? 'representative' : 'applicant',
                $representative?->id ?? $applicant?->id,
                'request.draft_created',
                null,
                'draft',
                null,
                [
                    'source' => $request->source,
                    'owner_consent_version' => $request->owner_consent_version,
                ],
            );

            return $request->load('identifiers');
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateDraft(
        TenantRegistrationRequest $request,
        array $data,
        string $actorType,
        int|string $actorId,
    ): TenantRegistrationRequest {
        return DB::connection('mysql')->transaction(function () use ($request, $data, $actorType, $actorId): TenantRegistrationRequest {
            $locked = TenantRegistrationRequest::on('mysql')->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['draft', 'needs_information'], true)) {
                throw ValidationException::withMessages(['status' => ['This request can no longer be edited.']]);
            }
            if ($actorType === 'representative') {
                $email = Str::lower(trim((string) ($data['applicant_email'] ?? '')));
                if ($email === '') {
                    throw ValidationException::withMessages(['applicant_email' => ['The owner email is required.']]);
                }
                if ($locked->applicant_id !== null && $email !== $locked->applicant_email) {
                    throw ValidationException::withMessages(['applicant_email' => ['Owner email cannot change after the owner verifies it.']]);
                }
                if (empty($data['owner_consent'])) {
                    throw ValidationException::withMessages(['owner_consent' => ['Owner consent is required for assisted registration.']]);
                }
                $locked->applicant_email = $email;
                $locked->owner_consent_at = now();
                $locked->owner_consent_version = 'tenant-registration-v1';
            }

            $locked->fill([
                'name' => trim($data['name']),
                'legal_type' => $data['legal_type'],
                'activity_type' => $data['activity_type'],
                'phone' => $data['phone'] ?? null,
                'responsible_name' => trim($data['responsible_name']),
                'responsible_qid' => $data['responsible_qid'] ?? null,
                'qid_expires_at' => $data['qid_expires_at'] ?? null,
            ])->save();
            $this->syncIdentifiers($locked, $data['identifiers']);
            $this->assertComplete($locked);
            $this->audit->record(
                $locked->id,
                $actorType,
                $actorId,
                'request.updated',
                $locked->status,
                $locked->status,
                null,
                ['changed_fields' => array_values(array_intersect(
                    array_keys($data),
                    ['name', 'legal_type', 'activity_type', 'phone', 'responsible_name', 'responsible_qid', 'qid_expires_at', 'identifiers', 'applicant_email'],
                ))],
            );

            return $locked->fresh()->load('identifiers');
        });
    }

    public function claimAssistedDrafts(Applicant $applicant): int
    {
        if (! $applicant->email_verified_at) {
            throw ValidationException::withMessages([
                'applicant' => ['A verified owner email is required to claim an assisted request.'],
            ]);
        }

        return DB::connection('mysql')->transaction(function () use ($applicant): int {
            $requests = TenantRegistrationRequest::on('mysql')
                ->whereNull('applicant_id')
                ->where('source', 'representative')
                ->where('applicant_email', $applicant->email)
                ->whereIn('status', ['draft', 'needs_information'])
                ->lockForUpdate()
                ->get();

            foreach ($requests as $request) {
                $request->applicant_id = $applicant->id;
                $request->owner_consent_at = now();
                $request->owner_consent_version = 'tenant-registration-v1';
                $request->save();
                $this->audit->record(
                    $request->id,
                    'applicant',
                    $applicant->id,
                    'request.claimed_by_verified_owner',
                    $request->status,
                    $request->status,
                    null,
                    ['owner_consent_confirmed' => true],
                );
            }

            return $requests->count();
        });
    }

    public function submit(
        TenantRegistrationRequest $request,
        string $actorType,
        int|string $actorId,
        ?string $message = null,
    ): TenantRegistrationRequest {
        return DB::connection('mysql')->transaction(function () use ($request, $actorType, $actorId, $message): TenantRegistrationRequest {
            $locked = TenantRegistrationRequest::on('mysql')->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['draft', 'needs_information'], true)) {
                throw ValidationException::withMessages(['status' => ['Only a draft or a request needing information can be submitted.']]);
            }
            if ($locked->applicant_id === null) {
                throw ValidationException::withMessages(['applicant' => ['The owner must verify their email before submission.']]);
            }
            $this->assertComplete($locked);
            $this->assertRequiredDocumentsUploaded($locked);

            $from = $locked->status;
            $locked->status = 'submitted';
            $locked->fill(RegistrationLifecycle::fromRegistrationStatus('submitted'));
            $locked->submitted_at = now();
            $locked->owner_consent_at ??= now();
            $locked->owner_consent_version ??= 'tenant-registration-v1';
            $locked->save();
            $this->audit->record($locked->id, $actorType, $actorId, 'request.submitted', $from, 'submitted', $message ? trim($message) : null);
            $this->notifyApplicantStatusChange($locked->fresh(), $from, 'submitted', $message ? trim($message) : 'تم استلام طلب التسجيل.');

            return $locked->fresh()->load('identifiers');
        });
    }

    public function review(
        TenantRegistrationRequest $request,
        PlatformUser $actor,
        string $action,
        string $note,
        ?string $verificationReference = null,
    ): TenantRegistrationRequest {
        $fromStatus = null;
        $reviewed = DB::connection('mysql')->transaction(function () use ($request, $actor, $action, $note, $verificationReference, &$fromStatus): TenantRegistrationRequest {
            $locked = TenantRegistrationRequest::on('mysql')->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $fromStatus = $from;

            $next = match ($action) {
                'start_review' => $from === 'submitted' ? 'under_review' : null,
                'needs_information' => $from === 'under_review' ? 'needs_information' : null,
                'approve' => $from === 'under_review' ? 'approved_pending_provisioning' : null,
                'reject' => $from === 'under_review' ? 'rejected' : null,
                default => null,
            };
            if ($next === null) {
                throw ValidationException::withMessages(['status' => ['This review transition is not allowed.']]);
            }

            if ($action === 'approve') {
                $this->assertComplete($locked);
                $this->assertRequiredDocumentsAccepted($locked);
                if (trim((string) $verificationReference) === '') {
                    throw ValidationException::withMessages([
                        'verification_reference' => ['A verification reference is required before approval.'],
                    ]);
                }
                $requiredTypes = ['organization' => ['cr'], 'individual' => ['qid']][$locked->legal_type];
                if (
                    $locked->legal_type === 'organization'
                    && in_array($locked->activity_type, ['consultant', 'both'], true)
                ) {
                    $requiredTypes[] = 'professional_license';
                }
                $locked->identifiers()
                    ->whereIn('type', $requiredTypes)
                    ->update(['validity_status' => 'valid']);
            }

            $locked->status = $next;
            $locked->fill(RegistrationLifecycle::fromRegistrationStatus($next));
            $locked->reviewed_by = $actor->id;
            $locked->reviewed_at = now();
            $locked->save();
            $this->audit->record(
                $locked->id,
                'platform',
                $actor->id,
                'review.'.$action,
                $from,
                $next,
                trim($note),
                $action === 'approve' ? ['verification_reference' => trim((string) $verificationReference)] : null,
            );

            return $locked->fresh()->load('identifiers');
        });

        $this->notifyApplicantStatusChange(
            $reviewed,
            (string) $fromStatus,
            $action,
            trim($note),
        );

        return $reviewed;
    }

    /**
     * Public wrapper for document-review-driven notifications (no PII in payload beyond status labels).
     */
    public function notifyStatusChangePublic(
        TenantRegistrationRequest $request,
        string $fromStatus,
        string $action,
        string $note,
    ): void {
        $this->notifyApplicantStatusChange($request, $fromStatus, $action, $note);
    }

    private function notifyApplicantStatusChange(
        TenantRegistrationRequest $request,
        string $fromStatus,
        string $action,
        string $note,
    ): void {
        $email = Str::lower(trim((string) $request->applicant_email));
        if ($email === '') {
            return;
        }

        // Deduplicate identical status mails within a short window for the same request+action.
        $duplicate = TenantRegistrationEvent::on('mysql')
            ->where('request_id', $request->id)
            ->where('event', 'notification.status.'.$action)
            ->where('created_at', '>=', now()->subMinutes(2))
            ->exists();
        if ($duplicate) {
            return;
        }

        try {
            app(RegistrationMailDeliveryGuard::class)->assertAvailable();
            Mail::to($email)->send(new RegistrationStatusChangedMail(
                requestNumber: (string) $request->request_number,
                companyName: (string) $request->name,
                fromStatus: $fromStatus,
                toStatus: (string) $request->status,
                action: $action,
                note: $note,
            ));
            $this->audit->record(
                $request->id,
                'system',
                null,
                'notification.status.'.$action,
                $fromStatus,
                (string) $request->status,
                null,
                ['channel' => 'mail'],
            );
        } catch (\Throwable $e) {
            Log::warning('registration.status_mail_failed', [
                'request_id' => $request->id,
                'action' => $action,
                'to_status' => $request->status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function assertRequiredDocumentsUploaded(TenantRegistrationRequest $request): void
    {
        $checklist = app(RegistrationDocumentRequirementService::class)->checklist($request);
        $missing = [];
        foreach ($checklist['items'] as $item) {
            if (! ($item['is_required'] ?? false)) {
                continue;
            }
            if (($item['review_status'] ?? 'missing') === 'missing') {
                $missing[] = $item['label_ar'] ?? $item['code'];
            }
        }
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'documents' => ['المستندات الإلزامية غير مكتملة: '.implode('، ', $missing)],
            ]);
        }
    }

    private function assertRequiredDocumentsAccepted(TenantRegistrationRequest $request): void
    {
        $checklist = app(RegistrationDocumentRequirementService::class)->checklist($request);
        $pending = [];
        foreach ($checklist['items'] as $item) {
            if (! ($item['is_required'] ?? false)) {
                continue;
            }
            if (($item['review_status'] ?? 'missing') !== 'accepted') {
                $pending[] = $item['label_ar'] ?? $item['code'];
            }
        }
        if ($pending !== []) {
            throw ValidationException::withMessages([
                'documents' => ['لا يمكن الاعتماد قبل قبول المستندات الإلزامية: '.implode('، ', $pending)],
            ]);
        }
    }

    private function assertComplete(TenantRegistrationRequest $request): void
    {
        $identifiers = $request->identifiers()->get();
        $types = $identifiers->pluck('type');
        if ($request->legal_type === 'organization' && ! $types->contains('cr')) {
            throw ValidationException::withMessages(['identifiers' => ['A company requires a CR.']]);
        }
        if (
            ($request->legal_type === 'individual' && $types->contains(fn ($type) => $type !== 'qid'))
            || ($request->legal_type === 'organization' && $types->contains('qid'))
        ) {
            throw ValidationException::withMessages(['identifiers' => ['The identifier type is incompatible with the legal entity type.']]);
        }
        if (
            $request->legal_type === 'individual'
            && (! in_array($request->activity_type, ['contractor', 'owner'], true) || ! $types->contains('qid'))
        ) {
            throw ValidationException::withMessages([
                'identifiers' => ['An individual contractor or owner requires a QID.'],
            ]);
        }
        if (
            $request->legal_type === 'organization'
            && in_array($request->activity_type, ['consultant', 'both'], true)
            && ! $types->contains('professional_license')
        ) {
            throw ValidationException::withMessages(['identifiers' => ['An engineering consultancy requires a professional license in addition to its CR.']]);
        }

        foreach ($identifiers as $identifier) {
            if ($identifier->expires_at !== null && $identifier->expires_at->toDateString() < now()->toDateString()) {
                throw ValidationException::withMessages(['identifiers' => ['Expired identifiers cannot be submitted or approved.']]);
            }
        }
    }

    /**
     * @param array<int, array{type: string, number: string, expires_at?: string|null}> $identifiers
     */
    private function syncIdentifiers(TenantRegistrationRequest $request, array $identifiers): void
    {
        $existing = $request->identifiers()->get()->keyBy('type');
        $submittedTypes = [];

        foreach ($identifiers as $data) {
            $type = strtolower(trim($data['type']));
            $number = trim($data['number']);
            $normalized = TenantIdentifier::normalize($number);
            if ($normalized === '') {
                throw ValidationException::withMessages(['identifiers' => ['Identifier values must contain letters or digits.']]);
            }

            $row = $existing->get($type);
            $duplicate = TenantIdentifier::on('mysql')
                ->where('type', $type)
                ->where('normalized_number', $normalized)
                ->when($row, fn (Builder $query) => $query->where('id', '<>', $row->id))
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['identifiers' => ['This identifier is already registered.']]);
            }

            $row ??= new TenantIdentifier();
            $row->fill([
                'tenant_id' => null,
                'registration_request_id' => $request->id,
                'type' => $type,
                'number' => $number,
                'expires_at' => $data['expires_at'] ?? null,
                'validity_status' => 'unverified',
                'document_path' => null,
            ]);
            try {
                $row->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'identifiers' => ['This identifier is already registered.'],
                ]);
            }
            $submittedTypes[] = $type;
        }

        foreach ($existing as $type => $row) {
            if (! in_array($type, $submittedTypes, true)) {
                $row->delete();
            }
        }
    }

    private function newRequestNumber(): string
    {
        do {
            $number = 'TR-'.now()->format('Y').'-'.Str::upper(Str::random(8));
        } while (TenantRegistrationRequest::on('mysql')->where('request_number', $number)->exists());

        return $number;
    }

    private function newSlug(string $name): string
    {
        $base = substr(Str::slug($name) ?: 'tenant', 0, 48);
        do {
            $slug = trim($base, '-').'-'.Str::lower(Str::random(8));
        } while (
            Tenant::on('mysql')->where('slug', $slug)->exists()
            || TenantRegistrationRequest::on('mysql')->where('slug', $slug)->exists()
        );

        return $slug;
    }
}
