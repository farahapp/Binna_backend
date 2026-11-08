<?php

declare(strict_types=1);

namespace App\Http\Controllers\IAM;

use App\Foundation\TenantIdentifier;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * TenantResolutionController — resolves a company identifier to a signed resolution_token.
 *
 * PATH A of the SPA login flow (PROD-TENANT-SPA-FIX-001):
 *   POST /api/auth/tenant/resolve
 *   Body: { identifier_type: "cr"|"qid", identifier: string }
 *
 * The resolution_token is a short-lived (5 min) encrypted payload that encodes
 * { tenant_id, slug, expires_at }.  It is passed to spaLogin() so that the
 * SPA login endpoint can resolve the tenant without an X-Tenant header.
 *
 * Rate-limited: 10 attempts / 60 s per IP.
 * Schema info (schema_name) is NEVER included in the response.
 */
class TenantResolutionController extends Controller
{
    /**
     * Resolve a verified CR or QID to a signed resolution_token.
     *
     * @throws \Illuminate\Validation\ValidationException  on invalid input
     */
    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier_type' => ['required', 'string', Rule::in(['cr', 'qid'])],
            'identifier' => ['required', 'string', 'max:128'],
        ]);

        $key = 'tenant-resolution:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['message' => 'Unable to continue. Please try again later.'], 429);
        }
        RateLimiter::hit($key, 60);

        $normalized = TenantIdentifier::normalize($data['identifier']);
        $matches = TenantIdentifier::on('mysql')
            ->with('tenant:id,slug,status,legal_type')
            ->where('type', $data['identifier_type'])
            ->where('normalized_number', $normalized)
            ->where('validity_status', 'valid')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', now()->toDateString());
            })
            ->limit(2)
            ->get();

        if ($matches->count() !== 1) {
            return response()->json(['message' => 'Unable to continue with the provided details.'], 422);
        }

        $identifier = $matches->first();
        if (
            ! $identifier->tenant
            || ! $identifier->tenant->isActive()
            || TenantIdentifier::loginTypeForLegalType($identifier->tenant->legal_type) !== $data['identifier_type']
        ) {
            return response()->json(['message' => 'Unable to continue with the provided details.'], 422);
        }

        return response()->json([
            'resolution_token' => Crypt::encryptString(json_encode([
                'tenant_id' => $identifier->tenant->id,
                'slug' => $identifier->tenant->slug,
                'expires_at' => now()->addMinutes(5)->timestamp,
            ], JSON_THROW_ON_ERROR)),
        ]);
    }
}
