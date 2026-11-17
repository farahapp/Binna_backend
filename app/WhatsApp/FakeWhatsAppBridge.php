<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\WhatsApp\Contracts\WhatsAppBridgeContract;
use RuntimeException;

/**
 * In-process dual-account bridge for PHPUnit — no network, no Baileys, no real QR.
 */
class FakeWhatsAppBridge implements WhatsAppBridgeContract
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $accounts = [];

    /** @var array<string, bool> */
    private array $backups = ['primary' => false, 'secondary' => false];

    /** @var array<string, array{ok:bool,code?:string}> */
    private array $sendBehavior = [];

    private bool $healthy = true;

    public function __construct()
    {
        foreach (['primary', 'secondary'] as $slot) {
            $this->accounts[$slot] = [
                'active' => $this->blank($slot, 'active'),
                'candidate' => $this->blank($slot, 'candidate'),
            ];
        }
    }

    public function setHealthy(bool $healthy): void
    {
        $this->healthy = $healthy;
    }

    public function setSendBehavior(string $slot, bool $ok, string $code = 'send_failed'): void
    {
        $this->sendBehavior[$slot] = ['ok' => $ok, 'code' => $code];
    }

    public function health(): array
    {
        if (! $this->healthy) {
            throw new RuntimeException('whatsapp_bridge_unhealthy');
        }

        return [
            'ok' => true,
            'service' => 'fake-whatsapp-bridge',
            'mock_mode' => true,
            'accounts' => [
                'primary' => [
                    'active' => $this->accounts['primary']['active'],
                    'candidate' => $this->accounts['primary']['candidate'],
                    'has_backup' => $this->backups['primary'],
                ],
                'secondary' => [
                    'active' => $this->accounts['secondary']['active'],
                    'candidate' => $this->accounts['secondary']['candidate'],
                    'has_backup' => $this->backups['secondary'],
                ],
            ],
        ];
    }

    public function accountStatus(string $slot, string $role = 'active'): array
    {
        return $this->accounts[$slot][$role];
    }

    public function startSession(string $slot, string $role = 'candidate', ?string $mockPhone = null): array
    {
        $row = &$this->accounts[$slot][$role];
        $row['status'] = 'qr_pending';
        $row['has_qr'] = true;
        $row['qr_expires_at'] = now()->addSeconds(90)->toIso8601String();
        $row['phone_e164'] = null;
        if ($mockPhone) {
            return $this->mockComplete($slot, $role, $mockPhone);
        }

        return $row;
    }

    public function qr(string $slot, string $role = 'candidate'): ?array
    {
        $row = $this->accounts[$slot][$role];
        if (! ($row['has_qr'] ?? false)) {
            return null;
        }

        return [
            'slot' => $slot,
            'role' => $role,
            'qr_data_url' => 'data:image/png;base64,FAKE',
            'expires_at' => $row['qr_expires_at'],
        ];
    }

    public function logout(string $slot, string $role): array
    {
        $this->accounts[$slot][$role] = $this->blank($slot, $role);

        return $this->accounts[$slot][$role];
    }

    public function backup(string $slot): array
    {
        $this->backups[$slot] = true;

        return ['ok' => true, 'slot' => $slot, 'has_backup' => true];
    }

    public function restore(string $slot): array
    {
        if (! $this->backups[$slot]) {
            throw new RuntimeException('backup_missing');
        }
        // Simulate restore of previous approved identity stored in meta
        $phone = $this->accounts[$slot]['active']['_backup_phone'] ?? $this->accounts[$slot]['active']['phone_e164'];
        $this->accounts[$slot]['active'] = $this->blank($slot, 'active');
        if ($phone) {
            $this->accounts[$slot]['active']['status'] = 'connected';
            $this->accounts[$slot]['active']['phone_e164'] = $phone;
            $this->accounts[$slot]['active']['last_connected_at'] = now()->toIso8601String();
        }

        return $this->accounts[$slot]['active'];
    }

    public function promote(string $slot): array
    {
        $cand = $this->accounts[$slot]['candidate'];
        if (($cand['status'] ?? '') !== 'connected' || empty($cand['phone_e164'])) {
            throw new RuntimeException('candidate_not_ready');
        }
        $this->accounts[$slot]['active']['_backup_phone'] = $this->accounts[$slot]['active']['phone_e164'] ?? null;
        $this->backups[$slot] = true;
        $this->accounts[$slot]['active'] = array_merge($this->blank($slot, 'active'), [
            'status' => 'connected',
            'phone_e164' => $cand['phone_e164'],
            'last_connected_at' => now()->toIso8601String(),
            '_backup_phone' => $this->accounts[$slot]['active']['_backup_phone'] ?? null,
        ]);
        $this->accounts[$slot]['candidate'] = $this->blank($slot, 'candidate');

        return [
            'active' => $this->accounts[$slot]['active'],
            'candidate' => $this->accounts[$slot]['candidate'],
            'has_backup' => true,
        ];
    }

    public function send(string $slot, string $toE164, string $text, string $template, ?string $messageId = null): array
    {
        $active = $this->accounts[$slot]['active'];
        if (($active['status'] ?? '') !== 'connected') {
            throw new RuntimeException('not_connected');
        }
        $behavior = $this->sendBehavior[$slot] ?? ['ok' => true];
        if (! $behavior['ok']) {
            throw new RuntimeException($behavior['code'] ?? 'send_failed');
        }
        $digits = preg_replace('/\D+/', '', $toE164) ?: '';

        return [
            'ok' => true,
            'message_id' => $messageId ?: 'fake-'.bin2hex(random_bytes(4)),
            'destination_masked' => '+'.substr($digits, 0, 3).'***'.substr($digits, -2),
            'slot' => $slot,
        ];
    }

    public function mockComplete(string $slot, string $role, string $phoneE164): array
    {
        if (! preg_match('/^\+[1-9]\d{7,14}$/', $phoneE164)) {
            $this->accounts[$slot][$role]['status'] = 'error';
            $this->accounts[$slot][$role]['last_error'] = 'invalid_identity';

            return $this->accounts[$slot][$role];
        }
        $this->accounts[$slot][$role] = array_merge($this->blank($slot, $role), [
            'status' => 'connected',
            'phone_e164' => $phoneE164,
            'has_qr' => false,
            'last_connected_at' => now()->toIso8601String(),
        ]);

        return $this->accounts[$slot][$role];
    }

    public function securityStatus(): array
    {
        return [
            'encryption_status' => 'storage_ready',
            'key_version' => '1',
            'key_fingerprint' => 'fake-fingerprint',
            'key_source' => 'fake',
            'accounts' => [
                'primary' => [
                    'active' => ['status' => 'empty'],
                    'candidate' => ['status' => 'empty'],
                    'backup' => ['status' => 'empty'],
                    'last_backup' => null,
                    'backup_count' => 0,
                ],
                'secondary' => [
                    'active' => ['status' => 'empty'],
                    'candidate' => ['status' => 'empty'],
                    'backup' => ['status' => 'empty'],
                    'last_backup' => null,
                    'backup_count' => 0,
                ],
            ],
            'caveats' => ['Fake bridge — no real encryption material.'],
        ];
    }

    public function createEncryptedBackup(string $slot, string $role = 'active'): array
    {
        return ['ok' => true, 'backup_id' => 'fake-bk-'.$slot, 'slot' => $slot, 'role' => $role, 'file_count' => 0];
    }

    public function verifyEncryptedBackup(string $slot, string $backupId): array
    {
        return ['ok' => true, 'backup_id' => $backupId, 'slot' => $slot, 'role' => 'active', 'file_count' => 0];
    }

    public function recoveryTest(string $slot, string $backupId): array
    {
        return ['ok' => true, 'mode' => 'recovery_test', 'slot' => $slot, 'backup_id' => $backupId, 'restored_files' => 0];
    }

    public function listEncryptedBackups(string $slot): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function blank(string $slot, string $role): array
    {
        return [
            'slot' => $slot,
            'role' => $role,
            'status' => 'disconnected',
            'phone_e164' => null,
            'last_connected_at' => null,
            'last_disconnected_at' => null,
            'last_error' => null,
            'has_qr' => false,
            'qr_expires_at' => null,
            'baileys_loaded' => false,
            'mock' => true,
        ];
    }
}
