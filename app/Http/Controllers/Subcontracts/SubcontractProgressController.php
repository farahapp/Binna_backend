<?php

declare(strict_types=1);

namespace App\Http\Controllers\Subcontracts;

use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use App\Subcontracts\Services\SubcontractProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SubcontractProgressController — CAP-080 HTTP API Layer (Progress & Certificates).
 *
 * Architectural constraints (A1 MOD-10 / TASK-009C):
 *  - Delegates ALL business logic to SubcontractProgressService.
 *  - Does NOT contain cumulative-progress calculations, approval logic, or payment logic.
 *  - Tenant context resolved by ResolveTenantContext middleware (OD-001).
 *  - All endpoints protected by auth:sanctum.
 *  - Controllers are thin: validate → delegate → respond.
 *
 * TASK-009C — Subcontract HTTP API Layer (CAP-080)
 */
class SubcontractProgressController extends Controller
{
    public function __construct(
        private readonly SubcontractProgressService $progressService,
    ) {}

    /**
     * POST /api/v1/subcontracts/agreements/{id}/progress
     * Record a progress update for an active SubcontractAgreement.
     */
    public function recordProgress(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('subcontract.progress.record')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'progress_period'       => ['required', 'string', 'max:100'],
            'percentage_complete'   => ['required', 'numeric', 'min:0', 'max:100'],
            'cumulative_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'amount_certified'      => ['nullable', 'numeric', 'min:0'],
            'cumulative_amount'     => ['nullable', 'numeric', 'min:0'],
            'notes'                 => ['nullable', 'string'],
        ]);

        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $progress = $this->progressService->recordProgress(
                $id,
                $validated,
                $tenantContext->slug,
                $actorId,
            );
            return response()->json($progress->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/subcontracts/agreements/{id}/progress
     * Retrieve all progress records for a SubcontractAgreement.
     */
    public function getSubcontractProgress(Request $request, string $id): JsonResponse
    {
        try {
            $records = $this->progressService->getProgressRecords($id);
            return response()->json($records->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * POST /api/v1/subcontracts/agreements/{id}/certificates
     * Issue a payment certificate for a SubcontractAgreement.
     */
    public function issueCertificate(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('subcontract.certificate.issue')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'certificate_number'      => ['required', 'string', 'max:100'],
            'subcontract_progress_id' => ['required', 'string', 'uuid'],
            'certified_amount'        => ['required', 'numeric', 'min:0'],
            'issued_date'             => ['required', 'date'],
            'notes'                   => ['nullable', 'string'],
        ]);

        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $certificate = $this->progressService->issueCertificate(
                $id,
                $validated,
                $tenantContext->slug,
                $actorId,
            );
            return response()->json($certificate->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/subcontracts/certificates/{id}/approve
     * Approve a payment certificate.
     */
    public function approveCertificate(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('subcontract.certificate.approve')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $certificate = $this->progressService->approveCertificate(
                $id,
                $actorId,
                $tenantContext->slug,
            );
            return response()->json($certificate->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
