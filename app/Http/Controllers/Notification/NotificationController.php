<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notification;

use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use App\Notification\Notification;
use App\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NotificationController — Notifications HTTP API Layer.
 *
 * Architectural constraints (A1 MOD-19 / TASK-002B / CAP-210):
 *  - Delegates ALL business logic to NotificationService.
 *  - Does NOT access Notification models directly for mutations.
 *  - Tenant context resolved by ResolveTenantContext middleware (OD-001).
 *  - All endpoints protected by auth:sanctum.
 *  - Provider abstraction: no external provider is bound (NullNotificationProvider default).
 *  - F-3 (UNRESOLVED): Retry/dead-letter semantics are not implemented.
 *
 * TASK-002B — Notifications Shared Service HTTP API Layer
 */
class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    // =========================================================================
    // LIST — GET /api/v1/notifications
    // =========================================================================

    /**
     * List notifications for the authenticated user within the current tenant.
     *
     * GET /api/v1/notifications
     * Optional query params:
     *   - unread_only=1  — filter to unread notifications only
     *   - per_page=25    — pagination size (max 100)
     */
    public function index(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $userId = $request->user()?->id;

        $query = Notification::where('tenant_slug', $tenantContext->slug)
            ->where('notifiable_id', $userId)
            ->where('notifiable_type', 'user')
            ->orderByDesc('created_at');

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $notifications = $query->paginate($perPage);

        return response()->json([
            'data'  => $notifications->items(),
            'meta'  => [
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
                'per_page'     => $notifications->perPage(),
                'total'        => $notifications->total(),
            ],
        ]);
    }

    // =========================================================================
    // MARK AS READ — PATCH /api/v1/notifications/{id}/read
    // =========================================================================

    /**
     * Mark a notification as read.
     *
     * PATCH /api/v1/notifications/{id}/read
     * Tenant isolation: only the owning user within the current tenant may mark.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $userId = $request->user()?->id;

        $notification = Notification::where('tenant_slug', $tenantContext->slug)
            ->where('notifiable_id', $userId)
            ->where('notifiable_type', 'user')
            ->find($id);

        if ($notification === null) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->markAsRead();

        return response()->json(['data' => $notification]);
    }

    // =========================================================================
    // MARK ALL AS READ — PATCH /api/v1/notifications/read-all
    // =========================================================================

    /**
     * Mark all unread notifications as read for the authenticated user.
     *
     * PATCH /api/v1/notifications/read-all
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $userId = $request->user()?->id;

        Notification::where('tenant_slug', $tenantContext->slug)
            ->where('notifiable_id', $userId)
            ->where('notifiable_type', 'user')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    // =========================================================================
    // PREFERENCES — GET /api/v1/notifications/preferences
    // =========================================================================

    /**
     * Get notification preferences for the authenticated user.
     *
     * GET /api/v1/notifications/preferences
     */
    public function getPreferences(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $userId = $request->user()?->id;

        $preferences = \App\Notification\NotificationPreference::where('tenant_slug', $tenantContext->slug)
            ->where('user_id', $userId)
            ->get();

        return response()->json(['data' => $preferences]);
    }

    // =========================================================================
    // SET PREFERENCE — PUT /api/v1/notifications/preferences
    // =========================================================================

    /**
     * Set a notification preference for the authenticated user.
     *
     * PUT /api/v1/notifications/preferences
     * Body: { notification_type, channel, enabled }
     */
    public function setPreference(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notification_type' => ['required', 'string', 'max:100'],
            'channel'           => ['required', 'string', 'max:50'],
            'enabled'           => ['required', 'boolean'],
        ]);

        $tenantContext = app(TenantContext::class);
        $userId = $request->user()?->id;

        $preference = $this->notificationService->setPreference(
            tenantSlug: $tenantContext->slug,
            userId: $userId,
            notificationType: $validated['notification_type'],
            channel: $validated['channel'],
            enabled: $validated['enabled'],
        );

        return response()->json(['data' => $preference]);
    }
}
