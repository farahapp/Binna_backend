<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Audit\PlatformAuditService;
use App\Foundation\Tenant;
use App\Foundation\TenantIdentifier;
use App\Foundation\TenantProvisioner;
use App\Foundation\TenantSetting;
use App\Foundation\PlatformUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PlatformTenantController — Tenant management for platform operators.
 *
 * Read-only list + detail + status-change operations on tenants.
 * All routes are protected by auth:sanctum (platform guard) + platform.operator middleware.
 *
 * Routes:
 *   GET    /api/platform/tenants              — list all tenants (paginated, filterable)
 *   GET    /api/platform/tenants/{id}         — get tenant detail + settings
 *   PATCH  /api/platform/tenants/{id}/suspend — suspend an active tenant
 *   PATCH  /api/platform/tenants/{id}/activate — activate a pending/suspended tenant
 *
 * Security invariants:
 *  - All reads/writes target the shared 'mysql' connection (Tenant model).
 *  - schema_name is NEVER returned to the client (hidden on Tenant model).
 *  - No tenant schema is accessed here — platform operators cannot read tenant data.
 *  - Status transitions: pending → active, active → suspended, suspended → active.
 */
class PlatformTenantController extends Controller
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly PlatformAuditService $auditService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateTenant($request);
        $slug = $this->uniqueSlug($data['name']);
        $tenant = $this->provisioner->provision($slug, $data['name']);

        DB::connection('mysql')->transaction(function () use ($tenant, $data): void {
            $tenant->fill(collect($data)->except('identifiers')->all())->save();
            foreach ($data['identifiers'] as $identifier) {
                $this->assertUniqueIdentifier($identifier['type'], $identifier['number']);
                $tenant->identifiers()->create([
                    ...$identifier,
                    'validity_status' => 'unverified',
                ]);
            }
        });

        return response()->json(['tenant' => $this->tenantPayload($tenant->load('identifiers'))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::on('mysql')->findOrFail($id);
        $data = $this->validateTenant($request, $tenant);
        $tenant->fill(collect($data)->except('identifiers')->all())->save();

        return response()->json(['tenant' => $this->tenantPayload($tenant->fresh()->load('identifiers'))]);
    }

    public function storeIdentifier(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::on('mysql')->findOrFail($id);
        $data = $this->validateIdentifier($request);
        $this->assertIdentifierMatchesLegalType($data['type'], $tenant->legal_type);
        $this->assertUniqueIdentifier($data['type'], $data['number']);
        $identifier = $tenant->identifiers()->create([
            ...$data,
            'validity_status' => 'unverified',
        ]);

        return response()->json(['identifier' => $this->identifierPayload($identifier)], 201);
    }

    public function updateIdentifier(Request $request, int $id, int $identifierId): JsonResponse
    {
        $identifier = TenantIdentifier::on('mysql')->where('tenant_id', $id)->findOrFail($identifierId);
        $data = $this->validateIdentifier($request);
        $tenant = Tenant::on('mysql')->findOrFail($id);
        $this->assertIdentifierMatchesLegalType($data['type'], $tenant->legal_type);
        $this->assertUniqueIdentifier($data['type'], $data['number'], $identifier->getKey());
        $identifier->update([
            ...$data,
            'validity_status' => 'unverified',
        ]);

        return response()->json(['identifier' => $this->identifierPayload($identifier->fresh())]);
    }

    public function approveIdentifier(Request $request, int $id, int $identifierId): JsonResponse
    {
        $data = $request->validate([
            'review_confirmed' => ['accepted'],
            'verification_reference' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        if (mb_strlen(trim($data['verification_reference'])) < 3) {
            abort(422, 'A verification reference of at least 3 characters is required.');
        }

        $result = DB::connection('mysql')->transaction(function () use ($request, $id, $identifierId, $data): TenantIdentifier {
            $actor = $request->user();
            if (! $actor instanceof PlatformUser) {
                abort(403, 'Platform operator identity required.');
            }

            $tenant = Tenant::on('mysql')->whereKey($id)->lockForUpdate()->firstOrFail();
            $identifier = TenantIdentifier::on('mysql')
                ->where('tenant_id', $id)
                ->whereKey($identifierId)
                ->lockForUpdate()
                ->firstOrFail();

            $requiredType = TenantIdentifier::loginTypeForLegalType($tenant->legal_type);
            if ($requiredType === null || $identifier->type !== $requiredType) {
                abort(422, 'The identifier type does not match the tenant legal type.');
            }
            if ($identifier->validity_status !== 'unverified') {
                abort(422, 'Only unverified identifiers can be approved.');
            }
            if ($identifier->expires_at !== null && $identifier->expires_at->toDateString() < now()->toDateString()) {
                abort(422, 'Expired identifiers cannot be approved.');
            }
            $this->assertUniqueIdentifier(
                $identifier->type,
                $identifier->number,
                $identifier->getKey(),
            );

            $before = ['validity_status' => $identifier->validity_status];
            $identifier->validity_status = 'valid';
            $identifier->save();

            $this->auditService->record(
                actor: $actor,
                event: 'platform.tenant_identifier.approved',
                subjectType: TenantIdentifier::class,
                subjectId: $identifier->getKey(),
                before: $before,
                after: [
                    'result' => 'approved',
                    'validity_status' => $identifier->validity_status,
                    'type' => $identifier->type,
                    'verification_reference' => trim($data['verification_reference']),
                ],
            );

            return $identifier->fresh();
        });

        return response()->json(['identifier' => $this->identifierPayload($result)]);
    }

    public function destroyIdentifier(int $id, int $identifierId): JsonResponse
    {
        $identifier = TenantIdentifier::on('mysql')->where('tenant_id', $id)->findOrFail($identifierId);
        $tenant = Tenant::on('mysql')->findOrFail($id);
        $requiredType = TenantIdentifier::loginTypeForLegalType($tenant->legal_type);

        if (
            $identifier->type === $requiredType
            && TenantIdentifier::on('mysql')->where('tenant_id', $id)->where('type', $requiredType)->count() <= 1
        ) {
            abort(422, 'The tenant must retain its required login identifier.');
        }

        $identifier->delete();

        return response()->json(status: 204);
    }

    private function validateTenant(Request $request, ?Tenant $tenant = null): array
    {
        $partial = $tenant !== null;
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'legal_type' => [$required, Rule::in(['individual', 'organization'])],
            'activity_type' => ['nullable', Rule::in(['contractor', 'consultant', 'both'])],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'responsible_name' => ['nullable', 'string', 'max:255'],
            'responsible_qid' => ['nullable', 'string', 'max:32'],
            'qid_expires_at' => ['nullable', 'date'],
            'identifiers' => [$partial ? 'sometimes' : 'required', 'array', 'min:1'],
            'identifiers.*.type' => ['required', Rule::in(TenantIdentifier::TYPES)],
            'identifiers.*.number' => ['required', 'string', 'max:128'],
            'identifiers.*.expires_at' => ['nullable', 'date'],
            'identifiers.*.document_path' => ['nullable', 'string', 'max:255'],
            'identifiers.*.validity_status' => ['prohibited'],
        ]);

        $legalType = $data['legal_type'] ?? $tenant?->legal_type;
        $identifiers = $data['identifiers'] ?? $tenant?->identifiers()->get()->toArray() ?? [];
        $requiredType = TenantIdentifier::loginTypeForLegalType($legalType);
        if (!$requiredType || !collect($identifiers)->contains('type', $requiredType)) {
            abort(422, 'An organization requires a CR and an individual requires a QID identifier.');
        }
        foreach ($identifiers as $identifier) {
            $this->assertIdentifierMatchesLegalType($identifier['type'], $legalType);
        }
        if (! $partial) {
            foreach ($identifiers as $identifier) {
                $this->assertUniqueIdentifier($identifier['type'], $identifier['number']);
            }
            $normalizedIdentifiers = collect($identifiers)->map(
                fn (array $identifier): string => $identifier['type'].'|'.TenantIdentifier::normalize($identifier['number'])
            );
            if ($normalizedIdentifiers->count() !== $normalizedIdentifiers->unique()->count()) {
                abort(422, 'Duplicate tenant identifiers are not allowed.');
            }
        }

        return $data;
    }

    private function validateIdentifier(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(TenantIdentifier::TYPES)],
            'number' => ['required', 'string', 'max:128'],
            'expires_at' => ['nullable', 'date'],
            'document_path' => ['nullable', 'string', 'max:255'],
            'validity_status' => ['prohibited'],
        ]);
    }

    private function assertUniqueIdentifier(string $type, string $number, int|string|null $exceptId = null): void
    {
        $normalized = TenantIdentifier::normalize($number);
        if ($normalized === '') {
            abort(422, 'A tenant identifier must contain letters or digits.');
        }

        $query = TenantIdentifier::on('mysql')
            ->where('type', $type)
            ->where('normalized_number', $normalized);
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }
        if ($query->exists()) {
            abort(422, 'This identifier is already registered.');
        }
    }

    private function assertIdentifierMatchesLegalType(string $identifierType, ?string $legalType): void
    {
        if (
            in_array($identifierType, ['cr', 'qid'], true)
            && TenantIdentifier::loginTypeForLegalType($legalType) !== $identifierType
        ) {
            abort(422, 'An organization requires a CR and an individual requires a QID identifier.');
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = substr($base, 0, 55);
        $suffix = 1;
        while (Tenant::on('mysql')->where('slug', $slug)->exists()) {
            $slug = substr($base, 0, 50).'-'.$suffix++;
        }

        return $slug;
    }
    /**
     * List all tenants with optional status filter and search.
     *
     * GET /api/platform/tenants
     * Query: status (pending|active|suspended), search (slug/name), per_page (default 20)
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'   => ['sometimes', 'nullable', Rule::in(['pending', 'active', 'suspended'])],
            'legal_type' => ['sometimes', 'nullable', Rule::in(['individual', 'organization'])],
            'activity_type' => ['sometimes', 'nullable', Rule::in(['contractor', 'consultant', 'both'])],
            'classification' => ['sometimes', 'nullable', Rule::in([
                Tenant::CLASSIFICATION_CONTRACTING_COMPANY,
                Tenant::CLASSIFICATION_INDIVIDUAL_CONTRACTOR,
                Tenant::CLASSIFICATION_ENGINEERING_CONSULTANCY,
                Tenant::CLASSIFICATION_NEEDS_REVIEW,
            ])],
            'search'   => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Tenant::on('mysql')->select([
            'id',
            'slug',
            'name',
            'legal_type',
            'activity_type',
            'status',
            'created_at',
            'updated_at',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('legal_type')) {
            $query->where('legal_type', $request->legal_type);
        }
        if ($request->filled('activity_type')) {
            $query->where('activity_type', $request->activity_type);
        }
        if ($request->filled('classification')) {
            match ($request->classification) {
                Tenant::CLASSIFICATION_CONTRACTING_COMPANY => $query
                    ->where('legal_type', 'organization')
                    ->where('activity_type', 'contractor'),
                Tenant::CLASSIFICATION_INDIVIDUAL_CONTRACTOR => $query
                    ->where('legal_type', 'individual')
                    ->where('activity_type', 'contractor'),
                Tenant::CLASSIFICATION_ENGINEERING_CONSULTANCY => $query
                    ->where('legal_type', 'organization')
                    ->where('activity_type', 'consultant'),
                Tenant::CLASSIFICATION_NEEDS_REVIEW => $query->where(function ($reviewQuery) {
                    $reviewQuery->whereNull('legal_type')
                        ->orWhereNull('activity_type')
                        ->orWhereRaw(
                            'NOT ((legal_type = ? AND activity_type = ?) OR (legal_type = ? AND activity_type = ?) OR (legal_type = ? AND activity_type = ?))',
                            ['organization', 'contractor', 'individual', 'contractor', 'organization', 'consultant']
                        );
                }),
            };
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('slug', 'like', $search)
                  ->orWhere('name', 'like', $search)
                  ->orWhereHas('identifiers', fn ($identifierQuery) => $identifierQuery->where('normalized_number', 'like', TenantIdentifier::normalize(trim($search, '%')).'%'));
            });
        }

        $perPage = (int) $request->input('per_page', 20);
        $tenants = $query->orderBy('created_at', 'desc')->paginate($perPage);
        $tenants->getCollection()->transform(function (Tenant $tenant): array {
            return array_merge($this->tenantPayload($tenant), $tenant->classification());
        });

        return response()->json($tenants);
    }

    /**
     * Get a single tenant's detail including settings.
     *
     * GET /api/platform/tenants/{id}
     */
    public function show(int $id): JsonResponse
    {
        $tenant = Tenant::on('mysql')
            ->with('identifiers')
            ->findOrFail($id);

        $settings = TenantSetting::on('mysql')
            ->where('tenant_id', $id)
            ->get(['key', 'value']);

        return response()->json([
            'tenant'   => $this->tenantPayload($tenant),
            'settings' => $settings,
        ]);
    }

    /**
     * Serialize tenant data for Platform Admin without exposing full CR/QID values.
     *
     * @return array<string, mixed>
     */
    private function tenantPayload(Tenant $tenant): array
    {
        $payload = $tenant->toArray();
        if (isset($payload['responsible_qid'])) {
            $payload['responsible_qid'] = $this->maskIdentifierNumber((string) $payload['responsible_qid']);
        }

        if (isset($payload['identifiers']) && is_array($payload['identifiers'])) {
            $payload['identifiers'] = array_map(
                fn (array $identifier): array => $this->maskIdentifierPayload($identifier),
                $payload['identifiers'],
            );
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function identifierPayload(TenantIdentifier $identifier): array
    {
        return $this->maskIdentifierPayload($identifier->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function maskIdentifierPayload(array $payload): array
    {
        if (isset($payload['number'])) {
            $payload['number'] = $this->maskIdentifierNumber((string) $payload['number']);
        }

        return $payload;
    }

    private function maskIdentifierNumber(string $number): string
    {
        $length = mb_strlen($number);
        if ($length <= 2) {
            return '****';
        }

        return str_repeat('*', max(4, $length - 2)).mb_substr($number, -2);
    }

    /**
     * Suspend an active tenant.
     *
     * PATCH /api/platform/tenants/{id}/suspend
     */
    public function suspend(int $id): JsonResponse
    {
        $tenant = Tenant::on('mysql')->findOrFail($id);

        if ($tenant->status !== 'active') {
            return response()->json([
                'error' => "Cannot suspend tenant with status [{$tenant->status}]. Only active tenants can be suspended.",
            ], 422);
        }

        $tenant->status = 'suspended';
        $tenant->save();

        return response()->json([
            'message' => "Tenant [{$tenant->slug}] suspended.",
            'tenant'  => ['id' => $tenant->id, 'slug' => $tenant->slug, 'status' => $tenant->status],
        ]);
    }

    /**
     * Activate a pending or suspended tenant.
     *
     * PATCH /api/platform/tenants/{id}/activate
     */
    public function activate(int $id): JsonResponse
    {
        $tenant = Tenant::on('mysql')->findOrFail($id);

        if ($tenant->status === 'active') {
            return response()->json([
                'error' => "Tenant [{$tenant->slug}] is already active.",
            ], 422);
        }

        $tenant->status = 'active';
        $tenant->save();

        return response()->json([
            'message' => "Tenant [{$tenant->slug}] activated.",
            'tenant'  => ['id' => $tenant->id, 'slug' => $tenant->slug, 'status' => $tenant->status],
        ]);
    }
}
