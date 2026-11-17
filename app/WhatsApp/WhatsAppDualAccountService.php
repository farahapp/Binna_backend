<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Foundation\PlatformUser;
use App\WhatsApp\Contracts\WhatsAppBridgeContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class WhatsAppDualAccountService
{
    public const SLOTS = ['primary', 'secondary'];

    public function __construct(
        private readonly WhatsAppBridgeContract $bridge,
        private readonly WhatsAppOpsAudit $opsAudit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $settings = $this->settings();
        $bridgeHealth = null;
        $bridgeError = null;
        try {
            $bridgeHealth = $this->bridge->health();
        } catch (\Throwable) {
            $bridgeError = 'bridge_unreachable';
        }

        $accounts = [];
        foreach (self::SLOTS as $slot) {
            $row = $this->accountRow($slot);
            $bridgeActive = null;
            $bridgeCandidate = null;
            if ($bridgeError === null) {
                try {
                    $bridgeActive = $this->bridge->accountStatus($slot, 'active');
                    $bridgeCandidate = $this->bridge->accountStatus($slot, 'candidate');
                } catch (\Throwable) {
                    $bridgeError = $bridgeError ?? 'bridge_status_failed';
                }
            }
            $accounts[$slot] = $this->presentAccount($row, $bridgeActive, $bridgeCandidate);
        }

        $security = null;
        if ($bridgeError === null) {
            try {
                $security = $this->bridge->securityStatus();
            } catch (\Throwable) {
                $security = ['encryption_status' => 'storage_locked', 'error' => 'security_status_unavailable'];
            }
        }

        return [
            'caveat' => 'Unofficial Baileys WhatsApp Web integration. Not Meta Business API. May break, require re-linking, or risk account restriction.',
            'bridge' => [
                'health' => $bridgeHealth,
                'error' => $bridgeError,
            ],
            'security' => $security,
            'settings' => $settings,
            'accounts' => $accounts,
            'ops' => $this->recentOps(30),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createEncryptedBackup(?PlatformUser $actor, string $slot, string $role = 'active'): array
    {
        $this->assertSlot($slot);
        try {
            $result = $this->bridge->createEncryptedBackup($slot, $role);
            $this->opsAudit->record($actor, 'platform.whatsapp.backup_created', $slot, null, null, 'ok', [
                'backup_id' => $result['backup_id'] ?? null,
                'role' => $role,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->opsAudit->record($actor, 'platform.whatsapp.backup_failed', $slot, null, null, 'failed', [
                'role' => $role,
            ]);
            throw new RuntimeException('backup_failed');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyStorage(?PlatformUser $actor, string $slot, ?string $backupId = null): array
    {
        $this->assertSlot($slot);
        if ($backupId) {
            $result = $this->bridge->verifyEncryptedBackup($slot, $backupId);
            $this->opsAudit->record($actor, 'platform.whatsapp.storage_verified', $slot, null, null, 'ok', [
                'backup_id' => $backupId,
            ]);

            return $result;
        }
        $status = $this->bridge->securityStatus();
        $this->opsAudit->record($actor, 'platform.whatsapp.storage_verified', $slot, null, null, 'ok', [
            'encryption_status' => $status['encryption_status'] ?? null,
        ]);

        return $status;
    }

    /**
     * Isolated recovery test — does not replace Active.
     *
     * @return array<string, mixed>
     */
    public function recoveryTest(?PlatformUser $actor, string $slot, string $backupId): array
    {
        $this->assertSlot($slot);
        try {
            $result = $this->bridge->recoveryTest($slot, $backupId);
            $this->opsAudit->record($actor, 'platform.whatsapp.recovery_test_ok', $slot, null, null, 'ok', [
                'backup_id' => $backupId,
                'mode' => 'recovery_test',
            ]);

            return $result;
        } catch (\Throwable) {
            $this->opsAudit->record($actor, 'platform.whatsapp.recovery_test_failed', $slot, null, null, 'failed', [
                'backup_id' => $backupId,
            ]);
            throw new RuntimeException('recovery_test_failed');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateSettings(?PlatformUser $actor, array $input): array
    {
        $before = $this->settings();
        $failover = array_key_exists('failover_enabled', $input) ? (bool) $input['failover_enabled'] : (bool) $before['failover_enabled'];
        $otp = array_key_exists('otp_enabled', $input) ? (bool) $input['otp_enabled'] : (bool) $before['otp_enabled'];
        $notifications = array_key_exists('notifications_enabled', $input) ? (bool) $input['notifications_enabled'] : (bool) $before['notifications_enabled'];
        $emailFallback = array_key_exists('email_fallback_enabled', $input) ? (bool) $input['email_fallback_enabled'] : (bool) $before['email_fallback_enabled'];
        $primaryEnabled = array_key_exists('primary_enabled', $input) ? (bool) $input['primary_enabled'] : (bool) $this->accountRow('primary')->enabled;
        $secondaryEnabled = array_key_exists('secondary_enabled', $input) ? (bool) $input['secondary_enabled'] : (bool) $this->accountRow('secondary')->enabled;

        if ($primaryEnabled && ! $this->isIdentityReady('primary')) {
            throw new RuntimeException('primary_not_ready');
        }
        if ($secondaryEnabled && ! $this->isIdentityReady('secondary')) {
            throw new RuntimeException('secondary_not_ready');
        }
        if ($failover && ! $this->isIdentityReady('secondary')) {
            throw new RuntimeException('failover_requires_approved_secondary');
        }
        if (($otp || $notifications) && ! $this->isIdentityReady('primary') && ! $this->isIdentityReady('secondary')) {
            throw new RuntimeException('no_approved_sender');
        }

        DB::connection('mysql')->table('platform_whatsapp_settings')->where('id', 1)->update([
            'enabled' => $primaryEnabled || $secondaryEnabled,
            'otp_enabled' => $otp,
            'notifications_enabled' => $notifications,
            'failover_enabled' => $failover,
            'email_fallback_enabled' => $emailFallback,
            'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', 'primary')->update([
            'enabled' => $primaryEnabled,
            'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', 'secondary')->update([
            'enabled' => $secondaryEnabled,
            'updated_at' => now(),
        ]);

        $this->opsAudit->record($actor, 'platform.whatsapp.settings_updated', null, null, null, 'ok', [
            'before' => $before,
            'after' => $this->settings(),
        ]);

        return $this->dashboard();
    }

    /**
     * @return array<string, mixed>
     */
    public function startLink(?PlatformUser $actor, string $slot, bool $replacement = false): array
    {
        $this->assertSlot($slot);
        $lock = Cache::lock('whatsapp-replace-'.$slot, 120);
        if ($replacement && ! $lock->get()) {
            throw new RuntimeException('replacement_in_progress');
        }

        try {
            if ($replacement) {
                $row = $this->accountRow($slot);
                if ($row->replacement_pending) {
                    throw new RuntimeException('replacement_in_progress');
                }
                // Keep active session; only start candidate
                DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', $slot)->update([
                    'replacement_pending' => true,
                    'lifecycle_state' => 'replacement_pending',
                    'candidate_phone_e164' => null,
                    'updated_at' => now(),
                ]);
                $this->opsAudit->record($actor, 'platform.whatsapp.replacement_started', $slot, $row->active_sender_e164, null, 'started');
            } else {
                $this->opsAudit->record($actor, 'platform.whatsapp.account_link_started', $slot, null, null, 'started');
            }

            // Initial link without replacement uses candidate first, then approve+promote
            $result = $this->bridge->startSession($slot, 'candidate');
            DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', $slot)->update([
                'lifecycle_state' => $replacement ? 'replacement_pending' : 'qr_pending',
                'updated_at' => now(),
            ]);

            return ['bridge' => $result, 'dashboard' => $this->dashboard()];
        } finally {
            if ($replacement) {
                // lock held until approve/cancel — release only on cancel/fail paths elsewhere
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function qr(string $slot): ?array
    {
        $this->assertSlot($slot);

        return $this->bridge->qr($slot, 'candidate');
    }

    public function refreshQr(?PlatformUser $actor, string $slot): array
    {
        $this->assertSlot($slot);
        $result = $this->bridge->startSession($slot, 'candidate');
        $this->opsAudit->record($actor, 'platform.whatsapp.account_link_started', $slot, null, null, 'qr_refreshed');

        return ['bridge' => $result, 'dashboard' => $this->dashboard()];
    }

    public function cancelCandidate(?PlatformUser $actor, string $slot): array
    {
        $this->assertSlot($slot);
        $row = $this->accountRow($slot);
        $this->bridge->logout($slot, 'candidate');
        DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', $slot)->update([
            'replacement_pending' => false,
            'candidate_phone_e164' => null,
            'lifecycle_state' => $row->active_sender_e164 ? 'active' : 'disconnected',
            'updated_at' => now(),
        ]);
        Cache::lock('whatsapp-replace-'.$slot, 120)->forceRelease();
        $this->opsAudit->record($actor, 'platform.whatsapp.replacement_failed', $slot, $row->active_sender_e164, null, 'cancelled');

        return $this->dashboard();
    }

    /**
     * Sync candidate identity from bridge after connect; verify against optional expected phone.
     */
    public function syncCandidateIdentity(?PlatformUser $actor, string $slot, ?string $expectedPhone = null): array
    {
        $this->assertSlot($slot);
        $status = $this->bridge->accountStatus($slot, 'candidate');
        $linked = $status['phone_e164'] ?? null;
        if (($status['status'] ?? '') !== 'connected' || ! is_string($linked) || $linked === '') {
            throw new RuntimeException('candidate_not_connected');
        }
        if (! preg_match('/^\+[1-9]\d{7,14}$/', $linked)) {
            throw new RuntimeException('identity_invalid');
        }
        if ($expectedPhone !== null && $expectedPhone !== '' && $expectedPhone !== $linked) {
            $this->opsAudit->record($actor, 'platform.whatsapp.identity_verified', $slot, $expectedPhone, $linked, 'mismatch');
            throw new RuntimeException('identity_mismatch');
        }

        DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', $slot)->update([
            'candidate_phone_e164' => $linked,
            'linked_phone_e164' => $linked,
            'verified_phone_e164' => $linked,
            'identity_verified_at' => now(),
            'lifecycle_state' => 'awaiting_approval',
            'updated_at' => now(),
        ]);
        $this->opsAudit->record($actor, 'platform.whatsapp.identity_verified', $slot, $expectedPhone, $linked, 'ok');
        $this->opsAudit->record($actor, 'platform.whatsapp.account_connected', $slot, null, $linked, 'ok');

        return $this->dashboard();
    }

    public function approve(?PlatformUser $actor, string $slot): array
    {
        $this->assertSlot($slot);
        $row = $this->accountRow($slot);
        if (! $row->verified_phone_e164 || $row->lifecycle_state !== 'awaiting_approval') {
            throw new RuntimeException('not_awaiting_approval');
        }
        $previous = $row->active_sender_e164;
        $this->bridge->backup($slot);
        $promoted = $this->bridge->promote($slot);
        $newPhone = $promoted['active']['phone_e164'] ?? $row->verified_phone_e164;

        DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', $slot)->update([
            'approved_sender_e164' => $newPhone,
            'active_sender_e164' => $newPhone,
            'linked_phone_e164' => $newPhone,
            'verified_phone_e164' => $newPhone,
            'candidate_phone_e164' => null,
            'approved_at' => now(),
            'approved_by' => $actor?->getKey(),
            'lifecycle_state' => 'active',
            'replacement_pending' => false,
            'has_session_backup' => true,
            'enabled' => false, // require explicit enable after approve
            'last_connected_at' => now(),
            'updated_at' => now(),
        ]);

        $event = $previous ? 'platform.whatsapp.replacement_completed' : 'platform.whatsapp.account_approved';
        $this->opsAudit->record($actor, $event, $slot, $previous, $newPhone, 'ok');
        $this->opsAudit->record($actor, 'platform.whatsapp.account_activated', $slot, $previous, $newPhone, 'pending_enable');
        Cache::lock('whatsapp-replace-'.$slot, 120)->forceRelease();

        return $this->dashboard();
    }

    public function rollback(?PlatformUser $actor, string $slot): array
    {
        $this->assertSlot($slot);
        $row = $this->accountRow($slot);
        $this->opsAudit->record($actor, 'platform.whatsapp.rollback_started', $slot, $row->active_sender_e164, null, 'started');
        try {
            $restored = $this->bridge->restore($slot);
        } catch (\Throwable $e) {
            $this->opsAudit->record($actor, 'platform.whatsapp.replacement_failed', $slot, $row->active_sender_e164, null, 'rollback_failed');
            throw new RuntimeException('rollback_failed');
        }
        $phone = $restored['phone_e164'] ?? $row->approved_sender_e164;
        DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', $slot)->update([
            'active_sender_e164' => $phone,
            'approved_sender_e164' => $phone,
            'linked_phone_e164' => $phone,
            'verified_phone_e164' => $phone,
            'candidate_phone_e164' => null,
            'replacement_pending' => false,
            'lifecycle_state' => $phone ? 'active' : 'disconnected',
            'updated_at' => now(),
        ]);
        $this->bridge->logout($slot, 'candidate');
        Cache::lock('whatsapp-replace-'.$slot, 120)->forceRelease();
        $this->opsAudit->record($actor, 'platform.whatsapp.rollback_completed', $slot, $row->active_sender_e164, $phone, 'ok');

        return $this->dashboard();
    }

    public function disconnect(?PlatformUser $actor, string $slot): array
    {
        $this->assertSlot($slot);
        $row = $this->accountRow($slot);
        $this->bridge->logout($slot, 'active');
        $this->bridge->logout($slot, 'candidate');
        DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', $slot)->update([
            'enabled' => false,
            'lifecycle_state' => 'disconnected',
            'active_sender_e164' => null,
            'approved_sender_e164' => null,
            'linked_phone_e164' => null,
            'verified_phone_e164' => null,
            'candidate_phone_e164' => null,
            'replacement_pending' => false,
            'last_disconnected_at' => now(),
            'updated_at' => now(),
        ]);
        $this->opsAudit->record($actor, 'platform.whatsapp.disconnected', $slot, $row->active_sender_e164, null, 'ok');

        return $this->dashboard();
    }

    /**
     * Connection test — does NOT send a WhatsApp message.
     *
     * @return array<string, mixed>
     */
    public function testConnection(?PlatformUser $actor, ?string $slot = null): array
    {
        $slots = $slot ? [$slot] : self::SLOTS;
        $results = [];
        foreach ($slots as $s) {
            $this->assertSlot($s);
            $row = $this->accountRow($s);
            $bridgeActive = null;
            $error = null;
            try {
                $bridgeActive = $this->bridge->accountStatus($s, 'active');
            } catch (\Throwable) {
                $error = 'bridge_status_failed';
            }
            $connected = ($bridgeActive['status'] ?? '') === 'connected';
            $identity = $bridgeActive['phone_e164'] ?? null;
            $result = [
                'slot' => $s,
                'bridge_reachable' => $error === null,
                'socket_status' => $bridgeActive['status'] ?? 'unknown',
                'authenticated' => $connected,
                'linked_phone_e164' => $identity,
                'verified' => (bool) $row->verified_phone_e164 && $row->verified_phone_e164 === $identity,
                'approved' => (bool) $row->approved_sender_e164 && $row->approved_sender_e164 === $identity,
                'enabled' => (bool) $row->enabled,
                'send_ready' => $this->isSendReady($s),
                'last_connected_at' => $row->last_connected_at,
            ];
            $verdict = $result['send_ready'] ? 'ready' : ($connected ? 'connected_not_approved' : 'not_connected');
            DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', $s)->update([
                'last_tested_at' => now(),
                'last_test_result' => $verdict,
                'updated_at' => now(),
            ]);
            $this->opsAudit->record($actor, 'platform.whatsapp.connection_tested', $s, null, $identity, $verdict, [
                'bridge_reachable' => $result['bridge_reachable'],
                'send_ready' => $result['send_ready'],
            ]);
            $results[$s] = $result + ['verdict' => $verdict];
        }

        return ['results' => $results, 'dashboard' => $this->dashboard()];
    }

    public function isIdentityReady(string $slot): bool
    {
        $row = $this->accountRow($slot);
        if (! $row->approved_sender_e164 || ! $row->verified_phone_e164) {
            return false;
        }
        if ($row->lifecycle_state !== 'active') {
            return false;
        }
        try {
            $status = $this->bridge->accountStatus($slot, 'active');
        } catch (\Throwable) {
            return false;
        }
        if (($status['status'] ?? '') !== 'connected') {
            return false;
        }
        $linked = $status['phone_e164'] ?? null;

        return is_string($linked) && $linked === $row->approved_sender_e164;
    }

    public function isSendReady(string $slot): bool
    {
        $row = $this->accountRow($slot);

        return (bool) $row->enabled && $this->isIdentityReady($slot);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentOps(int $limit = 30): array
    {
        if (! Schema::connection('mysql')->hasTable('platform_whatsapp_ops_audit')) {
            return [];
        }

        return DB::connection('mysql')->table('platform_whatsapp_ops_audit')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'actor_id' => $r->actor_id,
                'event' => $r->event,
                'slot' => $r->slot,
                'previous_phone_e164' => $r->previous_phone_e164,
                'new_phone_e164' => $r->new_phone_e164,
                'result' => $r->result,
                'created_at' => $r->created_at,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $defaults = [
            'otp_enabled' => false,
            'notifications_enabled' => false,
            'failover_enabled' => false,
            'email_fallback_enabled' => true,
            'primary_priority' => 1,
            'secondary_priority' => 2,
        ];
        if (! Schema::connection('mysql')->hasTable('platform_whatsapp_settings')) {
            return $defaults;
        }
        $row = DB::connection('mysql')->table('platform_whatsapp_settings')->where('id', 1)->first();
        if (! $row) {
            return $defaults;
        }

        return [
            'otp_enabled' => (bool) ($row->otp_enabled ?? false),
            'notifications_enabled' => (bool) ($row->notifications_enabled ?? false),
            'failover_enabled' => (bool) ($row->failover_enabled ?? false),
            'email_fallback_enabled' => (bool) ($row->email_fallback_enabled ?? true),
            'primary_priority' => (int) ($row->primary_priority ?? 1),
            'secondary_priority' => (int) ($row->secondary_priority ?? 2),
            'primary_enabled' => (bool) $this->accountRow('primary')->enabled,
            'secondary_enabled' => (bool) $this->accountRow('secondary')->enabled,
        ];
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
     * @param  array<string, mixed>|null  $bridgeActive
     * @param  array<string, mixed>|null  $bridgeCandidate
     * @return array<string, mixed>
     */
    private function presentAccount(object $row, ?array $bridgeActive, ?array $bridgeCandidate): array
    {
        return [
            'slot' => $row->slot,
            'lifecycle_state' => $row->lifecycle_state,
            'enabled' => (bool) $row->enabled,
            'candidate_phone_e164' => $row->candidate_phone_e164,
            'linked_phone_e164' => $bridgeActive['phone_e164'] ?? $row->linked_phone_e164,
            'verified_phone_e164' => $row->verified_phone_e164,
            'approved_sender_e164' => $row->approved_sender_e164,
            'active_sender_e164' => $row->active_sender_e164,
            'identity_verified_at' => $row->identity_verified_at,
            'approved_at' => $row->approved_at,
            'last_connected_at' => $row->last_connected_at,
            'last_tested_at' => $row->last_tested_at,
            'last_test_result' => $row->last_test_result,
            'replacement_pending' => (bool) $row->replacement_pending,
            'has_session_backup' => (bool) $row->has_session_backup,
            'send_ready' => $this->isSendReady($row->slot),
            'bridge_active' => $bridgeActive,
            'bridge_candidate' => $bridgeCandidate,
        ];
    }

    private function assertSlot(string $slot): void
    {
        if (! in_array($slot, self::SLOTS, true)) {
            throw new RuntimeException('invalid_slot');
        }
    }
}
