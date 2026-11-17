<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Audit\PlatformAuditService;
use App\Foundation\PlatformUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class WhatsAppPlatformService
{
    public function __construct(
        private readonly WhatsAppBridgeClient $bridge,
        private readonly PlatformAuditService $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $settings = $this->settingsRow();
        $bridgeHealth = null;
        $bridgeStatus = null;
        $bridgeError = null;

        try {
            $bridgeHealth = $this->bridge->health();
        } catch (\Throwable) {
            $bridgeError = 'bridge_unreachable';
        }

        if ($bridgeError === null) {
            try {
                $bridgeStatus = $this->bridge->status();
            } catch (\Throwable) {
                $bridgeError = 'bridge_auth_or_status_failed';
            }
        }

        return [
            'caveat' => 'Unofficial Baileys WhatsApp Web integration. Not Meta Business API. May break, require re-linking, or risk account restriction.',
            'flags' => [
                'enabled' => (bool) ($settings['enabled'] ?? false),
                'otp_enabled' => (bool) ($settings['otp_enabled'] ?? false),
                'notifications_enabled' => (bool) ($settings['notifications_enabled'] ?? false),
            ],
            'configured_sender_e164' => (string) ($settings['configured_sender_e164'] ?? ''),
            'linked_phone_e164' => $bridgeStatus['phone_e164'] ?? null,
            'connection_status' => $bridgeStatus['status'] ?? ($bridgeError ?? 'unknown'),
            'bridge_health' => $bridgeHealth,
            'bridge_error' => $bridgeError,
            'last_connected_at' => $bridgeStatus['last_connected_at'] ?? null,
            'has_qr' => (bool) ($bridgeStatus['has_qr'] ?? false),
        ];
    }

    /**
     * @param  array{enabled?:bool,otp_enabled?:bool,notifications_enabled?:bool,configured_sender_e164?:string}  $input
     * @return array<string, mixed>
     */
    public function updateFlags(?PlatformUser $actor, array $input): array
    {
        $this->ensureTable();
        $before = $this->settingsRow();

        $enabled = array_key_exists('enabled', $input) ? (bool) $input['enabled'] : (bool) $before['enabled'];
        $otp = array_key_exists('otp_enabled', $input) ? (bool) $input['otp_enabled'] : (bool) $before['otp_enabled'];
        $notifications = array_key_exists('notifications_enabled', $input) ? (bool) $input['notifications_enabled'] : (bool) $before['notifications_enabled'];
        $sender = array_key_exists('configured_sender_e164', $input)
            ? trim((string) $input['configured_sender_e164'])
            : (string) $before['configured_sender_e164'];

        // Hard safety: OTP/notifications require master enable
        if (! $enabled) {
            $otp = false;
            $notifications = false;
        }

        DB::connection('mysql')->table('platform_whatsapp_settings')->where('id', 1)->update([
            'enabled' => $enabled,
            'otp_enabled' => $otp,
            'notifications_enabled' => $notifications,
            'configured_sender_e164' => $sender !== '' ? $sender : null,
            'updated_at' => now(),
        ]);

        $after = $this->settingsRow();
        $this->audit->recordIfAvailable(
            $actor,
            'platform.whatsapp.settings_updated',
            'platform_whatsapp_settings',
            1,
            [
                'enabled' => (bool) $before['enabled'],
                'otp_enabled' => (bool) $before['otp_enabled'],
                'notifications_enabled' => (bool) $before['notifications_enabled'],
            ],
            [
                'enabled' => (bool) $after['enabled'],
                'otp_enabled' => (bool) $after['otp_enabled'],
                'notifications_enabled' => (bool) $after['notifications_enabled'],
            ],
        );

        return $this->snapshot();
    }

    /**
     * @return array<string, mixed>
     */
    public function startLink(?PlatformUser $actor): array
    {
        $result = $this->bridge->startSession();
        $this->audit->recordIfAvailable($actor, 'platform.whatsapp.link_started', 'whatsapp_bridge', null, null, [
            'status' => $result['status'] ?? null,
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function qrPayload(): array
    {
        return $this->bridge->qr();
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnect(?PlatformUser $actor): array
    {
        $result = $this->bridge->logout();
        // Force-disable outbound channels on disconnect
        if (Schema::connection('mysql')->hasTable('platform_whatsapp_settings')) {
            DB::connection('mysql')->table('platform_whatsapp_settings')->where('id', 1)->update([
                'otp_enabled' => false,
                'notifications_enabled' => false,
                'updated_at' => now(),
            ]);
        }
        $this->audit->recordIfAvailable($actor, 'platform.whatsapp.disconnected', 'whatsapp_bridge', null, null, [
            'status' => $result['status'] ?? null,
        ]);

        return $this->snapshot();
    }

    public function sendConnectionTest(?PlatformUser $actor, string $toE164): array
    {
        $settings = $this->settingsRow();
        if (! (bool) $settings['enabled']) {
            throw new RuntimeException('whatsapp_disabled');
        }

        $messageId = 'test-'.bin2hex(random_bytes(8));
        $result = $this->bridge->sendText(
            $toE164,
            'BINAA QATAR connection test. Unofficial bridge. Do not share codes.',
            'connection_test',
            $messageId,
        );

        $this->audit->recordIfAvailable($actor, 'platform.whatsapp.test_sent', 'whatsapp_bridge', null, null, [
            'message_id' => $messageId,
            'destination_masked' => $result['destination_masked'] ?? null,
        ]);

        return ['ok' => true, 'message_id' => $messageId];
    }

    public function canSendRegistrationOtp(): bool
    {
        $settings = $this->settingsRow();
        if (! (bool) $settings['enabled'] || ! (bool) $settings['otp_enabled']) {
            return false;
        }
        try {
            $status = $this->bridge->status();

            return ($status['status'] ?? '') === 'connected';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Best-effort WhatsApp OTP. Caller must still ensure email fallback.
     * Never logs the OTP value.
     */
    public function sendRegistrationOtp(string $toE164, string $code): bool
    {
        if (! $this->canSendRegistrationOtp()) {
            return false;
        }

        try {
            $this->bridge->sendText(
                $toE164,
                'BINAA QATAR verification code: '.$code.' (expires soon).',
                'registration_otp',
                'otp-'.bin2hex(random_bytes(6)),
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{enabled:bool|int,otp_enabled:bool|int,notifications_enabled:bool|int,configured_sender_e164:?string}
     */
    private function settingsRow(): array
    {
        $defaults = [
            'enabled' => (bool) config('whatsapp.enabled', false),
            'otp_enabled' => (bool) config('whatsapp.otp_enabled', false),
            'notifications_enabled' => (bool) config('whatsapp.notifications_enabled', false),
            'configured_sender_e164' => (string) config('whatsapp.configured_sender_e164', ''),
        ];

        if (! Schema::connection('mysql')->hasTable('platform_whatsapp_settings')) {
            return $defaults;
        }

        $row = DB::connection('mysql')->table('platform_whatsapp_settings')->where('id', 1)->first();
        if (! $row) {
            return $defaults;
        }

        return [
            'enabled' => (bool) $row->enabled,
            'otp_enabled' => (bool) $row->otp_enabled,
            'notifications_enabled' => (bool) $row->notifications_enabled,
            'configured_sender_e164' => (string) ($row->configured_sender_e164 ?? ''),
        ];
    }

    private function ensureTable(): void
    {
        if (! Schema::connection('mysql')->hasTable('platform_whatsapp_settings')) {
            throw new RuntimeException('platform_whatsapp_settings_missing');
        }
    }
}
