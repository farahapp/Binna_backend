<?php

declare(strict_types=1);

namespace App\Registration;

use App\Mail\RegistrationOtpMail;
use App\WhatsApp\WhatsAppDispatchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ApplicantOtpService
{
    public function send(string $email, ?string $phoneE164 = null): void
    {
        app(RegistrationMailDeliveryGuard::class)->assertAvailable();
        $email = Str::lower(trim($email));
        $phoneE164 = $phoneE164 !== null && $phoneE164 !== '' ? trim($phoneE164) : null;

        $challenge = DB::connection('mysql')->transaction(function () use ($email): array {
            $previous = DB::connection('mysql')->table('registration_otp_challenges')
                ->where('email', $email)
                ->whereNull('consumed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (
                $previous
                && now()->diffInSeconds($previous->sent_at) < config('registration.otp.resend_cooldown_seconds', 60)
            ) {
                abort(429, 'Please wait before requesting another verification code.');
            }

            $code = (string) random_int(100000, 999999);
            $id = DB::connection('mysql')->table('registration_otp_challenges')->insertGetId([
                'email' => $email,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes((int) config('registration.otp.expires_minutes', 5)),
                'sent_at' => now(),
                'attempts' => 0,
                'consumed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['code' => $code, 'id' => $id];
        });

        // WhatsApp optional (Primary→Secondary failover). Same OTP code. Email always delivered.
        if ($phoneE164 !== null) {
            try {
                app(WhatsAppDispatchService::class)->sendRegistrationOtp(
                    $phoneE164,
                    $challenge['code'],
                    'challenge:'.$challenge['id'],
                );
            } catch (\Throwable) {
                // Never fail registration OTP on WhatsApp errors.
            }
        }

        Mail::to($email)->send(new RegistrationOtpMail($challenge['code']));
    }

    public function verify(string $email, string $code): ?Applicant
    {
        $email = Str::lower(trim($email));

        return DB::connection('mysql')->transaction(function () use ($email, $code): ?Applicant {
            $challenge = DB::connection('mysql')->table('registration_otp_challenges')
                ->where('email', $email)
                ->whereNull('consumed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (
                ! $challenge
                || $challenge->attempts >= config('registration.otp.max_attempts', 5)
                || now()->greaterThanOrEqualTo($challenge->expires_at)
            ) {
                return null;
            }

            DB::connection('mysql')->table('registration_otp_challenges')
                ->where('id', $challenge->id)
                ->increment('attempts');

            if (! Hash::check($code, $challenge->code_hash)) {
                return null;
            }

            DB::connection('mysql')->table('registration_otp_challenges')
                ->where('id', $challenge->id)
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            $applicant = Applicant::on('mysql')->firstOrCreate(
                ['email' => $email],
                ['email_verified_at' => now()],
            );
            $applicant->forceFill(['email_verified_at' => now()])->save();

            return $applicant;
        });
    }
}
