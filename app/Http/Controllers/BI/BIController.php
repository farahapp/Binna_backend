<?php

declare(strict_types=1);

namespace App\Http\Controllers\BI;

use App\BI\ReportingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Thin HTTP API layer for read-only BI dashboards.
 */
final class BIController extends Controller
{
    public function __construct(
        private readonly ReportingService $reportingService,
    ) {}

    public function getDashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $validated = $request->validate([
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to'   => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
        ]);

        try {
            return response()->json(
                $this->reportingService->getDashboard(
                    $user->getAuthIdentifier(),
                    $validated['date_from'] ?? null,
                    $validated['date_to'] ?? null,
                ),
            );
        } catch (RuntimeException $exception) {
            return response()->json(
                ['error' => $exception->getMessage()],
                Response::HTTP_FORBIDDEN,
            );
        }
    }
}
