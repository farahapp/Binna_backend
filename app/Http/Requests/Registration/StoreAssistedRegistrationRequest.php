<?php

declare(strict_types=1);

namespace App\Http\Requests\Registration;

class StoreAssistedRegistrationRequest extends StoreTenantRegistrationRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'applicant_email' => ['required', 'email:rfc', 'max:255'],
            'owner_consent' => ['required', 'accepted'],
        ]);
    }
}
