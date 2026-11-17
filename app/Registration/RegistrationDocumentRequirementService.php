<?php

declare(strict_types=1);

namespace App\Registration;

use Illuminate\Support\Collection;

class RegistrationDocumentRequirementService
{
    /**
     * @return Collection<int, RegistrationDocumentRequirement>
     */
    public function forProfile(string $legalType, string $activityType): Collection
    {
        return RegistrationDocumentRequirement::on('mysql')
            ->where('is_active', true)
            ->where(function ($query) use ($legalType): void {
                $query->where('legal_type', $legalType)->orWhere('legal_type', 'any');
            })
            ->where(function ($query) use ($activityType): void {
                $query->where('activity_type', $activityType)->orWhere('activity_type', 'any');
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{required_total: int, required_satisfied: int, optional_total: int, optional_uploaded: int, percent: int, items: array<int, array<string, mixed>>}
     */
    public function checklist(TenantRegistrationRequest $registration): array
    {
        $requirements = $this->forProfile((string) $registration->legal_type, (string) $registration->activity_type);
        $attachments = $registration->attachments()
            ->where('is_current', true)
            ->get()
            ->groupBy(fn ($row) => (string) ($row->document_type ?: 'untyped'));

        $items = [];
        $requiredTotal = 0;
        $requiredSatisfied = 0;
        $optionalTotal = 0;
        $optionalUploaded = 0;

        foreach ($requirements as $requirement) {
            $current = $attachments->get($requirement->code)?->first();
            $status = $current?->review_status ?? 'missing';
            if ($requirement->is_required) {
                $requiredTotal++;
                if ($current && in_array($status, ['pending', 'accepted'], true)) {
                    $requiredSatisfied++;
                }
            } else {
                $optionalTotal++;
                if ($current) {
                    $optionalUploaded++;
                }
            }
            $items[] = [
                'code' => $requirement->code,
                'label_ar' => $requirement->label_ar,
                'label_en' => $requirement->label_en,
                'is_required' => $requirement->is_required,
                'expires' => $requirement->expires,
                'attachment_id' => $current?->id,
                'review_status' => $status,
                'review_note' => $current?->review_note,
            ];
        }

        $percent = $requiredTotal === 0
            ? 100
            : (int) floor(($requiredSatisfied / $requiredTotal) * 100);

        return [
            'required_total' => $requiredTotal,
            'required_satisfied' => $requiredSatisfied,
            'optional_total' => $optionalTotal,
            'optional_uploaded' => $optionalUploaded,
            'percent' => $percent,
            'items' => $items,
            'ocr' => app(RegistrationOcrAssistance::class)->describe(),
        ];
    }
}
