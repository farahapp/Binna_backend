<?php

declare(strict_types=1);

namespace App\Http\Requests\Registration;

use Illuminate\Foundation\Http\FormRequest;

class ApplicantOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            // Optional E.164 for WhatsApp OTP when platform channel is enabled + connected.
            'phone_e164' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\+[1-9]\d{7,14}$/'],
        ];
    }
}
