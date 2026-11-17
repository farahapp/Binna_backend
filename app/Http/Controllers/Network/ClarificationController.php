<?php

declare(strict_types=1);

namespace App\Http\Controllers\Network;

use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use App\Network\ClarificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClarificationController extends Controller
{
    public function __construct(
        private readonly ClarificationService $service,
    ) {}

    public function ask(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'tender_id' => 'required|uuid',
                'question' => 'required|string|max:5000',
                'bid_id' => 'nullable|uuid',
                'parent_id' => 'nullable|uuid',
                'asked_by_profile_id' => 'nullable|uuid',
                'directed_to_tenant_slug' => 'nullable|string|max:100',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            $item = $this->service->ask(
                $validated,
                (string) auth()->id(),
                app(TenantContext::class)->slug,
            );
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Tender not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $item->toArray()], 201);
    }

    public function answer(Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'response' => 'required|string|max:5000',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            $item = $this->service->answer(
                $id,
                $validated['response'],
                (string) auth()->id(),
                app(TenantContext::class)->slug,
            );
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Clarification not found.'], 404);
        } catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'not authorized') ? 403 : 422;

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json(['data' => $item->toArray()]);
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
}
