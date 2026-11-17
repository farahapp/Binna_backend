<?php

declare(strict_types=1);

namespace App\Http\Requests\Registration;

use App\Foundation\TenantIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTenantRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_type' => ['required', Rule::in(['individual', 'organization'])],
            'activity_type' => ['required', Rule::in(['contractor', 'consultant', 'both', 'owner'])],
            'phone' => ['nullable', 'string', 'max:32'],
            'responsible_name' => ['required', 'string', 'max:255'],
            'responsible_qid' => ['nullable', 'string', 'max:32'],
            'qid_expires_at' => ['nullable', 'date'],
            'identifiers' => ['required', 'array', 'min:1', 'max:5'],
            'identifiers.*.type' => ['required', Rule::in(TenantIdentifier::TYPES)],
            'identifiers.*.number' => ['required', 'string', 'max:128'],
            'identifiers.*.expires_at' => ['nullable', 'date'],
            'owner_consent' => ['sometimes', 'accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $legalType = $this->input('legal_type');
            $activity = $this->input('activity_type');
            $types = collect($this->input('identifiers', []))->pluck('type');

            if ($legalType === 'individual' && $activity === 'contractor' && ! $types->contains('qid')) {
                $validator->errors()->add('identifiers', 'An individual contractor requires a QID and contractor activity.');
            }

            if ($legalType === 'individual' && $activity === 'owner' && ! $types->contains('qid')) {
                $validator->errors()->add('identifiers', 'An individual owner requires a QID.');
            }

            if ($legalType === 'individual' && ! in_array($activity, ['contractor', 'owner'], true)) {
                $validator->errors()->add('activity_type', 'An individual may register only as contractor or owner.');
            }

            if ($legalType === 'organization' && ! $types->contains('cr')) {
                $validator->errors()->add('identifiers', 'A company requires a CR.');
            }

            if (
                $legalType === 'organization'
                && in_array($activity, ['consultant', 'both'], true)
                && ! $types->contains('professional_license')
            ) {
                $validator->errors()->add('identifiers', 'An engineering consultancy requires a professional license in addition to its CR.');
            }

            if ($legalType === 'individual' && $types->contains(fn ($type) => $type !== 'qid')) {
                $validator->errors()->add('identifiers', 'An individual registration may submit only a QID identifier.');
            }
            if ($legalType === 'organization' && $types->contains('qid')) {
                $validator->errors()->add('identifiers', 'A company registration cannot use an individual QID identifier.');
            }

            if (
                $types->count() !== $types->unique()->count()
                || collect($this->input('identifiers', []))->map(
                    fn (array $identifier): string => $identifier['type'].'|'.TenantIdentifier::normalize($identifier['number'] ?? '')
                )->count() !== collect($this->input('identifiers', []))->map(
                    fn (array $identifier): string => $identifier['type'].'|'.TenantIdentifier::normalize($identifier['number'] ?? '')
                )->unique()->count()
            ) {
                $validator->errors()->add('identifiers', 'Duplicate identifiers in one registration request are not allowed.');
            }
        });
    }
}
