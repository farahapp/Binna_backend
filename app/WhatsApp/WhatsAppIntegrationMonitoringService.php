<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\WhatsApp\Contracts\WhatsAppBridgeContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds the Platform Admin monitoring view from authoritative bridge status.
 * Health/HMAC success is never treated proof of a linked WhatsApp session.
 */
class WhatsAppIntegrationMonitoringService
{
    public const CONNECTED_STATES = ['connected'];

    public function __construct(
        private readonly WhatsAppBridgeContract $bridge,
        private readonly WhatsAppOpsAudit $opsAudit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $health = null;
        $bridgeError = null;
        try {
            $health = $this->bridge->health();
        } catch (\Throwable) {
            $bridgeError = 'bridge_offline';
        }

        $bridgeStatus = $bridgeError ?? ($health['ok'] ?? false ? 'online' : 'bridge_offline');
        $accounts = [];

        foreach (WhatsAppDualAccountService::SLOTS as $slot) {
            $row = $this->accountRow($slot);
            $active = null;
            $candidate = null;
            if ($bridgeError === null) {
                try {
                    $active = $this->bridge->accountStatus($slot, 'active');
                    $candidate = $this->bridge->accountStatus($slot, 'candidate');
                } catch (\Throwable) {
                    $bridgeError = 'network_error';
                    $bridgeStatus = 'network_error';
                }
            }
            $accounts[$slot] = $this->presentAccount($row, $active, $candidate, $bridgeStatus);
        }

        if ($bridgeError === null) {
            Cache::put('whatsapp-monitor-last-ok', now()->toIso8601String(), now()->addMinutes(10));
        }

        $states = array_column($accounts, 'connection_state');
        $summary = [
            'total_accounts' => count($accounts),
            'connected_accounts' => count(array_filter($states, fn ($s) => in_array($s, self::CONNECTED_STATES, true))),
            'disconnected_accounts' => count(array_filter($states, fn ($s) => in_array($s, [
                'disconnected', 'session_invalid', 'session_expired', 'bridge_offline', 'network_error',
            ], true))),
            'qr_required_accounts' => count(array_filter($states, fn ($s) => in_array($s, ['qr_required', 'qr_pending'], true))),
            'bridge_status' => $bridgeStatus,
            'last_successful_check_at' => Cache::get('whatsapp-monitor-last-ok'),
            'last_updated_at' => now()->toIso8601String(),
        ];

        return [
            'caveat' => 'Unofficial Baileys WhatsApp Web integration. Not Meta Business API. '
                .'Connected is shown only when the bridge reports a fresh authenticated socket.',
            'summary' => $summary,
            'bridge' => [
                'status' => $bridgeStatus,
                'error' => $bridgeError,
                'instance_id' => $health['bridge_instance_id'] ?? null,
                'checked_at' => now()->toIso8601String(),
                'publicly_exposed' => false,
            ],
            'accounts' => array_values($accounts),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function account(string $slot): array
    {
        $this->assertSlot($slot);
        $overview = $this->overview();
        foreach ($overview['accounts'] as $account) {
            if ($account['slot'] === $slot) {
                return $account;
            }
        }
        throw new RuntimeException('account_not_found');
    }

    /**
     * @return array<string, mixed>
     */
    public function requestConnect(string $slot, ?\App\Foundation\PlatformUser $actor, bool $replacement = false): array
    {
        $this->assertSlot($slot);
        $row = $this->accountRow($slot);
        $this->opsAudit->record(
            $actor,
            $replacement ? 'platform.whatsapp.number_change_requested' : 'platform.whatsapp.connect_requested',
            $slot,
            $row->active_sender_e164,
            null,
            'started',
        );

        $bridge = $this->bridge->startSession($slot, 'candidate');
        DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', $slot)->update([
            'lifecycle_state' => $replacement ? 'replacement_pending' : 'qr_pending',
            'replacement_pending' => $replacement,
            'candidate_phone_e164' => null,
            'updated_at' => now(),
        ]);
        $this->opsAudit->record($actor, 'platform.whatsapp.pairing_started', $slot, null, null, 'ok', [
            'replacement' => $replacement,
        ]);

        return ['bridge' => $bridge, 'account' => $this->account($slot)];
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshQr(string $slot, ?\App\Foundation\PlatformUser $actor): array
    {
        $this->assertSlot($slot);
        $bridge = $this->bridge->startSession($slot, 'candidate');
        DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', $slot)->update([
            'lifecycle_state' => 'qr_pending',
            'updated_at' => now(),
        ]);
        $this->opsAudit->record($actor, 'platform.whatsapp.qr_refreshed', $slot, null, null, 'ok');

        return ['bridge' => $bridge, 'account' => $this->account($slot)];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelPairing(string $slot, ?\App\Foundation\PlatformUser $actor): array
    {
        $this->assertSlot($slot);
        $row = $this->accountRow($slot);
        $this->bridge->cancelPairing($slot, 'candidate');
        DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', $slot)->update([
            'replacement_pending' => false,
            'candidate_phone_e164' => null,
            'lifecycle_state' => $row->active_sender_e164 ? 'active' : 'disconnected',
            'updated_at' => now(),
        ]);
        $this->opsAudit->record($actor, 'platform.whatsapp.pairing_failed', $slot, null, null, 'cancelled');

        return ['account' => $this->account($slot)];
    }

    /**
     * @return array<string, mixed>
     */
    public function reconnect(string $slot, ?\App\Foundation\PlatformUser $actor): array
    {
        $this->assertSlot($slot);
        $this->opsAudit->record($actor, 'platform.whatsapp.reconnect_started', $slot, null, null, 'started');
        try {
            $bridge = $this->bridge->reconnect($slot, 'active');
        } catch (\Throwable) {
            $this->opsAudit->record($actor, 'platform.whatsapp.reconnect_failed', $slot, null, null, 'failed');
            throw new RuntimeException('reconnect_failed');
        }
        $this->opsAudit->record($actor, 'platform.whatsapp.reconnect_succeeded', $slot, null, null, 'ok');

        return ['bridge' => $bridge, 'account' => $this->account($slot)];
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnect(string $slot, ?\App\Foundation\PlatformUser $actor): array
    {
        $this->assertSlot($slot);
        $row = $this->accountRow($slot);
        $this->bridge->disconnect($slot, 'active');
        $this->bridge->cancelPairing($slot, 'candidate');
        DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', $slot)->update([
            'enabled' => false,
            'lifecycle_state' => 'disconnected',
            'replacement_pending' => false,
            'candidate_phone_e164' => null,
            'last_disconnected_at' => now(),
            'updated_at' => now(),
        ]);
        $this->opsAudit->record(
            $actor,
            'platform.whatsapp.account_disconnected_by_admin',
            $slot,
            $row->active_sender_e164,
            null,
            'ok',
        );

        return ['account' => $this->account($slot)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(string $slot, int $limit = 50): array
    {
        $this->assertSlot($slot);
        $all = (new WhatsAppDualAccountService($this->bridge, $this->opsAudit))->recentOps($limit * 3);

        return array_values(array_slice(
            array_filter($all, fn (array $event) => $event['slot'] === $slot),
            0,
            $limit,
        ));
    }

    private function accountRow(string $slot): object
    {
        $row = DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', $slot)->first();
        if (! $row) {
            throw new RuntimeException('account_row_missing');
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>|null  $active
     * @param  array<string, mixed>|null  $candidate
     * @return array<string, mixed>
     */
    private function presentAccount(object $row, ?array $active, ?array $candidate, string $bridgeStatus): array
    {
        $state = $this->deriveState($active, $bridgeStatus);
        $heartbeatAge = $active['heartbeat_age_ms'] ?? null;
        $disconnectedAt = $active['last_disconnected_at'] ?? $row->last_disconnected_at ?? null;
        $alerts = [];
        if (in_array($state, ['disconnected', 'session_invalid', 'session_expired'], true)) {
            $alerts[] = 'account_disconnected';
        }
        if (in_array($state, ['stale', 'unknown'], true)) {
            $alerts[] = 'status_unconfirmed';
        }
        if (in_array($state, ['qr_required', 'qr_pending'], true)) {
            $alerts[] = 'qr_required';
        }
        if ($bridgeStatus !== 'online') {
            $alerts[] = 'bridge_unavailable';
        }

        return [
            'slot' => $row->slot,
            'account_id' => 'binaa-wa-'.$row->slot,
            'label' => $row->slot === 'primary' ? 'WhatsApp Account 1 — primary' : 'WhatsApp Account 2 — secondary',
            'purpose' => $row->slot === 'primary' ? 'Primary transactional sender' : 'Secondary / failover sender',
            'phone_masked' => $this->maskPhone($row->active_sender_e164 ?? $active['phone_e164'] ?? null),
            'connection_state' => $state,
            'bridge_session_status' => $active['status'] ?? 'unknown',
            'session_state' => $this->deriveSessionState($active, $bridgeStatus),
            'recovery_state' => $this->deriveRecoveryState($active, $bridgeStatus),
            'last_connected_at' => $active['last_connected_at'] ?? $row->last_connected_at ?? null,
            'last_disconnected_at' => $disconnectedAt,
            'last_status_check_at' => now()->toIso8601String(),
            'last_heartbeat_at' => $active['last_seen_at'] ?? null,
            'heartbeat_age_seconds' => is_numeric($heartbeatAge) ? round((int) $heartbeatAge / 1000, 1) : null,
            'disconnect_duration_seconds' => $state === 'connected' || ! $disconnectedAt
                ? null
                : max(0, now()->diffInSeconds(\Illuminate\Support\Carbon::parse($disconnectedAt))),
            'disconnect_reason' => $active['disconnect_reason'] ?? $active['last_error'] ?? null,
            'reconnect_attempt' => (int) ($active['reconnect_attempt'] ?? 0),
            'has_qr' => (bool) ($candidate['has_qr'] ?? false),
            'qr_expires_at' => $candidate['qr_expires_at'] ?? null,
            'send_ready' => (bool) $row->enabled && $state === 'connected'
                && ($row->approved_sender_e164 ?? null) === ($active['phone_e164'] ?? null),
            'alerts' => array_values(array_unique($alerts)),
            'actions' => $this->availableActions($state),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $active
     */
    private function deriveState(?array $active, string $bridgeStatus): string
    {
        if ($bridgeStatus === 'bridge_offline') {
            return 'bridge_offline';
        }
        if ($bridgeStatus === 'network_error') {
            return 'network_error';
        }
        if ($active === null) {
            return 'unknown';
        }

        $state = (string) ($active['connection_state'] ?? $active['status'] ?? 'unknown');
        $allowed = [
            'connected', 'connecting', 'qr_required', 'qr_pending', 'disconnected', 'reconnecting',
            'session_invalid', 'session_expired', 'stale', 'unknown',
        ];
        if (! in_array($state, $allowed, true)) {
            $state = 'unknown';
        }
        if (! empty($active['stale']) && $state === 'connected') {
            return 'stale';
        }
        if ($state === 'connected' && is_numeric($active['heartbeat_age_ms'] ?? null)
            && (int) $active['heartbeat_age_ms'] > 45000) {
            return 'stale';
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>|null  $active
     */
    private function deriveSessionState(?array $active, string $bridgeStatus): string
    {
        if ($bridgeStatus !== 'online') {
            return 'unverified';
        }
        if ($active === null) {
            return 'unknown';
        }
        $state = (string) ($active['connection_state'] ?? $active['status'] ?? 'unknown');

        return match ($state) {
            'connected' => 'authenticated',
            'reconnecting', 'connecting' => 'restoring',
            'session_invalid', 'session_expired' => 'invalid',
            default => 'not_authenticated',
        };
    }

    /**
     * @param  array<string, mixed>|null  $active
     */
    private function deriveRecoveryState(?array $active, string $bridgeStatus): string
    {
        if ($bridgeStatus !== 'online') {
            return 'unavailable';
        }
        $state = (string) ($active['connection_state'] ?? $active['status'] ?? 'unknown');

        return match ($state) {
            'reconnecting' => 'in_progress',
            'reconnect_exhausted' => 'failed',
            default => 'idle',
        };
    }

    /**
     * @return array<string, bool>
     */
    private function availableActions(string $state): array
    {
        return [
            'refresh_status' => true,
            'connect' => ! in_array($state, ['connected', 'connecting', 'qr_pending', 'reconnecting'], true),
            'generate_qr' => in_array($state, ['qr_required', 'disconnected', 'session_invalid', 'session_expired'], true),
            'refresh_qr' => $state === 'qr_pending',
            'reconnect' => in_array($state, ['disconnected', 'stale', 'network_error', 'session_invalid', 'session_expired'], true),
            'cancel_pairing' => in_array($state, ['qr_pending', 'connecting'], true),
            'disconnect' => in_array($state, ['connected', 'connecting', 'reconnecting', 'qr_pending', 'stale'], true),
            'change_number' => true,
        ];
    }

    private function maskPhone(mixed $phone): ?string
    {
        if (! is_string($phone) || $phone === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (strlen($digits) < 6) {
            return '***';
        }

        return '+'.substr($digits, 0, 3).'***'.substr($digits, -3);
    }

    private function assertSlot(string $slot): void
    {
        if (! in_array($slot, WhatsAppDualAccountService::SLOTS, true)) {
            throw new RuntimeException('invalid_account_id');
        }
    }
}
