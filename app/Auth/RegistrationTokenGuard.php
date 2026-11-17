<?php

declare(strict_types=1);

namespace App\Auth;

use App\Registration\Applicant;
use App\Registration\Representative;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegistrationTokenGuard implements Guard
{
    private ?Authenticatable $resolvedUser = null;
    private bool $userResolved = false;

    /**
     * @param class-string<Applicant|Representative> $modelClass
     */
    public function __construct(
        private readonly Request $request,
        private readonly string $modelClass,
        private readonly string $table,
        private readonly string $foreignKey,
    ) {}

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->userResolved) {
            return $this->resolvedUser;
        }

        $this->userResolved = true;
        $bearerToken = $this->request->bearerToken();
        if (! $bearerToken || ! preg_match('/^(\d+)\|(.+)$/', $bearerToken, $parts)) {
            return null;
        }

        $token = DB::connection('mysql')->table($this->table)
            ->where('id', (int) $parts[1])
            ->where('token_hash', hash('sha256', $parts[2]))
            ->where('expires_at', '>', now())
            ->first();

        if (! $token) {
            return null;
        }

        $model = $this->modelClass::on('mysql')->find($token->{$this->foreignKey});
        if (! $model || ! $this->isEnabled($model)) {
            return null;
        }

        DB::connection('mysql')->table($this->table)
            ->where('id', $token->id)
            ->update(['last_used_at' => now(), 'updated_at' => now()]);

        $this->request->attributes->set('registration_token_id', $token->id);
        $this->resolvedUser = $model;

        return $this->resolvedUser;
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return $this->resolvedUser !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->resolvedUser = $user;
        $this->userResolved = true;

        return $this;
    }

    private function isEnabled(Applicant|Representative $user): bool
    {
        return match (true) {
            $user instanceof Applicant => $user->email_verified_at !== null,
            $user instanceof Representative => $user->status === 'active',
        };
    }
}
