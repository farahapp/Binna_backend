<?php

declare(strict_types=1);

namespace App\Auth;

use App\Foundation\PlatformUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PlatformGuard — stateless bearer-token guard for BINAA2 platform operators.
 *
 * Authenticates requests by looking up a bearer token in the
 * platform_personal_access_tokens table on the shared 'mysql' connection.
 *
 * This guard is entirely independent of Laravel Sanctum's global
 * PersonalAccessToken model (App\IAM\PersonalAccessToken), which uses the
 * 'tenant' connection. The two token stores are completely isolated:
 *
 *   Platform tokens  → platform_personal_access_tokens  (mysql)
 *   Tenant tokens    → personal_access_tokens            (tenant)
 *
 * Token format (Sanctum-compatible):
 *   plainTextToken = "{id}|{randomString}"
 *   stored token   = hash('sha256', $randomString)
 *
 * Security invariants:
 *  - Only resolves tokens whose tokenable_type = App\Foundation\PlatformUser.
 *  - Never queries the 'tenant' connection.
 *  - Never uses Sanctum::$personalAccessTokenModel.
 *  - Updates last_used_at on every successful authentication.
 *  - Returns null (not an exception) for any invalid/missing token.
 *  - is_active check is delegated to RequirePlatformOperator middleware.
 */
class PlatformGuard implements Guard
{
    private ?Authenticatable $resolvedUser = null;
    private bool             $userResolved = false;

    public function __construct(
        private readonly Request $request,
    ) {}

    // =========================================================================
    // Guard contract
    // =========================================================================

    /**
     * Determine if the current user is authenticated.
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Determine if the current user is a guest (not authenticated).
     */
    public function guest(): bool
    {
        return ! $this->check();
    }

    /**
     * Get the currently authenticated user.
     *
     * Returns null if:
     *  - No bearer token is present.
     *  - The token is not found in platform_personal_access_tokens on mysql.
     *  - The token's tokenable_type is not App\Foundation\PlatformUser.
     *  - The token has expired.
     *  - The PlatformUser record no longer exists.
     */
    public function user(): ?Authenticatable
    {
        if ($this->userResolved) {
            return $this->resolvedUser;
        }

        $this->userResolved  = true;
        $this->resolvedUser  = $this->resolveUserFromBearerToken();

        return $this->resolvedUser;
    }

    /**
     * Get the ID of the currently authenticated user.
     */
    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * Validate a user's credentials.
     * Not used for bearer-token guards — always returns false.
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        return $this->resolvedUser !== null;
    }

    /**
     * Set the current user.
     */
    public function setUser(Authenticatable $user): static
    {
        $this->resolvedUser = $user;
        $this->userResolved = true;

        return $this;
    }

    // =========================================================================
    // Token resolution
    // =========================================================================

    /**
     * Resolve a PlatformUser from the request's bearer token.
     *
     * Token format: "{id}|{rawToken}"
     * Stored hash:  hash('sha256', $rawToken)
     */
    private function resolveUserFromBearerToken(): ?Authenticatable
    {
        $bearerToken = $this->request->bearerToken();

        if (empty($bearerToken)) {
            return null;
        }

        // Split into ID and raw token value.
        $parts    = explode('|', $bearerToken, 2);
        $tokenId  = $parts[0] ?? null;
        $rawToken = $parts[1] ?? null;

        // Both parts are required.
        if (! $tokenId || ! $rawToken) {
            return null;
        }

        $tokenHash = hash('sha256', $rawToken);

        // Query platform_personal_access_tokens on mysql only.
        // Scope to PlatformUser tokenable_type to prevent any cross-type resolution.
        $tokenRecord = DB::connection('mysql')
            ->table('platform_personal_access_tokens')
            ->where('id', (int) $tokenId)
            ->where('token', $tokenHash)
            ->where('tokenable_type', PlatformUser::class)
            ->first();

        if (! $tokenRecord) {
            return null;
        }

        // Reject expired tokens.
        if ($tokenRecord->expires_at !== null) {
            $expiresAt = \Carbon\Carbon::parse($tokenRecord->expires_at);
            if ($expiresAt->isPast()) {
                return null;
            }
        }

        // Load the PlatformUser from mysql.
        /** @var PlatformUser|null $operator */
        $operator = PlatformUser::on('mysql')->find($tokenRecord->tokenable_id);

        if (! $operator) {
            return null;
        }

        // Update last_used_at — standard Sanctum behavior for audit trails.
        DB::connection('mysql')
            ->table('platform_personal_access_tokens')
            ->where('id', $tokenRecord->id)
            ->update(['last_used_at' => now()]);

        // D-1/D-3 fix: bind the resolved PAT record to the operator so that
        // $operator->currentAccessToken() returns the real token model, not a
        // TransientToken. This is required for logout() to call
        // $operator->currentAccessToken()->delete() and actually revoke the token.
        //
        // IMPORTANT: use newFromBuilder() — NOT make() — so that $model->exists
        // is set to true. Eloquent's delete() is a silent no-op when exists=false
        // (make() leaves exists=false; newFromBuilder() sets exists=true).
        $tokenModel = (new \Laravel\Sanctum\PersonalAccessToken())->newFromBuilder([
            'id'             => $tokenRecord->id,
            'tokenable_type' => $tokenRecord->tokenable_type,
            'tokenable_id'   => $tokenRecord->tokenable_id,
            'name'           => $tokenRecord->name,
            'token'          => $tokenRecord->token,
            'abilities'      => $tokenRecord->abilities,
            'last_used_at'   => $tokenRecord->last_used_at,
            'expires_at'     => $tokenRecord->expires_at,
            'created_at'     => $tokenRecord->created_at,
            'updated_at'     => $tokenRecord->updated_at,
        ]);

        // Force the model to use the mysql connection and correct table so that
        // delete() targets platform_personal_access_tokens on mysql, not the
        // default Sanctum table on the tenant connection.
        $tokenModel->setConnection('mysql');
        $tokenModel->setTable('platform_personal_access_tokens');

        $operator->withAccessToken($tokenModel);

        return $operator;
    }
}
