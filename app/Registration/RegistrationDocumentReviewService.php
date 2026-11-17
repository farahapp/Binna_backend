<?php

declare(strict_types=1);

namespace App\Registration;

use App\Foundation\PlatformUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistrationDocumentReviewService
{
    public function __construct(
        private readonly RegistrationAuditService $audit,
        private readonly TenantRegistrationService $registrations,
    ) {}

    /**
     * @param 'accept'|'reject'|'request_completion' $action
     */
    public function reviewAttachment(
        TenantRegistrationRequest $registration,
        TenantRegistrationAttachment $attachment,
        PlatformUser $actor,
        string $action,
        string $note,
    ): TenantRegistrationAttachment {
        if ((int) $attachment->request_id !== (int) $registration->id) {
            abort(404);
        }
        if (! $attachment->is_current) {
            throw ValidationException::withMessages([
                'attachment' => ['Only the current document version can be reviewed.'],
            ]);
        }
        if (! in_array($registration->status, ['submitted', 'under_review', 'needs_information'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Documents cannot be reviewed in this registration state.'],
            ]);
        }

        $note = trim($note);
        if ($action !== 'accept' && strlen($note) < 3) {
            throw ValidationException::withMessages([
                'note' => ['A clear reason or instruction is required.'],
            ]);
        }
        if ($action === 'accept' && $note === '') {
            $note = 'Document accepted.';
        }

        $fromReview = (string) $attachment->review_status;
        $nextReview = match ($action) {
            'accept' => 'accepted',
            'reject' => 'rejected',
            'request_completion' => 'needs_completion',
            default => null,
        };
        if ($nextReview === null) {
            throw ValidationException::withMessages(['action' => ['Unsupported document review action.']]);
        }

        return DB::connection('mysql')->transaction(function () use (
            $registration,
            $attachment,
            $actor,
            $action,
            $note,
            $fromReview,
            $nextReview,
        ): TenantRegistrationAttachment {
            $lockedAttachment = TenantRegistrationAttachment::on('mysql')
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedRequest = TenantRegistrationRequest::on('mysql')
                ->whereKey($registration->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedAttachment->review_status = $nextReview;
            $lockedAttachment->review_note = $note;
            $lockedAttachment->reviewed_by = $actor->id;
            $lockedAttachment->reviewed_at = now();
            $lockedAttachment->save();

            $this->audit->record(
                $lockedRequest->id,
                'platform',
                $actor->id,
                'attachment.review.'.$action,
                $lockedRequest->status,
                $lockedRequest->status,
                $note,
                [
                    'attachment_id' => $lockedAttachment->id,
                    'document_type' => $lockedAttachment->document_type,
                    'from_review_status' => $fromReview,
                    'to_review_status' => $nextReview,
                ],
            );

            if (in_array($action, ['reject', 'request_completion'], true)
                && in_array($lockedRequest->status, ['submitted', 'under_review'], true)
            ) {
                $from = $lockedRequest->status;
                $lockedRequest->status = 'needs_information';
                $lockedRequest->fill(RegistrationLifecycle::fromRegistrationStatus('needs_information'));
                $lockedRequest->reviewed_by = $actor->id;
                $lockedRequest->reviewed_at = now();
                $lockedRequest->save();
                $this->audit->record(
                    $lockedRequest->id,
                    'platform',
                    $actor->id,
                    'review.needs_information',
                    $from,
                    'needs_information',
                    $note,
                    [
                        'triggered_by_attachment_id' => $lockedAttachment->id,
                        'document_type' => $lockedAttachment->document_type,
                    ],
                );
                $this->registrations->notifyStatusChangePublic(
                    $lockedRequest->fresh(),
                    $from,
                    'needs_information',
                    $note,
                );
            } else {
                $lockedRequest->fill(RegistrationLifecycle::fromRegistrationStatus((string) $lockedRequest->status));
                $lockedRequest->save();
                if ($action === 'accept') {
                    $this->registrations->notifyStatusChangePublic(
                        $lockedRequest->fresh(),
                        (string) $lockedRequest->status,
                        'document_accepted',
                        $note,
                    );
                }
            }

            return $lockedAttachment->fresh();
        });
    }
}
