<?php

declare(strict_types=1);

namespace App\Http\Controllers\Core;

use App\Core\CollaborationService;
use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CollaborationController extends Controller
{
    public function __construct(
        private readonly CollaborationService $service,
    ) {}

    public function submit(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_identity_id' => 'required|uuid',
                'submission_type' => 'required|in:document,rfi,inspection_request,progress,other',
                'title' => 'required|string|max:255',
                'body' => 'nullable|string',
                'payload' => 'nullable|array',
                'submitter_participation_id' => 'nullable|uuid',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            $item = $this->service->submit($validated, (string) auth()->id(), app(TenantContext::class)->slug);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $item->toArray()], 201);
    }

    public function review(Request $request, string $submissionId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'outcome' => 'required|in:recommend_approve,recommend_reject,observation,clarification_needed',
                'comments' => 'nullable|string',
                'requires_owner_decision' => 'nullable|boolean',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            $item = $this->service->review($submissionId, $validated, (string) auth()->id(), app(TenantContext::class)->slug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Submission not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $item->toArray()], 201);
    }

    public function ownerDecide(Request $request, string $submissionId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'decision' => 'required|in:approve,reject,return',
                'comments' => 'nullable|string',
                'review_id' => 'nullable|uuid',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            $item = $this->service->ownerDecide($submissionId, $validated, (string) auth()->id(), app(TenantContext::class)->slug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Submission not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $item->toArray()], 201);
    }

    public function listSubmissions(string $identityId): JsonResponse
    {
        try {
            $items = $this->service->listSubmissions($identityId, app(TenantContext::class)->slug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Project identity not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $items->toArray()]);
    }
}
