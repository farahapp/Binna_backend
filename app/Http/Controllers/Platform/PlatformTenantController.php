<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Audit\PlatformAuditService;
use App\Foundation\Tenant;
use App\Foundation\TenantIdentifier;
use App\Foundation\TenantSetting;
use App\Foundation\PlatformUser;
use App\Registration\TenantRegistrationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    private const TENANT_LIST_FIELDS = [
        'id',
        'slug',
        'name',
        'legal_type',
        'activity_type',
        'responsible_qid',
        'qid_expires_at',
        'status',
        'created_at',
        'updated_at',
    ];

    private const TENANT_DETAIL_FIELDS = [
        'id',
        'slug',
        'name',
        'legal_type',
        'activity_type',
        'phone',
        'email',
        'responsible_name',
        'responsible_qid',
        'qid_expires_at',
        'status',
        'created_at',
        'updated_at',
    ];

    private const IDENTIFIER_RESPONSE_FIELDS = [
        'id',
        'type',
        'number',
        'expires_at',
        'validity_status',
        'created_at',
        'updated_at',
    ];

    public function __construct(
        private readonly PlatformAuditService $auditService,
    ) {}

    public function update(Request $request, int $id): JsonResponse
    {
        $this->assertDisclosureAuditAvailable($request);
        $tenant = Tenant::on('mysql')->findOrFail($id);
        $data = $this->validateTenant($request, $tenant);
        $tenant->fill(collect($data)->except('identifiers')->all())->save();

        return response()->json(['tenant' => $this->tenantPayload($tenant->fresh()->load('identifiers'), $request)]);
    }

    public function storeIdentifier(Request $request, int $id): JsonResponse
    {
        $this->assertDisclosureAuditAvailable($request);
        $tenant = Tenant::on('mysql')->findOrFail($id);
        $data = $this->validateIdentifier($request);
        $this->assertIdentifierMatchesLegalType($data['type'], $tenant->legal_type);
        $this->assertUniqueIdentifier($data['type'], $data['number']);
        $identifier = $tenant->identifiers()->create([
            ...$data,
            'validity_status' => 'unverified',
        ]);

        $revealIdentifiers = $this->authorizeIdentifierDisclosure($request, $tenant, [$identifier->type]);

        return response()->json(['identifier' => $this->identifierPayload($identifier, $revealIdentifiers)], 201);
    }

    public function updateIdentifier(Request $request, int $id, int $identifierId): JsonResponse
    {
        $this->assertDisclosureAuditAvailable($request);
        $identifier = TenantIdentifier::on('mysql')->where('tenant_id', $id)->findOrFail($identifierId);
        $data = $this->validateIdentifier($request);
        $tenant = Tenant::on('mysql')->findOrFail($id);
        $this->assertIdentifierMatchesLegalType($data['type'], $tenant->legal_type);
        $this->assertUniqueIdentifier($data['type'], $data['number'], $identifier->getKey());
        $types = $tenant->identifiers()->get(['id', 'type'])
            ->map(fn (TenantIdentifier $item): string => $item->id === $identifier->id ? $data['type'] : $item->type)
            ->all();
        $this->assertRequiredIdentifierTypes($tenant->legal_type, $tenant->activity_type, $types);
        $identifier->update([
            ...$data,
            'validity_status' => 'unverified',
        ]);

        $revealIdentifiers = $this->authorizeIdentifierDisclosure($request, $tenant, [$identifier->type]);

        return response()->json(['identifier' => $this->identifierPayload($identifier->fresh(), $revealIdentifiers)]);
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
            $requiredTypes = $this->requiredIdentifierTypes($tenant->legal_type, $tenant->activity_type);
            if ($requiredType === null || ! in_array($identifier->type, $requiredTypes, true)) {
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

        $tenant = Tenant::on('mysql')->findOrFail($id);
        $revealIdentifiers = $this->authorizeIdentifierDisclosure($request, $tenant, [$result->type]);

        return response()->json(['identifier' => $this->identifierPayload($result, $revealIdentifiers)]);
    }

    public function destroyIdentifier(int $id, int $identifierId): JsonResponse
    {
        $identifier = TenantIdentifier::on('mysql')->where('tenant_id', $id)->findOrFail($identifierId);
        $tenant = Tenant::on('mysql')->findOrFail($id);
        $requiredTypes = $this->requiredIdentifierTypes($tenant->legal_type, $tenant->activity_type);

        if (in_array($identifier->type, $requiredTypes, true)) {
            $count = TenantIdentifier::on('mysql')
                ->where('tenant_id', $id)
                ->where('type', $identifier->type)
                ->count();
            if ($count <= 1) {
                abort(422, 'The tenant must retain its required registration identifiers.');
            }
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
            'activity_type' => ['nullable', Rule::in(['contractor', 'consultant', 'both', 'owner'])],
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
        $types = collect($identifiers)->pluck('type')->all();
        $this->assertRequiredIdentifierTypes($legalType, $data['activity_type'] ?? $tenant?->activity_type, $types);
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

    /**
     * @return array<int, string>
     */
    private function requiredIdentifierTypes(?string $legalType, ?string $activityType): array
    {
        $loginType = TenantIdentifier::loginTypeForLegalType($legalType);
        if ($loginType === null) {
            return [];
        }
        $required = [$loginType];
        if (
            $legalType === 'organization'
            && in_array($activityType, ['consultant', 'both'], true)
        ) {
            $required[] = 'professional_license';
        }

        return $required;
    }

    /**
     * @param array<int, string> $types
     */
    private function assertRequiredIdentifierTypes(?string $legalType, ?string $activityType, array $types): void
    {
        foreach ($this->requiredIdentifierTypes($legalType, $activityType) as $requiredType) {
            if (! in_array($requiredType, $types, true)) {
                abort(422, $requiredType === 'professional_license'
                    ? 'An engineering consultancy requires a professional license in addition to its CR.'
                    : 'An organization requires a CR and an individual requires a QID identifier.');
            }
        }
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
            'activity_type' => ['sometimes', 'nullable', Rule::in(['contractor', 'consultant', 'both', 'owner'])],
            'classification' => ['sometimes', 'nullable', Rule::in([
                Tenant::CLASSIFICATION_CONTRACTING_COMPANY,
                Tenant::CLASSIFICATION_INDIVIDUAL_CONTRACTOR,
                Tenant::CLASSIFICATION_ENGINEERING_CONSULTANCY,
                Tenant::CLASSIFICATION_PROPERTY_OWNER,
                Tenant::CLASSIFICATION_NEEDS_REVIEW,
            ])],
            'group' => ['sometimes', 'nullable', Rule::in(['contractors', 'consultants', 'owners'])],
            'search'   => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Tenant::on('mysql')
            ->select(self::TENANT_LIST_FIELDS)
            ->with(['identifiers:id,tenant_id,type,number,expires_at,validity_status,created_at,updated_at']);

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
                Tenant::CLASSIFICATION_PROPERTY_OWNER => $query
                    ->where('activity_type', 'owner')
                    ->whereIn('legal_type', ['individual', 'organization']),
                Tenant::CLASSIFICATION_NEEDS_REVIEW => $query->where(function ($reviewQuery) {
                    $reviewQuery->whereNull('legal_type')
                        ->orWhereNull('activity_type')
                        ->orWhereRaw(
                            'NOT ('
                            .' (legal_type = ? AND activity_type = ?)'
                            .' OR (legal_type = ? AND activity_type = ?)'
                            .' OR (legal_type = ? AND activity_type = ?)'
                            .' OR (legal_type = ? AND activity_type = ?)'
                            .' OR (legal_type = ? AND activity_type = ?)'
                            .')',
                            [
                                'organization', 'contractor',
                                'individual', 'contractor',
                                'organization', 'consultant',
                                'organization', 'owner',
                                'individual', 'owner',
                            ]
                        );
                }),
            };
        }
        if ($request->filled('group') && ! $request->filled('classification')) {
            match ($request->group) {
                'contractors' => $query
                    ->where('activity_type', 'contractor')
                    ->whereIn('legal_type', ['individual', 'organization']),
                'consultants' => $query
                    ->where('legal_type', 'organization')
                    ->where('activity_type', 'consultant'),
                'owners' => $query
                    ->where('activity_type', 'owner')
                    ->whereIn('legal_type', ['individual', 'organization']),
            };
        }

        if ($request->filled('search')) {
            $searchTerm = trim((string) $request->search);
            $search = '%'.$searchTerm.'%';
            $normalizedSearch = TenantIdentifier::normalize($searchTerm);
            $query->where(function ($q) use ($search, $normalizedSearch) {
                $q->where('slug', 'like', $search)
                    ->orWhere('name', 'like', $search)
                    ->orWhere('responsible_qid', 'like', $search);

                if ($normalizedSearch !== '') {
                    $q->orWhereHas(
                        'identifiers',
                        fn ($identifierQuery) => $identifierQuery->where('normalized_number', 'like', $normalizedSearch.'%')
                    );
                }
            });
        }

        $perPage = (int) $request->input('per_page', 20);
        $tenants = $query->orderBy('created_at', 'desc')->paginate($perPage);
        $tenants->getCollection()->transform(function (Tenant $tenant) use ($request): array {
            return array_merge($this->tenantListPayload($tenant, $request), $tenant->classification());
        });

        return response()->json($tenants);
    }

    /**
     * Real aggregate counts for Platform Admin entity directory (no mock numbers).
     *
     * GET /api/platform/tenants/summary
     */
    public function summary(): JsonResponse
    {
        $tenants = Tenant::on('mysql')->get(['id', 'legal_type', 'activity_type', 'status']);
        $byClassification = [
            Tenant::CLASSIFICATION_CONTRACTING_COMPANY => 0,
            Tenant::CLASSIFICATION_INDIVIDUAL_CONTRACTOR => 0,
            Tenant::CLASSIFICATION_ENGINEERING_CONSULTANCY => 0,
            Tenant::CLASSIFICATION_PROPERTY_OWNER => 0,
            Tenant::CLASSIFICATION_NEEDS_REVIEW => 0,
        ];
        $byStatus = ['pending' => 0, 'active' => 0, 'suspended' => 0];
        foreach ($tenants as $tenant) {
            $class = $tenant->classification()['classification'];
            if (! array_key_exists($class, $byClassification)) {
                $class = Tenant::CLASSIFICATION_NEEDS_REVIEW;
            }
            $byClassification[$class]++;
            if (array_key_exists((string) $tenant->status, $byStatus)) {
                $byStatus[$tenant->status]++;
            }
        }

        $registrationByStatus = [];
        if (Schema::connection('mysql')->hasTable('tenant_registration_requests')) {
            $registrationByStatus = TenantRegistrationRequest::on('mysql')
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->map(fn ($count) => (int) $count)
                ->all();
        }

        return response()->json([
            'tenants' => [
                'total' => $tenants->count(),
                'by_status' => $byStatus,
                'by_classification' => $byClassification,
                'contractors_total' => $byClassification[Tenant::CLASSIFICATION_CONTRACTING_COMPANY]
                    + $byClassification[Tenant::CLASSIFICATION_INDIVIDUAL_CONTRACTOR],
                'consultants_total' => $byClassification[Tenant::CLASSIFICATION_ENGINEERING_CONSULTANCY],
                'owners_total' => $byClassification[Tenant::CLASSIFICATION_PROPERTY_OWNER],
            ],
            'registrations' => [
                'by_status' => $registrationByStatus,
            ],
            'architecture' => [
                'owner_model' => 'client',
                'owner_tenant_product' => false,
            ],
        ]);
    }

    /**
     * Get a single tenant's detail including settings.
     *
     * GET /api/platform/tenants/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::on('mysql')
            ->select(self::TENANT_DETAIL_FIELDS)
            ->with(['identifiers:id,tenant_id,type,number,expires_at,validity_status,created_at,updated_at'])
            ->findOrFail($id);

        $settings = TenantSetting::on('mysql')
            ->where('tenant_id', $id)
            ->get(['key', 'value']);

        return response()->json([
            'tenant'   => $this->tenantPayload($tenant, $request),
            'settings' => $settings,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantPayload(Tenant $tenant, Request $request): array
    {
        $payload = $tenant->only(self::TENANT_DETAIL_FIELDS);
        $identifierTypes = $tenant->relationLoaded('identifiers')
            ? $tenant->identifiers->pluck('type')->all()
            : [];
        if (! empty($payload['responsible_qid'])) {
            $identifierTypes[] = 'responsible_qid';
        }
        $revealIdentifiers = $this->authorizeIdentifierDisclosure($request, $tenant, $identifierTypes);

        if (! $revealIdentifiers && isset($payload['responsible_qid'])) {
            $payload['responsible_qid'] = $this->maskIdentifierNumber((string) $payload['responsible_qid']);
        }

        if ($tenant->relationLoaded('identifiers')) {
            $payload['identifiers'] = $tenant->identifiers
                ->map(fn (TenantIdentifier $identifier): array => $this->identifierPayload($identifier, $revealIdentifiers))
                ->all();
        }

        return array_merge($payload, $tenant->classification());
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantListPayload(Tenant $tenant, Request $request): array
    {
        $payload = $tenant->only(self::TENANT_LIST_FIELDS);
        $identifierTypes = $tenant->relationLoaded('identifiers')
            ? $tenant->identifiers->pluck('type')->all()
            : [];
        if (! empty($payload['responsible_qid'])) {
            $identifierTypes[] = 'responsible_qid';
        }
        $revealIdentifiers = $this->authorizeIdentifierDisclosure($request, $tenant, $identifierTypes);

        if (! $revealIdentifiers && isset($payload['responsible_qid'])) {
            $payload['responsible_qid'] = $this->maskIdentifierNumber((string) $payload['responsible_qid']);
        }

        if ($tenant->relationLoaded('identifiers')) {
            $payload['identifiers'] = $tenant->identifiers
                ->map(fn (TenantIdentifier $identifier): array => $this->identifierPayload($identifier, $revealIdentifiers))
                ->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function identifierPayload(TenantIdentifier $identifier, bool $revealNumber = false): array
    {
        $payload = $identifier->only(self::IDENTIFIER_RESPONSE_FIELDS);
        if (! $revealNumber && isset($payload['number'])) {
            $payload['number'] = $this->maskIdentifierNumber((string) $payload['number']);
        }

        return $payload;
    }

    /**
     * Sensitive identifiers are disclosed only when the explicit permission and audit table exist.
     *
     * @param array<int, string> $identifierTypes
     */
    private function authorizeIdentifierDisclosure(Request $request, Tenant $tenant, array $identifierTypes): bool
    {
        $actor = $request->user();
        if (! $actor instanceof PlatformUser || ! $this->hasFullIdentifierPermission($request, $actor)) {
            return false;
        }

        $identifierTypes = array_values(array_unique($identifierTypes));
        if ($identifierTypes === []) {
            return true;
        }

        if (! Schema::connection('mysql')->hasTable('platform_audit_entries')) {
            abort(503, 'Sensitive identifier disclosure is unavailable because audit logging is not configured.');
        }

        $this->auditService->record(
            actor: $actor,
            event: 'platform.tenant.identifiers.viewed',
            subjectType: Tenant::class,
            subjectId: $tenant->getKey(),
            after: ['identifier_types' => $identifierTypes],
        );

        return true;
    }

    private function assertDisclosureAuditAvailable(Request $request): void
    {
        $actor = $request->user();
        if (
            $actor instanceof PlatformUser
            && $this->hasFullIdentifierPermission($request, $actor)
            && ! Schema::connection('mysql')->hasTable('platform_audit_entries')
        ) {
            abort(503, 'Sensitive identifier disclosure is unavailable because audit logging is not configured.');
        }
    }

    private function hasFullIdentifierPermission(Request $request, PlatformUser $actor): bool
    {
        if (! $request->attributes->has('platform_full_identifier_permission')) {
            $request->attributes->set(
                'platform_full_identifier_permission',
                $actor->hasPlatformPermission('platform.tenants.identifiers.view'),
            );
        }

        return $request->attributes->get('platform_full_identifier_permission') === true;
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
     * Reactivate a suspended tenant. Pending tenants require operator provisioning.
     *
     * PATCH /api/platform/tenants/{id}/activate
     */
    public function activate(int $id): JsonResponse
    {
        $tenant = Tenant::on('mysql')->findOrFail($id);

        if ($tenant->status !== 'suspended') {
            return response()->json([
                'error' => 'Only a fully provisioned suspended tenant can be reactivated.',
            ], 409);
        }

        $registration = Schema::connection('mysql')->hasTable('tenant_registration_requests')
            ? TenantRegistrationRequest::on('mysql')->where('tenant_id', $tenant->id)->first()
            : null;
        if ($registration && $registration->status !== 'provisioned') {
            return response()->json(['error' => 'The linked registration has not completed provisioning.'], 409);
        }

        $tenant->status = 'active';
        $tenant->save();

        return response()->json([
            'message' => "Tenant [{$tenant->slug}] activated.",
            'tenant'  => ['id' => $tenant->id, 'slug' => $tenant->slug, 'status' => $tenant->status],
        ]);
    }
}
