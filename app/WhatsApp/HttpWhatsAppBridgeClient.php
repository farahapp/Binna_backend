<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\WhatsApp\Contracts\WhatsAppBridgeContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class HttpWhatsAppBridgeClient implements WhatsAppBridgeContract
{
    public function health(): array
    {
        $response = Http::timeout($this->timeout())->get($this->base().'/health');
        if (! $response->successful()) {
            throw new RuntimeException('whatsapp_bridge_unhealthy');
        }

        return $response->json() ?? [];
    }

    public function accountStatus(string $slot, string $role = 'active'): array
    {
        return $this->signed('GET', "/accounts/{$slot}/status?role={$role}");
    }

    public function startSession(string $slot, string $role = 'candidate', ?string $mockPhone = null): array
    {
        $payload = ['role' => $role];
        if ($mockPhone) {
            $payload['mock_phone'] = $mockPhone;
        }

        return $this->signed('POST', "/accounts/{$slot}/session/start", $payload);
    }

    public function qr(string $slot, string $role = 'candidate'): ?array
    {
        try {
            return $this->signed('GET', "/accounts/{$slot}/session/qr?role={$role}");
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), ':404')) {
                return null;
            }
            throw $e;
        }
    }

    public function logout(string $slot, string $role): array
    {
        return $this->signed('POST', "/accounts/{$slot}/session/logout", ['role' => $role]);
    }

    public function reconnect(string $slot, string $role = 'active'): array
    {
        return $this->signed('POST', "/accounts/{$slot}/session/reconnect", ['role' => $role]);
    }

    public function cancelPairing(string $slot, string $role = 'candidate'): array
    {
        return $this->signed('POST', "/accounts/{$slot}/session/cancel-pairing", ['role' => $role]);
    }

    public function disconnect(string $slot, string $role = 'active'): array
    {
        return $this->signed('POST', "/accounts/{$slot}/session/disconnect", ['role' => $role]);
    }

    public function backup(string $slot): array
    {
        return $this->signed('POST', "/accounts/{$slot}/session/backup", []);
    }

    public function restore(string $slot): array
    {
        return $this->signed('POST', "/accounts/{$slot}/session/restore", []);
    }

    public function promote(string $slot): array
    {
        return $this->signed('POST', "/accounts/{$slot}/session/promote", []);
    }

    public function send(string $slot, string $toE164, string $text, string $template, ?string $messageId = null): array
    {
        return $this->signed('POST', "/accounts/{$slot}/messages/send", [
            'to_e164' => $toE164,
            'text' => $text,
            'template' => $template,
            'message_id' => $messageId,
        ]);
    }

    public function mockComplete(string $slot, string $role, string $phoneE164): array
    {
        return $this->signed('POST', "/accounts/{$slot}/mock/complete", [
            'role' => $role,
            'phone_e164' => $phoneE164,
        ]);
    }

    public function securityStatus(): array
    {
        return $this->signed('GET', '/security/status');
    }

    public function createEncryptedBackup(string $slot, string $role = 'active'): array
    {
        return $this->signed('POST', '/security/backup', ['slot' => $slot, 'role' => $role]);
    }

    public function verifyEncryptedBackup(string $slot, string $backupId): array
    {
        return $this->signed('POST', '/security/verify', ['slot' => $slot, 'backup_id' => $backupId]);
    }

    public function recoveryTest(string $slot, string $backupId): array
    {
        return $this->signed('POST', '/security/recovery-test', ['slot' => $slot, 'backup_id' => $backupId]);
    }

    public function listEncryptedBackups(string $slot): array
    {
        $result = $this->signed('GET', '/security/backups?slot='.$slot);

        return $result['backups'] ?? [];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function signed(string $method, string $path, ?array $payload = null): array
    {
        $secret = (string) config('whatsapp.bridge.shared_secret');
        if ($secret === '') {
            throw new RuntimeException('whatsapp_bridge_secret_missing');
        }
        $body = $payload === null ? '' : (string) json_encode($payload, JSON_THROW_ON_ERROR);
        $ts = (string) time();
        $signature = hash_hmac('sha256', $ts.'.'.$body, $secret);
        $pending = Http::timeout($this->timeout())->withHeaders([
            'Accept' => 'application/json',
            'X-Bridge-Timestamp' => $ts,
            'X-Bridge-Signature' => $signature,
        ]);
        $url = $this->base().$path;
        $response = match (strtoupper($method)) {
            'GET' => $pending->withBody($body, 'application/json')->get($url),
            'POST' => $pending->withBody($body, 'application/json')->post($url),
            default => throw new RuntimeException('unsupported_method'),
        };
        if (! $response->successful()) {
            Log::warning('WhatsApp bridge request failed', [
                'path' => preg_replace('#/session/qr.*#', '/session/qr', $path),
                'status' => $response->status(),
            ]);
            throw new RuntimeException('whatsapp_bridge_request_failed:'.$response->status());
        }

        return $response->json() ?? [];
    }

    private function base(): string
    {
        return rtrim((string) config('whatsapp.bridge.base_url'), '/');
    }

    private function timeout(): int
    {
        return max(1, (int) config('whatsapp.bridge.timeout_seconds', 10));
    }
}
