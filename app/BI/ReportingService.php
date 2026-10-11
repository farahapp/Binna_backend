<?php

declare(strict_types=1);

namespace App\BI;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReportingService
{
    public function __construct(private readonly Request $request)
    {
    }

    /**
     * Return the dashboards visible to the authenticated tenant user.
     */
    public function getDashboard(int|string $userId, ?string $dateFrom = null, ?string $dateTo = null): Collection
    {
        $user = $this->authenticatedUser($userId);
        $tenantSlug = $this->tenantSlug($user);

        if (! method_exists($user, 'hasPermission') || ! $user->hasPermission('bi.dashboard.view')) {
            throw new RuntimeException('The authenticated user is not authorized to view BI dashboards.');
        }

        return DB::connection('tenant')->transaction(
            function () use ($tenantSlug, $dateFrom, $dateTo): Collection {
                $query = Dashboard::query()->where('tenant_slug', $tenantSlug);

                if ($dateFrom !== null) {
                    $query->whereDate('created_at', '>=', $dateFrom);
                }

                if ($dateTo !== null) {
                    $query->whereDate('created_at', '<=', $dateTo);
                }

                return $query->get()->values();
            },
            attempts: 1,
        );
    }

    private function authenticatedUser(int|string $userId): Authenticatable
    {
        $user = $this->request->user();

        if ($user === null || (string) $user->getAuthIdentifier() !== (string) $userId) {
            throw new RuntimeException('Authenticated user does not match the requested dashboard user.');
        }

        return $user;
    }

    private function tenantSlug(Authenticatable $user): string
    {
        $tenantSlug = data_get($user, 'tenant_slug');

        if (! is_string($tenantSlug) || $tenantSlug === '') {
            throw new RuntimeException('The authenticated user has no tenant context.');
        }

        return $tenantSlug;
    }
}
