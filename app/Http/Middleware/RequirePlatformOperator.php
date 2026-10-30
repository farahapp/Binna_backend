<?php
declare(strict_types=1);
namespace App\Http\Middleware;

use App\Foundation\PlatformUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequirePlatformOperator
 *
 * Ensures the authenticated user is a PlatformUser (BINAA2 operator),
 * NOT a tenant user. Rejects any request where the authenticated identity
 * is a tenant-schema User.
 *
 * Security invariants:
 *  - Tenant users (App\IAM\User) are always rejected — 403.
 *  - Inactive platform operators are rejected — 403.
 *  - Unauthenticated requests are rejected — 401.
 *  - This middleware MUST run after auth:sanctum.
 */
class RequirePlatformOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        // Reject tenant users — they are App\IAM\User, not PlatformUser.
        if (! ($user instanceof PlatformUser)) {
            return response()->json(
                ['error' => 'Access denied. Platform operator identity required.'],
                Response::HTTP_FORBIDDEN
            );
        }

        // Reject inactive platform operators.
        if (! $user->isActive()) {
            return response()->json(
                ['error' => 'Platform operator account is inactive.'],
                Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }
}
