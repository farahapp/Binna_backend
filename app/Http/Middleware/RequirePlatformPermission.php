<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Foundation\PlatformUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatformPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (! $user instanceof PlatformUser) {
            return response()->json([
                'error' => 'Platform operator identity required.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (! $user->hasPlatformPermission($permission)) {
            return response()->json([
                'error' => 'The required platform permission is missing.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
