<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Foundation\PlatformUser;
use App\WhatsApp\Contracts\WhatsAppBridgeContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class WhatsAppDispatchService
{
    public function __construct(
        private readonly WhatsAppBridgeContract $bridge,
        private readonly WhatsAppDualAccountService $accounts,
        private readonly WhatsAppOpsAudit $opsAudit,
    ) {}

    /**
     * Send transactional WhatsApp with Primary → Secondary failover.
     * Does not send email; caller handles email fallback.
     *
     * @return array{ok:bool,slot:?string,result:string,failover_reason:?string,message_id:?string,dispatch_id:string}
     */
    public function sendWhatsApp(
        string $toE164,
        string $text,
        string $template,
        string $purpose,
        ?string $idempotencyKey = null,
        ?PlatformUser $actor = null,
    ): array {
        $dispatchId = (string) Str::uuid();
        $settings = $this->accounts->settings();

        if ($idempotencyKey) {
            $cacheKey = 'wa-dispatch:'.hash('sha256', $idempotencyKey);
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }
        }

        $order = $this->slotOrder($settings);
        $attempted = [];
        $failoverReason = null;
        $lastError = null;

        foreach ($order as $slot) {
            if (! $this->accounts->isSendReady($slot)) {
                $attempted[] = $slot.':not_ready';
                if ($failoverReason === null) {
                    $failoverReason = $slot.'_not_ready';
                }
                continue;
            }

            try {
                $result = $this->bridge->send($slot, $toE164, $text, $template, $dispatchId);
                $payload = [
                    'ok' => true,
                    'slot' => $slot,
                    'result' => 'sent',
                    'failover_reason' => count($attempted) ? ($failoverReason ?: 'primary_unavailable') : null,
                    'message_id' => $result['message_id'] ?? null,
                    'dispatch_id' => $dispatchId,
                ];
                $this->logDispatch($dispatchId, $purpose, $template, $toE164, $slot, $attempted, $payload['failover_reason'], 'sent', 'whatsapp');
                if ($payload['failover_reason']) {
                    $this->opsAudit->record($actor, 'platform.whatsapp.failover_started', $slot, null, null, 'ok', [
                        'reason' => $payload['failover_reason'],
                        'dispatch_id' => $dispatchId,
                    ]);
                    $this->opsAudit->record($actor, 'platform.whatsapp.secondary_send_completed', $slot, null, null, 'ok', [
                        'dispatch_id' => $dispatchId,
                    ]);
                }
                DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', $slot)->update([
                    'last_successful_send_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($idempotencyKey) {
                    Cache::put($cacheKey, $payload, now()->addMinutes(30));
                }

                return $payload;
            } catch (\Throwable $e) {
                $code = $e->getMessage();
                $attempted[] = $slot.':'.$code;
                $lastError = $code;
                DB::connection('mysql')->table('platform_whatsapp_accounts')->where('slot', $slot)->update([
                    'last_failed_send_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($slot === 'primary') {
                    $this->opsAudit->record($actor, 'platform.whatsapp.primary_send_failed', 'primary', null, null, 'failed', [
                        'dispatch_id' => $dispatchId,
                        'error' => $code,
                    ]);
                    $failoverReason = 'primary_send_failed';
                }
                // Ambiguous timeout: do not blindly continue without recording unknown
                if (str_contains($code, 'timeout') || str_contains($code, 'unknown')) {
                    $payload = [
                        'ok' => false,
                        'slot' => $slot,
                        'result' => 'unknown',
                        'failover_reason' => $failoverReason,
                        'message_id' => null,
                        'dispatch_id' => $dispatchId,
                    ];
                    $this->logDispatch($dispatchId, $purpose, $template, $toE164, $slot, $attempted, $failoverReason, 'unknown', 'whatsapp');
                    if ($idempotencyKey) {
                        Cache::put($cacheKey, $payload, now()->addMinutes(30));
                    }

                    return $payload;
                }
                if (! ($settings['failover_enabled'] ?? false)) {
                    break;
                }
            }
        }

        $payload = [
            'ok' => false,
            'slot' => null,
            'result' => 'failed',
            'failover_reason' => $failoverReason ?: $lastError,
            'message_id' => null,
            'dispatch_id' => $dispatchId,
        ];
        $this->logDispatch($dispatchId, $purpose, $template, $toE164, null, $attempted, $payload['failover_reason'], 'failed', null);

        return $payload;
    }

    /**
     * OTP via WhatsApp keeping the same plaintext code; email is caller's responsibility.
     *
     * @return array{whatsapp_ok:bool,slot:?string,result:string,dispatch_id:?string}
     */
    public function sendRegistrationOtp(string $toE164, string $code, string $challengeKey): array
    {
        $settings = $this->accounts->settings();
        if (! ($settings['otp_enabled'] ?? false)) {
            return ['whatsapp_ok' => false, 'slot' => null, 'result' => 'otp_disabled', 'dispatch_id' => null];
        }

        $result = $this->sendWhatsApp(
            $toE164,
            'BINAA QATAR verification code: '.$code.' (expires soon).',
            'registration_otp',
            'registration_otp',
            'otp:'.$challengeKey,
        );

        return [
            'whatsapp_ok' => (bool) $result['ok'],
            'slot' => $result['slot'],
            'result' => $result['result'],
            'dispatch_id' => $result['dispatch_id'],
        ];
    }

    /**
     * Operational notification — recipient must be verified by caller policy.
     *
     * @return array{ok:bool,channel:?string,slot:?string,result:string}
     */
    public function sendOperationalNotice(
        string $toE164,
        string $text,
        string $purpose,
        bool $recipientVerified,
        ?callable $emailFallback = null,
    ): array {
        if (! $recipientVerified) {
            return ['ok' => false, 'channel' => null, 'slot' => null, 'result' => 'recipient_unverified'];
        }
        $settings = $this->accounts->settings();
        if (! ($settings['notifications_enabled'] ?? false)) {
            if ($emailFallback && ($settings['email_fallback_enabled'] ?? true)) {
                $emailFallback();
                $this->opsAudit->record(null, 'platform.whatsapp.email_fallback_used', null, null, null, 'ok', ['purpose' => $purpose]);

                return ['ok' => true, 'channel' => 'email', 'slot' => null, 'result' => 'email_fallback'];
            }

            return ['ok' => false, 'channel' => null, 'slot' => null, 'result' => 'notifications_disabled'];
        }

        $wa = $this->sendWhatsApp($toE164, $text, 'operational_notice', $purpose);
        if ($wa['ok']) {
            return ['ok' => true, 'channel' => 'whatsapp', 'slot' => $wa['slot'], 'result' => 'sent'];
        }
        if ($wa['result'] === 'unknown') {
            return ['ok' => false, 'channel' => 'whatsapp', 'slot' => $wa['slot'], 'result' => 'unknown'];
        }
        if ($emailFallback && ($settings['email_fallback_enabled'] ?? true)) {
            $emailFallback();
            $this->opsAudit->record(null, 'platform.whatsapp.email_fallback_used', null, null, null, 'ok', [
                'purpose' => $purpose,
                'dispatch_id' => $wa['dispatch_id'],
            ]);

            return ['ok' => true, 'channel' => 'email', 'slot' => null, 'result' => 'email_fallback'];
        }

        return ['ok' => false, 'channel' => null, 'slot' => null, 'result' => 'failed'];
    }

    public function sendTestMessage(?PlatformUser $actor, string $slot, string $toE164, bool $confirmed): array
    {
        if (! $confirmed) {
            throw new RuntimeException('confirmation_required');
        }
        if (! $this->accounts->isSendReady($slot)) {
            throw new RuntimeException('slot_not_ready');
        }
        $result = $this->bridge->send(
            $slot,
            $toE164,
            'BINAA QATAR connection test. Unofficial bridge.',
            'connection_test',
            'test-'.bin2hex(random_bytes(6)),
        );
        $this->opsAudit->record($actor, 'platform.whatsapp.message_tested', $slot, null, null, 'ok', [
            'destination_masked' => $result['destination_masked'] ?? null,
            'message_id' => $result['message_id'] ?? null,
        ]);

        return ['ok' => true, 'slot' => $slot, 'message_id' => $result['message_id'] ?? null];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function slotOrder(array $settings): array
    {
        $primaryFirst = ((int) ($settings['primary_priority'] ?? 1)) <= ((int) ($settings['secondary_priority'] ?? 2));
        $order = $primaryFirst ? ['primary', 'secondary'] : ['secondary', 'primary'];
        if (! ($settings['failover_enabled'] ?? false)) {
            return [$order[0]];
        }

        return $order;
    }

    /**
     * @param  list<string>  $attempted
     */
    private function logDispatch(
        string $dispatchId,
        string $purpose,
        string $template,
        string $toE164,
        ?string $chosenSlot,
        array $attempted,
        ?string $failoverReason,
        string $result,
        ?string $channelFinal,
    ): void {
        if (! Schema::connection('mysql')->hasTable('platform_whatsapp_dispatch_log')) {
            return;
        }
        $digits = preg_replace('/\D+/', '', $toE164) ?: '';
        DB::connection('mysql')->table('platform_whatsapp_dispatch_log')->insert([
            'dispatch_id' => $dispatchId,
            'purpose' => $purpose,
            'template' => $template,
            'destination_masked' => $digits !== '' ? '+'.substr($digits, 0, 3).'***'.substr($digits, -2) : null,
            'chosen_slot' => $chosenSlot,
            'attempted_slots' => implode(',', $attempted),
            'failover_reason' => $failoverReason,
            'result' => $result,
            'channel_final' => $channelFinal,
            'meta' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
