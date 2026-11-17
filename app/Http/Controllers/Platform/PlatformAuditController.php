<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Audit\PlatformAuditEntry;
use App\Foundation\PlatformUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PlatformAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! Schema::connection('mysql')->hasTable('platform_audit_entries')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 20,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ]);
        }

        $validated = $request->validate([
            'event' => ['sometimes', 'nullable', 'string', 'max:120'],
            'actor_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'subject_type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $query = PlatformAuditEntry::on('mysql')->newQuery()->orderByDesc('created_at');

        if (! empty($validated['event'])) {
            $query->where('event', 'like', '%'.$validated['event'].'%');
        }
        if (! empty($validated['actor_id'])) {
            $query->where('actor_id', (int) $validated['actor_id']);
        }
        if (! empty($validated['subject_type'])) {
            $query->where('subject_type', $validated['subject_type']);
        }

        $page = $query->paginate($perPage);
        $actorIds = collect($page->items())->pluck('actor_id')->filter()->unique()->values();
        $actors = $actorIds->isEmpty()
            ? collect()
            : PlatformUser::on('mysql')->whereIn('id', $actorIds)->get(['id', 'name', 'email'])->keyBy('id');

        return response()->json([
            'data' => collect($page->items())->map(function (PlatformAuditEntry $entry) use ($actors) {
                $actor = $entry->actor_id ? $actors->get($entry->actor_id) : null;

                return [
                    'id' => $entry->id,
                    'actor_id' => $entry->actor_id,
                    'actor' => $actor ? [
                        'id' => $actor->id,
                        'name' => $actor->name,
                        'email' => $actor->email,
                    ] : null,
                    'event' => $entry->event,
                    'subject_type' => $entry->subject_type,
                    'subject_id' => $entry->subject_id,
                    'before' => $entry->before,
                    'after' => $entry->after,
                    'created_at' => optional($entry->created_at)?->toIso8601String(),
                ];
            })->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}
