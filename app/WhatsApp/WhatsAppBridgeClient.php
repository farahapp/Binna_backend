<?php

declare(strict_types=1);

namespace App\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * HMAC-authenticated client for the local Baileys bridge.
 * Never logs OTP bodies, QR payloads, or shared secrets.
 */
class WhatsAppBridgeClient
{
    public function health(): array
    {
        $response = Http::timeout($this->timeout())
            ->get($this->baseUrl().'/health');

        if (! $response->successful()) {
            throw new RuntimeException('whatsapp_bridge_unhealthy');
        }

        return $response->json() ?? [];
    }

    public function status(): array
    {
        return $this->signedJson('GET', '/status');
    }

    public function startSession(): array
    {
        return $this->signedJson('POST', '/session/start', []);
    }

    public function qr(): array
    {
        return $this->signedJson('GET', '/session/qr');
    }

    public function logout(): array
    {
        return $this->signedJson('POST', '/session/logout', []);
    }

    /**
     * @param  non-empty-string  $template  registration_otp|operational_notice|connection_test
     */
    public function sendText(string $toE164, string $text, string $template, ?string $messageId = null): array
    {
        return $this->signedJson('POST', '/messages/send', [
            'to_e164' => $toE164,
            'text' => $text,
            'template' => $template,
            'message_id' => $messageId,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function signedJson(string $method, string $path, ?array $payload = null): array
    {
        $secret = (string) config('whatsapp.bridge.shared_secret');
        if ($secret === '') {
            throw new RuntimeException('whatsapp_bridge_secret_missing');
        }

        $body = $payload === null ? '' : (string) json_encode($payload, JSON_THROW_ON_ERROR);
        $ts = (string) time();
        $signature = hash_hmac('sha256', $ts.'.'.$body, $secret);

        $pending = Http::timeout($this->timeout())
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Bridge-Timestamp' => $ts,
                'X-Bridge-Signature' => $signature,
            ]);

        $url = $this->baseUrl().$path;
        $response = match (strtoupper($method)) {
            'GET' => $pending->withBody($body, 'application/json')->get($url),
            'POST' => $pending->withBody($body, 'application/json')->post($url),
            default => throw new RuntimeException('unsupported_method'),
        };

        if (! $response->successful()) {
            Log::warning('WhatsApp bridge request failed', [
                'path' => $path,
                'status' => $response->status(),
            ]);
            throw new RuntimeException('whatsapp_bridge_request_failed:'.$response->status());
        }

        return $response->json() ?? [];
    }

    private function baseUrl(): string
    {
        return (string) config('whatsapp.bridge.base_url');
    }

    private function timeout(): int
    {
        return max(1, (int) config('whatsapp.bridge.timeout_seconds', 10));
    }
}
