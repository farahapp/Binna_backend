<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Foundation\PlatformUser;
use App\Http\Controllers\Controller;
use App\Registration\RegistrationAuditService;
use App\Registration\RegistrationDocumentRequirement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformRegistrationDocumentRequirementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'legal_type' => ['nullable', Rule::in(['individual', 'organization', 'any'])],
            'activity_type' => ['nullable', Rule::in(['contractor', 'consultant', 'both', 'owner', 'any'])],
            'active_only' => ['nullable', 'boolean'],
        ]);

        $query = RegistrationDocumentRequirement::on('mysql')->orderBy('sort_order')->orderBy('id');
        if ($request->filled('legal_type')) {
            $query->where('legal_type', $request->query('legal_type'));
        }
        if ($request->filled('activity_type')) {
            $query->where('activity_type', $request->query('activity_type'));
        }
        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request, RegistrationAuditService $audit): JsonResponse
    {
        $data = $this->validated($request);
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $row = RegistrationDocumentRequirement::on('mysql')->create($data);
        $audit->recordIdentity(
            'platform',
            $actor->id,
            'registration_document_requirement',
            $row->id,
            'document_requirement.created',
            ['code' => $row->code, 'legal_type' => $row->legal_type, 'activity_type' => $row->activity_type],
        );

        return response()->json(['requirement' => $row], 201);
    }

    public function update(Request $request, int $id, RegistrationAuditService $audit): JsonResponse
    {
        $data = $this->validated($request, updating: true);
        /** @var PlatformUser $actor */
        $actor = $request->user();
        $row = RegistrationDocumentRequirement::on('mysql')->findOrFail($id);
        $before = $row->only(array_keys($data));
        $row->fill($data)->save();
        $audit->recordIdentity(
            'platform',
            $actor->id,
            'registration_document_requirement',
            $row->id,
            'document_requirement.updated',
            ['before' => $before, 'after' => $row->only(array_keys($data))],
        );

        return response()->json(['requirement' => $row->fresh()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'code' => [$updating ? 'sometimes' : 'required', 'string', 'max:64', 'alpha_dash'],
            'label_ar' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'label_en' => ['nullable', 'string', 'max:255'],
            'legal_type' => [$updating ? 'sometimes' : 'required', Rule::in(['individual', 'organization', 'any'])],
            'activity_type' => [$updating ? 'sometimes' : 'required', Rule::in(['contractor', 'consultant', 'both', 'owner', 'any'])],
            'is_required' => ['sometimes', 'boolean'],
            'expires' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);
    }
}
