<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Foundation\PlatformUser;
use App\Http\Controllers\Controller;
use App\WhatsApp\WhatsAppDispatchService;
use App\WhatsApp\WhatsAppDualAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PlatformWhatsAppController extends Controller
{
    public function show(WhatsAppDualAccountService $whatsapp): JsonResponse
    {
        return response()->json(['whatsapp' => $whatsapp->dashboard()]);
    }

    public function updateSettings(Request $request, WhatsAppDualAccountService $whatsapp): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $data = $request->validate([
            'primary_enabled' => ['sometimes', 'boolean'],
            'secondary_enabled' => ['sometimes', 'boolean'],
            'failover_enabled' => ['sometimes', 'boolean'],
            'otp_enabled' => ['sometimes', 'boolean'],
            'notifications_enabled' => ['sometimes', 'boolean'],
            'email_fallback_enabled' => ['sometimes', 'boolean'],
        ]);

        try {
            return response()->json(['whatsapp' => $whatsapp->updateSettings($actor, $data)]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function startLink(Request $request, WhatsAppDualAccountService $whatsapp): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $data = $request->validate([
            'slot' => ['required', 'in:primary,secondary'],
            'replacement' => ['sometimes', 'boolean'],
        ]);

        try {
            return response()->json($whatsapp->startLink($actor, $data['slot'], (bool) ($data['replacement'] ?? false)));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function qr(Request $request, WhatsAppDualAccountService $whatsapp): JsonResponse
    {
        $data = $request->validate(['slot' => ['required', 'in:primary,secondary']]);
        $qr = $whatsapp->qr($data['slot']);
        if ($qr === null) {
            return response()->json(['message' => 'QR unavailable.'], 404);
        }

        return response()->json($qr);
    }

    public function refreshQr(Request $request, WhatsAppDualAccountService $whatsapp): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $data = $request->validate(['slot' => ['required', 'in:primary,secondary']]);

        return response()->json($whatsapp->refreshQr($actor, $data['slot']));
    }

    public function cancelCandidate(Request $request, WhatsAppDualAccountService $whatsapp): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $data = $request->validate(['slot' => ['required', 'in:primary,secondary']]);

        return response()->json(['whatsapp' => $whatsapp->cancelCandidate($actor, $data['slot'])]);
    }

    public function syncIdentity(Request $request, WhatsAppDualAccountService $whatsapp): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $data = $request->validate([
            'slot' => ['required', 'in:primary,secondary'],
            'expected_phone_e164' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        try {
            return response()->json(['whatsapp' => $whatsapp->syncCandidateIdentity(
                $actor,
                $data['slot'],
                $data['expected_phone_e164'] ?? null,
            )]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function approve(Request $request, WhatsAppDualAccountService $whatsapp): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $data = $request->validate(['slot' => ['required', 'in:primary,secondary']]);

        try {
            return response()->json(['whatsapp' => $whatsapp->approve($actor, $data['slot'])]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function rollback(Request $request, WhatsAppDualAccountService $whatsapp): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $data = $request->validate(['slot' => ['required', 'in:primary,secondary']]);

        try {
            return response()->json(['whatsapp' => $whatsapp->rollback($actor, $data['slot'])]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function disconnect(Request $request, WhatsAppDualAccountService $whatsapp): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $data = $request->validate(['slot' => ['required', 'in:primary,secondary']]);

        return response()->json(['whatsapp' => $whatsapp->disconnect($actor, $data['slot'])]);
    }

    public function testConnection(Request $request, WhatsAppDualAccountService $whatsapp): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $data = $request->validate([
            'slot' => ['sometimes', 'nullable', 'in:primary,secondary'],
        ]);

        return response()->json($whatsapp->testConnection($actor, $data['slot'] ?? null));
    }

    public function testMessage(Request $request, WhatsAppDispatchService $dispatch): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $data = $request->validate([
            'slot' => ['required', 'in:primary,secondary'],
            'to_e164' => ['required', 'string', 'max:32', 'regex:/^\+[1-9]\d{7,14}$/'],
            'confirmed' => ['required', 'accepted'],
        ]);

        try {
            return response()->json($dispatch->sendTestMessage(
                $actor,
                $data['slot'],
                $data['to_e164'],
                (bool) $data['confirmed'],
            ));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function createBackup(Request $request, WhatsAppDualAccountService $whatsapp): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $data = $request->validate([
            'slot' => ['required', 'in:primary,secondary'],
            'role' => ['sometimes', 'in:active,candidate'],
        ]);

        try {
            return response()->json($whatsapp->createEncryptedBackup($actor, $data['slot'], $data['role'] ?? 'active'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function verifyStorage(Request $request, WhatsAppDualAccountService $whatsapp): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $data = $request->validate([
            'slot' => ['required', 'in:primary,secondary'],
            'backup_id' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        try {
            return response()->json($whatsapp->verifyStorage($actor, $data['slot'], $data['backup_id'] ?? null));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function recoveryTest(Request $request, WhatsAppDualAccountService $whatsapp): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $data = $request->validate([
            'slot' => ['required', 'in:primary,secondary'],
            'backup_id' => ['required', 'string', 'max:80'],
        ]);

        try {
            return response()->json($whatsapp->recoveryTest($actor, $data['slot'], $data['backup_id']));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
