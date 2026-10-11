<?php

declare(strict_types=1);

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Network\EvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvaluationController extends Controller
{
    public function __construct(private readonly EvaluationService $evaluationService) {}

    // POST /api/v1/network/tenders/{tenderId}/evaluation
    public function start(Request $request, string $tenderId): JsonResponse
    {
        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');
        try {
            $evaluation = $this->evaluationService->start($tenderId, $actorId, $tenantSlug);
            return response()->json($evaluation->load('notes'), 201);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // GET /api/v1/network/evaluations/{id}
    public function show(Request $request, string $id): JsonResponse
    {
        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');
        try {
            $evaluation = $this->evaluationService->getEvaluation($id, $actorId, $tenantSlug);
            return response()->json($evaluation->load('notes'));
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    // PATCH /api/v1/network/evaluations/{id}/complete
    public function complete(Request $request, string $id): JsonResponse
    {
        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');
        try {
            $evaluation = $this->evaluationService->complete($id, $actorId, $tenantSlug);
            return response()->json($evaluation->load('notes'));
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // POST /api/v1/network/evaluations/{id}/notes
    public function addNote(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|max:5000',
            'bid_id'  => 'nullable|uuid',
        ]);
        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');
        try {
            $note = $this->evaluationService->addNote($id, $validated, $actorId, $tenantSlug);
            return response()->json($note, 201);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // GET /api/v1/network/evaluations/{id}/bids
    public function bids(Request $request, string $id): JsonResponse
    {
        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');
        try {
            $bids = $this->evaluationService->getBidsForEvaluation($id, $actorId, $tenantSlug);
            return response()->json($bids->values());
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }
}
