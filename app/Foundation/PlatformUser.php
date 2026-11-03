<?php
declare(strict_types=1);
namespace App\Foundation;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * PlatformUser — BINAA2 platform operator identity.
 *
 * Lives in the shared schema (mysql connection).
 * Entirely separate from tenant User models — no cross-contamination possible.
 *
 * Security invariants:
 *  - Uses 'mysql' connection — never the 'tenant' connection.
 *  - Has no tenant_id — platform operators are not scoped to any tenant.
 *  - Cannot be authenticated via the tenant Sanctum PAT flow.
 *  - is_active must be true for the operator to be considered valid.
 */
class PlatformUser extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasApiTokens;

    /** Shared-schema connection — never the per-tenant connection. */
    protected $connection = 'mysql';

    protected $table = 'platform_users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'password'   => 'hashed',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    // =========================================================================
    // Platform PAT issuance
    // =========================================================================

    /**
     * Issue a platform Personal Access Token stored on the shared mysql connection.
     *
     * This method bypasses Sanctum::$personalAccessTokenModel entirely.
     * It writes directly to platform_personal_access_tokens on mysql, ensuring
     * platform tokens are never mixed with per-tenant tokens.
     *
     * Token format (Sanctum-compatible): "{id}|{rawToken}"
     * Stored:  hash('sha256', $rawToken)
     *
     * @param  string        $name       Human-readable token name (e.g. 'platform-api')
     * @param  array<string> $abilities  Token abilities (e.g. ['platform:*'])
     * @param  \DateTimeInterface|null $expiresAt  Optional expiry; null = never expires
     * @return NewAccessToken
     */
    public function createPlatformToken(
        string $name,
        array $abilities = ['*'],
        ?\DateTimeInterface $expiresAt = null,
    ): NewAccessToken {
        $rawToken  = Str::random(40);
        $tokenHash = hash('sha256', $rawToken);

        $id = DB::connection('mysql')
            ->table('platform_personal_access_tokens')
            ->insertGetId([
                'tokenable_type' => static::class,
                'tokenable_id'   => $this->getKey(),
                'name'           => $name,
                'token'          => $tokenHash,
                'abilities'      => json_encode($abilities),
                'expires_at'     => $expiresAt,
                'last_used_at'   => null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

        // Build a minimal token model for NewAccessToken.
        // We use a plain stdClass so we do not depend on any Eloquent model
        // that might redirect to the wrong connection.
        $tokenModel        = new \stdClass();
        $tokenModel->id    = $id;
        $tokenModel->name  = $name;

        // NewAccessToken expects an object implementing HasAbilities.
        // We wrap in the Sanctum PersonalAccessToken base class hydrated from
        // the raw attributes so that ability checks work correctly.
        $pat = \Laravel\Sanctum\PersonalAccessToken::make([
            'id'             => $id,
            'tokenable_type' => static::class,
            'tokenable_id'   => $this->getKey(),
            'name'           => $name,
            'token'          => $tokenHash,
            'abilities'      => $abilities,
            'expires_at'     => $expiresAt,
            'last_used_at'   => null,
        ]);

        return new NewAccessToken($pat, "{$id}|{$rawToken}");
    }
}
