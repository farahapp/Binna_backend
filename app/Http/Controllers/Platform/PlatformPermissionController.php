<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Foundation\PlatformPermission;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PlatformPermissionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => PlatformPermission::on('mysql')->orderBy('name')->get(['id', 'name', 'description']),
        ]);
    }
}
