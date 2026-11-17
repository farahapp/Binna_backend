<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Foundation\PlatformUser;
use App\Http\Controllers\Controller;
use App\WhatsApp\WhatsAppDualAccountService;
use App\WhatsApp\WhatsAppIntegrationMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Platform Admin WhatsApp management centre.
 * All responses are no-store: QR payloads and session metadata must never be cached.
 */
class PlatformWhatsAppIntegrationController extends Controller
{
    public function __construct(
        private readonly WhatsAppIntegrationMonitoringService $monitor,
        private readonly WhatsAppDualAccountService $whatsapp,
    ) {}

    public function index(): JsonResponse
    {
        return $this->noStore(response()->json($this->monitor->overview()));
    }

    public function accounts(): JsonResponse
    {
        $overview = $this->monitor->overview();

        return $this->noStore(response()->json([
            'accounts' => $overview['accounts'],
            'summary' => $overview['summary'],
        ]));
    }

    public function status(string $account): JsonResponse
    {
        try {
            return $this->noStore(response()->json(['account' => $this->safeAccount($account)]));
        } catch (RuntimeException $e) {
            return $this->noStore(response()->json(['message' => $e->getMessage()], 422));
        }
    }

    public function connect(Request $request, string $account): JsonResponse
    {
        $request->validate(['replacement' => ['sometimes', 'boolean']]);

        try {
            return $this->noStore(response()->json(
                $this->monitor->requestConnect($account, $this->actor($request), (bool) $request->input('replacement', false))
            ));
        } catch (RuntimeException $e) {
            return $this->noStore(response()->json(['message' => $e->getMessage()], 422));
        }
    }

    public function qr(string $account): JsonResponse
    {
        $qr = $this->whatsapp->qr($account);
        if ($qr === null) {
            return $this->noStore(response()->json(['message' => 'QR unavailable.'], 404));
        }

        return $this->noStore(response()->json($qr));
    }

    public function refreshQr(Request $request, string $account): JsonResponse
    {
        return $this->noStore(response()->json(
            $this->monitor->refreshQr($account, $this->actor($request))
        ));
    }

    public function reconnect(Request $request, string $account): JsonResponse
    {
        try {
            return $this->noStore(response()->json($this->monitor->reconnect($account, $this->actor($request))));
        } catch (RuntimeException $e) {
            return $this->noStore(response()->json(['message' => $e->getMessage()], 422));
        }
    }

    public function cancelPairing(Request $request, string $account): JsonResponse
    {
        return $this->noStore(response()->json($this->monitor->cancelPairing($account, $this->actor($request))));
    }

    public function disconnect(Request $request, string $account): JsonResponse
    {
        return $this->noStore(response()->json($this->monitor->disconnect($account, $this->actor($request))));
    }

    public function changeNumber(Request $request, string $account): JsonResponse
    {
        $request->validate([
            'confirmed' => ['required', 'accepted'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        try {
            return $this->noStore(response()->json(
                $this->monitor->requestConnect($account, $this->actor($request), true)
            ));
        } catch (RuntimeException $e) {
            return $this->noStore(response()->json(['message' => $e->getMessage()], 422));
        }
    }

    public function syncIdentity(Request $request, string $account): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $data = $request->validate([
            'expected_phone_e164' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        try {
            return $this->noStore(response()->json(['whatsapp' => $this->whatsapp->syncCandidateIdentity(
                $actor,
                $account,
                $data['expected_phone_e164'] ?? null,
            )]));
        } catch (RuntimeException $e) {
            return $this->noStore(response()->json(['message' => $e->getMessage()], 422));
        }
    }

    public function approve(Request $request, string $account): JsonResponse
    {
        /** @var PlatformUser $actor */
        $actor = $request->user();
        try {
            return $this->noStore(response()->json(['whatsapp' => $this->whatsapp->approve($actor, $account)]));
        } catch (RuntimeException $e) {
            return $this->noStore(response()->json(['message' => $e->getMessage()], 422));
        }
    }

    public function events(string $account): JsonResponse
    {
        try {
            return $this->noStore(response()->json(['events' => $this->monitor->events($account)]));
        } catch (RuntimeException $e) {
            return $this->noStore(response()->json(['message' => $e->getMessage()], 422));
        }
    }

    private function actor(Request $request): ?PlatformUser
    {
        $user = $request->user();

        return $user instanceof PlatformUser ? $user : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeAccount(string $account): array
    {
        return $this->monitor->account($account);
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
