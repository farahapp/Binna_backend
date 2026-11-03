<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Foundation\PlatformUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * PlatformAuthController — Platform operator authentication.
 *
 * Handles login/logout/me for BINAA2 platform operators (not tenant users).
 * Uses the 'platform' Sanctum guard backed by the shared-schema platform_users table.
 *
 * Routes (no X-Tenant header required — platform routes are tenant-agnostic):
 *   POST /api/platform/auth/login            — issue a platform PAT
 *   POST /api/platform/auth/logout           — revoke the current platform PAT
 *   GET  /api/platform/auth/me               — return the authenticated operator's profile
 *   POST /api/platform/auth/change-password  — change the authenticated operator's password
 *
 * Security invariants:
 *  - No X-Tenant header is required or used — platform operators are not scoped to any tenant.
 *  - Credentials are validated against platform_users (shared schema, 'mysql' connection).
 *  - Inactive operators are rejected by RequirePlatformOperator middleware (403).
 *  - Tenant users cannot authenticate here — wrong provider, wrong table.
 */
class PlatformAuthController extends Controller
{
    /**
     * Login: validate credentials, issue a platform Sanctum PAT.
     *
     * POST /api/platform/auth/login
     * Body: { email, password }
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /** @var PlatformUser|null $operator */
        $operator = PlatformUser::on('mysql')
            ->where('email', $request->email)
            ->first();

        if (! $operator || ! Hash::check($request->password, $operator->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $operator->isActive()) {
            return response()->json([
                'error' => 'Platform operator account is inactive.',
            ], 403);
        }

        // Issue a platform-scoped PAT via createPlatformToken().
        // This writes to platform_personal_access_tokens on mysql — never to the
        // tenant connection. Do NOT use createToken() here; that would write to
        // App\IAM\PersonalAccessToken on the 'tenant' connection (wrong table, wrong DB).
        $token = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        return response()->json([
            'token'      => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Logout: revoke the current platform PAT.
     *
     * POST /api/platform/auth/logout
     * Headers: Authorization: Bearer {token}
     */
    public function logout(Request $request): JsonResponse
    {
        $bearerToken = $request->bearerToken();

        // Revoke via the HasApiTokens currentAccessToken() — works for both
        // PAT (bearer token) and session-based auth. Falls back to raw DB delete
        // if currentAccessToken() is not available (e.g. unauthenticated call).
        $operator = $request->user();
        if ($operator && method_exists($operator, 'currentAccessToken')) {
            $operator->currentAccessToken()->delete();
        } elseif ($bearerToken) {
            $parts      = explode('|', $bearerToken, 2);
            $tokenValue = $parts[1] ?? $bearerToken;
            $tokenHash  = hash('sha256', $tokenValue);

            // D-2 fix: platform PATs are stored in platform_personal_access_tokens
            // on mysql — NOT in personal_access_tokens (which is the tenant table).
            \Illuminate\Support\Facades\DB::connection('mysql')
                ->table('platform_personal_access_tokens')
                ->where('token', $tokenHash)
                ->delete();
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * Return the authenticated platform operator's profile.
     *
     * GET /api/platform/auth/me
     * Headers: Authorization: Bearer {token}
     */
    public function me(Request $request): JsonResponse
    {
        /** @var PlatformUser $operator */
        $operator = $request->user();

        return response()->json([
            'id'        => $operator->id,
            'name'      => $operator->name,
            'email'     => $operator->email,
            'is_active' => $operator->is_active,
        ]);
    }

    /**
     * Change the authenticated platform operator's password.
     *
     * POST /api/platform/auth/change-password
     * Headers: Authorization: Bearer {token}
     * Body: { current_password, new_password, new_password_confirmation }
     *
     * Security invariants:
     *  - Requires auth:platform-token + platform.operator middleware (enforced in routes).
     *  - Verifies current_password against the stored hash before any write.
     *  - Stores new password using Hash::make() — never plain-text.
     *  - Does NOT return or log any password value or hash.
     *  - Does NOT revoke existing PATs — session continuity is preserved.
     *  - Only operates on the authenticated PlatformUser — cannot change another user's password.
     *  - Tenant users are rejected upstream by RequirePlatformOperator middleware.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password'      => ['required', 'string'],
            'new_password'          => ['required', 'string', 'min:8', 'confirmed'],
            'new_password_confirmation' => ['required', 'string'],
        ]);

        /** @var PlatformUser $operator */
        $operator = $request->user();

        // Verify current password — reject silently with 422 (no hint about which field is wrong).
        if (! Hash::check($request->current_password, $operator->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        // Reject if new password is identical to current password.
        if (Hash::check($request->new_password, $operator->password)) {
            throw ValidationException::withMessages([
                'new_password' => ['The new password must be different from the current password.'],
            ]);
        }

        // Persist the new hashed password directly on the shared mysql connection.
        // PlatformUser $casts['password'] = 'hashed' would re-hash on Eloquent save,
        // so we use DB::update to write the already-hashed value without double-hashing.
        \Illuminate\Support\Facades\DB::connection('mysql')
            ->table('platform_users')
            ->where('id', $operator->id)
            ->update([
                'password'   => Hash::make($request->new_password),
                'updated_at' => now(),
            ]);

        // No password, hash, or token is returned.
        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }
}
