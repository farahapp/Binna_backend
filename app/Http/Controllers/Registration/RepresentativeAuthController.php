<?php

declare(strict_types=1);

namespace App\Http\Controllers\Registration;

use App\Http\Controllers\Controller;
use App\Mail\RepresentativeInvitationMail;
use App\Registration\RegistrationAuditService;
use App\Registration\RegistrationMailDeliveryGuard;
use App\Registration\RegistrationTokenIssuer;
use App\Registration\Representative;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class RepresentativeAuthController extends Controller
{
    public function login(
        Request $request,
        RegistrationTokenIssuer $tokens,
        RegistrationAuditService $audit,
    ): JsonResponse {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $representative = Representative::on('mysql')
            ->where('email', Str::lower(trim($data['email'])))
            ->first();

        if (
            ! $representative
            || $representative->status !== 'active'
            || ! $representative->password
            || ! Hash::check($data['password'], $representative->password)
        ) {
            return response()->json(['message' => 'Invalid credentials or inactive account.'], 401);
        }

        $session = $tokens->issue('registration_representative_tokens', 'representative_id', $representative->id);
        $audit->recordIdentity('representative', $representative->id, 'representative', $representative->id, 'identity.login');

        return response()->json([
            'representative' => ['id' => $representative->id, 'name' => $representative->name, 'email' => $representative->email],
            ...$session,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Representative $representative */
        $representative = $request->user();
        return response()->json([
            'representative' => [
                'id' => $representative->id,
                'name' => $representative->name,
                'email' => $representative->email,
            ],
        ]);
    }

    public function logout(Request $request, RegistrationAuditService $audit): JsonResponse
    {
        /** @var Representative $representative */
        $representative = $request->user();
        $tokenId = $request->attributes->get('registration_token_id');
        if ($tokenId !== null) {
            DB::connection('mysql')->table('registration_representative_tokens')
                ->where('id', $tokenId)
                ->where('representative_id', $representative->id)
                ->delete();
        }
        $audit->recordIdentity('representative', $representative->id, 'representative', $representative->id, 'identity.logout');

        return response()->json(status: 204);
    }

    public function requestPasswordReset(Request $request, RegistrationAuditService $audit): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $email = Str::lower(trim($data['email']));
        $representative = Representative::on('mysql')
            ->where('email', $email)
            ->where('status', 'active')
            ->first();

        if ($representative) {
            app(RegistrationMailDeliveryGuard::class)->assertAvailable();
            $audit->recordIdentity(
                'representative',
                $representative->id,
                'representative',
                $representative->id,
                'identity.recovery_requested',
            );
            $this->sendInvite($representative, 'password_reset');
        }

        return response()->json([
            'message' => 'If the account is eligible, a secure recovery invitation has been sent.',
        ]);
    }

    public function consumeInvitation(
        Request $request,
        RegistrationAuditService $audit,
    ): JsonResponse {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:160'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        $result = DB::connection('mysql')->transaction(function () use ($data): ?array {
            $invite = DB::connection('mysql')->table('registration_representative_invites')
                ->where('token_hash', hash('sha256', $data['token']))
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();
            if (! $invite) {
                return null;
            }
            if (! in_array($invite->purpose, ['activation', 'password_reset'], true)) {
                return null;
            }

            $representative = Representative::on('mysql')->whereKey($invite->representative_id)
                ->lockForUpdate()->first();
            if (! $representative) {
                return null;
            }
            if (
                ($invite->purpose === 'activation' && $representative->status !== 'invited')
                || ($invite->purpose === 'password_reset' && $representative->status !== 'active')
            ) {
                return null;
            }

            DB::connection('mysql')->table('registration_representative_invites')
                ->where('id', $invite->id)
                ->update(['consumed_at' => now(), 'updated_at' => now()]);
            $representative->password = $data['password'];
            if ($invite->purpose === 'activation') {
                $representative->status = 'active';
            }
            $representative->save();
            if ($invite->purpose === 'password_reset') {
                DB::connection('mysql')->table('registration_representative_tokens')
                    ->where('representative_id', $representative->id)->delete();
            }

            return [$representative, $invite->purpose];
        });

        if (! $result) {
            return response()->json(['message' => 'The invitation is invalid or expired.'], 422);
        }

        /** @var Representative $representative */
        [$representative, $purpose] = $result;
        $audit->recordIdentity(
            'representative',
            $representative->id,
            'representative',
            $representative->id,
            $purpose === 'activation' ? 'identity.invitation_consumed' : 'identity.recovery_completed',
        );

        return response()->json(['message' => 'Account security details updated.']);
    }

    public function sendInvite(Representative $representative, string $purpose): void
    {
        if (
            ! in_array($purpose, ['activation', 'password_reset'], true)
            || ($purpose === 'activation' && $representative->status !== 'invited')
            || ($purpose === 'password_reset' && $representative->status !== 'active')
        ) {
            throw new \InvalidArgumentException('This representative is not eligible for the requested account action.');
        }
        app(RegistrationMailDeliveryGuard::class)->assertAvailable();
        $frontendUrl = rtrim((string) config('registration.frontend_url'), '/');
        if (
            ! filter_var($frontendUrl, FILTER_VALIDATE_URL)
            || (app()->environment('production') && ! str_starts_with($frontendUrl, 'https://'))
        ) {
            throw new ServiceUnavailableHttpException(null, 'Email delivery is temporarily unavailable.');
        }
        $plain = Str::random(64);
        $expiresAt = now()->addMinutes((int) config('registration.representative_invite.expires_minutes', 30));

        DB::connection('mysql')->table('registration_representative_invites')
            ->where('representative_id', $representative->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now(), 'updated_at' => now()]);
        DB::connection('mysql')->table('registration_representative_invites')->insert([
            'representative_id' => $representative->id,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $url = $frontendUrl.'/representative/activate?token='.urlencode($plain);
        Mail::to($representative->email)->send(new RepresentativeInvitationMail($url, $purpose));
    }

}
