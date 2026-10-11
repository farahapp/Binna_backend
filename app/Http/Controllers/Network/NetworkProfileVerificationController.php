<?php

declare(strict_types=1);

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Network\NetworkProfileVerificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NetworkProfileVerificationController extends Controller
{
    public function __construct(private readonly NetworkProfileVerificationService $service) {}

    public function submit(Request $request, string $profileId): JsonResponse
    {
        try {
            $data = $request->validate([
                'evidence' => 'required|array',
                'evidence.business_registration' => 'required|string|max:255',
                'evidence.professional_license' => 'nullable|string|max:255',
                'evidence.supporting_documents' => 'nullable|array',
                'evidence.supporting_documents.*' => 'string|max:255',
            ]);
            $verification = $this->service->submit($profileId, $data['evidence'], (string) auth()->id(), (string) $request->header('X-Tenant'));
            return response()->json(['data' => $verification], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network profile not found.'], 404);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    public function status(string $profileId): JsonResponse
    {
        try {
            return response()->json(['data' => $this->service->publicStatus($profileId)]);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network profile not found.'], 404);
        }
    }

    public function own(Request $request, string $profileId): JsonResponse
    {
        try {
            return response()->json(['data' => $this->service->ownerView($profileId, (string) $request->header('X-Tenant'))]);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Verification not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    public function pending(Request $request): JsonResponse
    {
        if (! $this->isReviewer($request)) {
            return response()->json(['error' => 'Reviewer permission required.'], 403);
        }
        return response()->json($this->service->pending());
    }

    public function review(Request $request, string $profileId): JsonResponse
    {
        if (! $this->isReviewer($request)) {
            return response()->json(['error' => 'Reviewer permission required.'], 403);
        }
        try {
            return response()->json(['data' => $this->service->reviewerView($profileId)]);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Verification not found.'], 404);
        }
    }

    public function approve(Request $request, string $profileId): JsonResponse
    {
        return $this->reviewTransition($request, $profileId, 'approve');
    }

    public function reject(Request $request, string $profileId): JsonResponse
    {
        return $this->reviewTransition($request, $profileId, 'reject');
    }

    public function revoke(Request $request, string $profileId): JsonResponse
    {
        return $this->reviewTransition($request, $profileId, 'revoke');
    }

    private function reviewTransition(Request $request, string $profileId, string $action): JsonResponse
    {
        if (! $this->isReviewer($request)) {
            return response()->json(['error' => 'Reviewer permission required.'], 403);
        }
        try {
            $reason = null;
            if (in_array($action, ['reject', 'revoke'], true)) {
                $reason = $request->validate(['reason' => 'required|string|max:2000'])['reason'];
            }
            $verification = match ($action) {
                'approve' => $this->service->approve($profileId, (string) auth()->id(), (string) $request->header('X-Tenant')),
                'reject' => $this->service->reject($profileId, (string) $reason, (string) auth()->id(), (string) $request->header('X-Tenant')),
                'revoke' => $this->service->revoke($profileId, (string) $reason, (string) auth()->id(), (string) $request->header('X-Tenant')),
            };
            return response()->json(['data' => $verification]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Verification not found.'], 404);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function isReviewer(Request $request): bool
    {
        $user = $request->user();
        return $user !== null && ($user->hasPermission('network.profile.verify') || $user->hasRole('network_admin'));
    }
}
