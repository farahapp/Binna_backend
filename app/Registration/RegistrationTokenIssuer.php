<?php

declare(strict_types=1);

namespace App\Registration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegistrationTokenIssuer
{
    /**
     * @return array{token: string, expires_at: string}
     */
    public function issue(string $table, string $foreignKey, int $identityId): array
    {
        $plain = Str::random(48);
        $expiresAt = now()->addHours((int) config('registration.otp.session_hours', 8));

        $id = DB::connection('mysql')->table($table)->insertGetId([
            $foreignKey => $identityId,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'token' => $id.'|'.$plain,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
