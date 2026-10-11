<?php

declare(strict_types=1);

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Network\AwardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AwardController — HTTP layer for NETWORK-005 Award.
 *
 * Routes:
 *  POST   /api/v1/network/tenders/{tenderId}/award   — create Award
 *  GET    /api/v1/network/awards/{id}                — get Award by ID
 *  GET    /api/v1/network/tenders/{tenderId}/award   — get Award by Tender
 */
class AwardController extends Controller
{
    public function __construct(private readonly AwardService $awardService) {}

    /**
     * POST /api/v1/network/tenders/{tenderId}/award
     *
     * Create an Award for a Tender.
     * Requires: Tender closed + Evaluation completed + Bid eligible + Actor authorized.
     */
    public function create(Request $request, string $tenderId): JsonResponse
    {
        $validated = $request->validate([
            'winning_bid_id' => 'required|uuid',
            'reason'         => 'nullable|string|max:5000',
        ]);

        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');

        try {
            $award = $this->awardService->createAward(
                $tenderId,
                $validated['winning_bid_id'],
                $actorId,
                $tenantSlug,
                $validated['reason'] ?? null
            );
            return response()->json($award, 201);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/network/awards/{id}
     *
     * Retrieve an Award by its ID.
     * Enforces tenant ownership.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');

        try {
            $award = $this->awardService->getAward($id, $actorId, $tenantSlug);
            return response()->json($award);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    /**
     * GET /api/v1/network/tenders/{tenderId}/award
     *
     * Retrieve the Award for a given Tender.
     * Enforces tenant ownership.
     */
    public function showByTender(Request $request, string $tenderId): JsonResponse
    {
        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');

        try {
            $award = $this->awardService->getAwardByTender($tenderId, $actorId, $tenantSlug);
            return response()->json($award);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }
}
