<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Foundation\PlatformUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WhatsAppOpsAudit
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function record(
        ?PlatformUser $actor,
        string $event,
        ?string $slot = null,
        ?string $previousPhone = null,
        ?string $newPhone = null,
        ?string $result = null,
        ?array $meta = null,
    ): void {
        if (! Schema::connection('mysql')->hasTable('platform_whatsapp_ops_audit')) {
            return;
        }
        // Strip forbidden keys if present
        if (is_array($meta)) {
            unset($meta['otp'], $meta['code'], $meta['qr'], $meta['qr_data_url'], $meta['session'], $meta['secret'], $meta['text']);
        }
        DB::connection('mysql')->table('platform_whatsapp_ops_audit')->insert([
            'actor_id' => $actor?->getKey(),
            'event' => $event,
            'slot' => $slot,
            'previous_phone_e164' => $previousPhone,
            'new_phone_e164' => $newPhone,
            'result' => $result,
            'meta' => $meta ? json_encode($meta, JSON_THROW_ON_ERROR) : null,
            'created_at' => now(),
        ]);
    }
}
