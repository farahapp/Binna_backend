<?php

declare(strict_types=1);

namespace App\Http\Controllers\Registration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\ApplicantOtpRequest;
use App\Registration\Applicant;
use App\Registration\ApplicantOtpService;
use App\Registration\RegistrationAuditService;
use App\Registration\RegistrationTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApplicantIdentityController extends Controller
{
    public function requestOtp(ApplicantOtpRequest $request, ApplicantOtpService $otp): JsonResponse
    {
        $data = $request->validated();
        $otp->send($data['email'], $data['phone_e164'] ?? null);

        return response()->json([
            'message' => 'If the address can receive verification, a code has been sent.',
        ]);
    }

    public function verifyOtp(
        Request $request,
        ApplicantOtpService $otp,
        RegistrationTokenIssuer $tokens,
        RegistrationAuditService $audit,
    ): JsonResponse {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'code' => ['required', 'digits:6'],
        ]);
        $email = Str::lower(trim($data['email']));
        $applicant = $otp->verify($email, $data['code']);

        if (! $applicant) {
            return response()->json(['message' => 'The verification code is invalid or expired.'], 422);
        }

        $session = $tokens->issue('registration_applicant_tokens', 'applicant_id', $applicant->id);
        $audit->recordIdentity('applicant', $applicant->id, 'applicant', $applicant->id, 'identity.email_verified');

        return response()->json([
            'applicant' => ['id' => $applicant->id, 'email' => $applicant->email],
            ...$session,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Applicant $applicant */
        $applicant = $request->user();
        return response()->json(['applicant' => ['id' => $applicant->id, 'email' => $applicant->email]]);
    }

    public function logout(Request $request, RegistrationAuditService $audit): JsonResponse
    {
        /** @var Applicant $applicant */
        $applicant = $request->user();
        $tokenId = $request->attributes->get('registration_token_id');
        if ($tokenId !== null) {
            DB::connection('mysql')->table('registration_applicant_tokens')
                ->where('id', $tokenId)
                ->where('applicant_id', $applicant->id)
                ->delete();
        }
        $audit->recordIdentity('applicant', $applicant->id, 'applicant', $applicant->id, 'identity.session_revoked');

        return response()->json(status: 204);
    }
}
