<?php

declare(strict_types=1);

namespace App\WhatsApp\Contracts;

interface WhatsAppBridgeContract
{
    /**
     * @return array<string, mixed>
     */
    public function health(): array;

    /**
     * @return array<string, mixed>
     */
    public function accountStatus(string $slot, string $role = 'active'): array;

    /**
     * @return array<string, mixed>
     */
    public function startSession(string $slot, string $role = 'candidate', ?string $mockPhone = null): array;

    /**
     * @return array<string, mixed>|null
     */
    public function qr(string $slot, string $role = 'candidate'): ?array;

    /**
     * @return array<string, mixed>
     */
    public function logout(string $slot, string $role): array;

    /**
     * Reconnect an existing session without generating QR or deleting files.
     *
     * @return array<string, mixed>
     */
    public function reconnect(string $slot, string $role = 'active'): array;

    /**
     * Cancel an in-flight pairing for one slot/role, preserving session files.
     *
     * @return array<string, mixed>
     */
    public function cancelPairing(string $slot, string $role = 'candidate'): array;

    /**
     * Admin disconnect: stop one socket and preserve encrypted session files.
     *
     * @return array<string, mixed>
     */
    public function disconnect(string $slot, string $role = 'active'): array;

    /**
     * @return array<string, mixed>
     */
    public function backup(string $slot): array;

    /**
     * @return array<string, mixed>
     */
    public function restore(string $slot): array;

    /**
     * @return array<string, mixed>
     */
    public function promote(string $slot): array;

    /**
     * @return array<string, mixed>
     */
    public function send(string $slot, string $toE164, string $text, string $template, ?string $messageId = null): array;

    /**
     * Mock-only: simulate QR completion.
     *
     * @return array<string, mixed>
     */
    public function mockComplete(string $slot, string $role, string $phoneE164): array;

    /**
     * @return array<string, mixed>
     */
    public function securityStatus(): array;

    /**
     * @return array<string, mixed>
     */
    public function createEncryptedBackup(string $slot, string $role = 'active'): array;

    /**
     * @return array<string, mixed>
     */
    public function verifyEncryptedBackup(string $slot, string $backupId): array;

    /**
     * @return array<string, mixed>
     */
    public function recoveryTest(string $slot, string $backupId): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listEncryptedBackups(string $slot): array;
}
