<?php

declare(strict_types=1);

namespace App\Http\Controllers\IAM;

use App\Audit\AuditService;
use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use App\IAM\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * AuthController — Login and Logout via Sanctum PAT (HB-1).
 *
 * Tenant schema switching is handled upstream by SwitchToTenantSchema
 * middleware, which runs after ResolveTenantContext and before auth:sanctum.
 * This controller relies on the 'tenant' connection already being configured
 * correctly when it executes.
 *
 * Pipeline:
 *   ResolveTenantContext → SwitchToTenantSchema → [auth:sanctum] → Controller
 *
 * Audit integration (TASK-001C Unit 3):
 *   - Login success/failure → AuditService::logEvent() (Security Observational — NEW-003 §3.4)
 *   - Logout → AuditService::logEvent() (Security Observational — NEW-003 §3.4)
 *   - tenant_slug comes from the resolved TenantContext (OD-001) — NOT from caller input.
 *   - Synchronous logEvent() only. Does NOT implement NEW-003 Async Mandatory Durable Retry.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Login: validate credentials, issue a Sanctum PAT.
     *
     * POST /api/auth/login
     * Headers: X-Tenant: {slug}
     * Body: { email, password }
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // tenant_slug from the resolved TenantContext — authoritative source (OD-001).
        $tenantContext = app(TenantContext::class);
        $tenantSlug    = $tenantContext->slug;

        // Explicitly query on the 'tenant' connection (OD-001 / OD-017).
        // SwitchToTenantSchema middleware has already configured this connection
        // to point at the correct per-tenant schema.
        $user = User::on('tenant')
            ->where('email', $request->email)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            // Failed login audit — Security Observational (NEW-003 §3.4).
            // actor_id = null: no authenticated user for a failed attempt.
            // Authority result (rejection) is fixed — audit is observational only.
            $this->auditService->logEvent(
                'auth.login_failed',
                null,
                [
                    'tenant_slug'  => $tenantSlug,
                    'subject_type' => User::class,
                    'after'        => ['email' => $request->email, 'reason' => 'invalid_credentials'],
                ]
            );

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        // Successful login audit — Security Observational (NEW-003 §3.4).
        // actor_id = authenticated user's id.
        $this->auditService->logEvent(
            'auth.login',
            (string) $user->id,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => User::class,
                'subject_id'   => (string) $user->id,
            ]
        );

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Logout: revoke the current PAT (server-side deletion — HB-1).
     *
     * POST /api/auth/logout
     * Headers: Authorization: Bearer {token}, X-Tenant: {slug}
     *
     * SwitchToTenantSchema middleware has already configured the 'tenant'
     * connection before auth:sanctum executed, so the PAT was looked up on
     * the correct tenant schema. No additional connection switching is needed.
     *
     * Audit order (TASK-001C Unit 3 §4):
     *   1. Capture actor identity BEFORE token revocation.
     *   2. Revoke the PAT.
     *   3. Log the audit event.
     */
    public function logout(Request $request): JsonResponse
    {
        // tenant_slug from the resolved TenantContext — authoritative source (OD-001).
        $tenantContext = app(TenantContext::class);
        $tenantSlug    = $tenantContext->slug;

        // Capture actor identity BEFORE token revocation (TASK-001C Unit 3 §4).
        // auth:sanctum has already authenticated the user — $request->user() is available.
        $actorId = $request->user()?->id ? (string) $request->user()->id : null;

        // Revoke the current PAT server-side (HB-1).
        // Sanctum PAT format: "{id}|{token}" — only the part after | is hashed in the DB.
        $bearerToken = $request->bearerToken();

        if ($bearerToken) {
            $parts = explode('|', $bearerToken, 2);
            $tokenValue = $parts[1] ?? $bearerToken;
            $tokenHash  = hash('sha256', $tokenValue);

            DB::connection('tenant')
                ->table('personal_access_tokens')
                ->where('token', $tokenHash)
                ->delete();
        }

        // Logout audit — Security Observational (NEW-003 §3.4).
        // Logged AFTER revocation; actor captured before revocation above.
        $this->auditService->logEvent(
            'auth.logout',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => User::class,
                'subject_id'   => $actorId,
            ]
        );

        return response()->json(['message' => 'Logged out successfully.']);
    }

    // =========================================================================
    // Current User Identity (B-01 — Mobile /api/auth/me)
    // =========================================================================

    /**
     * Return the authenticated user's profile.
     *
     * GET /api/auth/me
     * Headers: Authorization: Bearer {token}, X-Tenant: {slug}
     *
     * Used by Flutter DashboardScreen to display real user data from the server
     * instead of relying solely on local session data (B-01).
     *
     * Contract:
     * {
     *   "id":         "uuid",
     *   "name":       "string",
     *   "email":      "string",
     *   "tenant_slug":"string"
     * }
     */
    public function me(Request $request): JsonResponse
    {
        $user          = $request->user();
        $tenantContext = app(TenantContext::class);

        return response()->json([
            'id'          => (string) $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'tenant_slug' => $tenantContext->slug,
        ]);
    }

    // =========================================================================
    // SPA Cookie-based Authentication (C-002 §26 — Web Auth RESOLVED 2026-10-07)
    // =========================================================================

    /**
     * SPA Login: validate credentials and create a session (no PAT issued).
     *
     * POST /api/auth/spa/login
     * Headers: X-Tenant: {slug}, X-XSRF-TOKEN: {csrf}
     * Body: { email, password }
     *
     * Uses Sanctum SPA Cookie auth — session stored server-side.
     * Client receives HttpOnly session cookie. No token in response body.
     * Tenant context resolved by ResolveTenantContext middleware (OD-001).
     */
    public function spaLogin(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $tenantContext = app(TenantContext::class);
        $tenantSlug    = $tenantContext->slug;

        $user = User::on('tenant')
            ->where('email', $request->email)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            $this->auditService->logEvent(
                'auth.spa_login_failed',
                null,
                [
                    'tenant_slug'  => $tenantSlug,
                    'subject_type' => User::class,
                    'after'        => ['email' => $request->email, 'reason' => 'invalid_credentials'],
                ]
            );

            return response()->json(['message' => 'The provided credentials are incorrect.'], 401);
        }

        // Create session (SPA cookie auth — no PAT).
        // Guard: session is only available when EnsureFrontendRequestsAreStateful
        // has activated it (i.e. the request Origin matches SANCTUM_STATEFUL_DOMAINS).
        // Non-stateful origins (e.g. mobile apps) must use POST /api/auth/login (PAT).
        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'SPA login requires a stateful origin. Use POST /api/auth/login for token-based authentication.',
            ], 422);
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $this->auditService->logEvent(
            'auth.spa_login',
            (string) $user->id,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => User::class,
                'subject_id'   => (string) $user->id,
            ]
        );

        return response()->json([
            'user' => [
                'id'    => (string) $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ], 200);
    }

    /**
     * SPA Logout: invalidate the server-side session.
     *
     * POST /api/auth/spa/logout
     * Headers: X-Tenant: {slug}, X-XSRF-TOKEN: {csrf}
     */
    public function spaLogout(Request $request): JsonResponse
    {
        $actorId = $request->user() ? (string) $request->user()->id : null;
        $tenantContext = app(TenantContext::class);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($actorId) {
            $this->auditService->logEvent(
                'auth.spa_logout',
                $actorId,
                [
                    'tenant_slug'  => $tenantContext->slug,
                    'subject_type' => User::class,
                    'subject_id'   => $actorId,
                ]
            );
        }

        return response()->json(['message' => 'Logged out successfully.'], 200);
    }
}
