<?php

declare(strict_types=1);

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Network\BidService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BidController extends Controller
{
    public function __construct(private readonly BidService $service) {}

    public function submit(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'tender_id' => 'required|uuid',
                'contractor_profile_id' => 'required|uuid',
                'content' => 'required|array|min:1',
                'reason' => 'nullable|string|max:1000',
            ]);
            $bid = $this->service->submit($data, (string) auth()->id(), (string) $request->header('X-Tenant', ''));
            return response()->json(['data' => $bid->toArray()], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Tender or contractor NetworkProfile not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $bid = $this->service->get($id, (string) $request->header('X-Tenant', ''));
            return response()->json(['data' => $bid->toArray()]);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network Bid not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    public function revise(Request $request, string $id): JsonResponse
    {
        try {
            $data = $request->validate([
                'content' => 'required|array|min:1',
                'reason' => 'nullable|string|max:1000',
            ]);
            $bid = $this->service->revise($id, $data, (string) auth()->id(), (string) $request->header('X-Tenant', ''));
            return response()->json(['data' => $bid->toArray()]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network Bid not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function withdraw(Request $request, string $id): JsonResponse
    {
        try {
            $data = $request->validate(['reason' => 'nullable|string|max:1000']);
            $bid = $this->service->withdraw($id, $data['reason'] ?? null, (string) auth()->id(), (string) $request->header('X-Tenant', ''));
            return response()->json(['data' => $bid->toArray()]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network Bid not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
