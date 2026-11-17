<?php

declare(strict_types=1);

namespace App\Http\Controllers\Network;

use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use App\Network\InvitationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $service,
    ) {}

    public function invite(Request $request, string $tenderId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'invitee_network_profile_id' => 'required|uuid',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            $invitation = $this->service->invite(
                $tenderId,
                $validated['invitee_network_profile_id'],
                (string) auth()->id(),
                app(TenantContext::class)->slug,
            );
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Tender or profile not found.'], 404);
        } catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'not authorized') ? 403 : 422;

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json(['data' => $invitation->toArray()], 201);
    }

    public function listForTender(string $tenderId): JsonResponse
    {
        try {
            $items = $this->service->listForTender($tenderId, app(TenantContext::class)->slug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Tender not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $items->toArray()]);
    }

    public function listMine(): JsonResponse
    {
        return response()->json($this->service->listMine(app(TenantContext::class)->slug));
    }

    public function respond(Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'decision' => 'required|in:accept,decline',
                'note' => 'nullable|string|max:500',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            $invitation = $this->service->respond(
                $id,
                $validated['decision'],
                (string) auth()->id(),
                app(TenantContext::class)->slug,
                $validated['note'] ?? null,
            );
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Invitation not found.'], 404);
        } catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'not authorized') ? 403 : 422;

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json(['data' => $invitation->toArray()]);
    }
}
