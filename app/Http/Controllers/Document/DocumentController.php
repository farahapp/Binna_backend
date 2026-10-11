<?php

declare(strict_types=1);

namespace App\Http\Controllers\Document;

use App\Document\Document;
use App\Document\DocumentCategory;
use App\Document\DocumentService;
use App\Document\DocumentStorageService;
use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DocumentController — Document Management HTTP API Layer.
 *
 * Architectural constraints (A1 MOD-15 / TASK-002C):
 *  - Delegates ALL business logic to DocumentService.
 *  - Does NOT access Document models directly (except tenant-scoped listing).
 *  - METADATA ONLY — no physical file storage (TASK-002D boundary).
 *  - Tenant context resolved by ResolveTenantContext middleware (OD-001).
 *  - All endpoints protected by auth:sanctum (TASK-001B).
 *  - storage_path is NOT exposed in responses (internal reference only).
 *
 * TASK-002C — Documents Metadata Layer
 */
class DocumentController extends Controller
{
    // Allowed MIME types (F-5 decision — approved 2026-10-09)
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/png',
        'image/jpeg',
    ];

    // Allowed extensions (F-5 decision)
    private const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'xlsx', 'png', 'jpg', 'jpeg'];

    // Maximum file size in bytes: 20 MB (F-5 decision)
    private const MAX_FILE_SIZE_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly DocumentService $documentService,
        private readonly DocumentStorageService $documentStorageService,
    ) {}

    // =========================================================================
    // DOCUMENTS
    // =========================================================================

    /**
     * POST /api/v1/documents
     * Create a new document record (metadata only).
     */
    public function createDocument(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'           => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'category_id'     => ['nullable', 'string', 'uuid'],
            'status'          => ['nullable', 'string', 'in:draft,published,archived'],
            'mime_type'       => ['nullable', 'string', 'max:100'],
            'file_size'       => ['nullable', 'integer', 'min:0'],
            'tags'            => ['nullable', 'array'],
            'tags.*'          => ['string'],
        ]);

        $tenantContext = app(TenantContext::class);
        $actorId       = $request->user()?->id;

        $document = $this->documentService->createDocument(
            tenantSlug: $tenantContext->slug,
            createdBy:  $actorId,
            title:      $validated['title'],
            attributes: array_filter([
                'description'     => $validated['description']     ?? null,
                'document_number' => $validated['document_number'] ?? null,
                'category_id'     => $validated['category_id']     ?? null,
                'status'          => $validated['status']          ?? null,
                'mime_type'       => $validated['mime_type']       ?? null,
                'file_size'       => $validated['file_size']       ?? null,
                'tags'            => $validated['tags']            ?? null,
            ], fn($v) => $v !== null),
        );

        return response()->json($this->formatDocument($document), 201);
    }

    /**
     * GET /api/v1/documents
     * List all documents for the active tenant.
     */
    public function listDocuments(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);

        $documents = Document::where('tenant_slug', $tenantContext->slug)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(
            $documents->map(fn($d) => $this->formatDocument($d))->values()->all(),
            200
        );
    }

    /**
     * GET /api/v1/documents/{id}
     * Retrieve a single document by ID (tenant-scoped).
     */
    public function getDocument(Request $request, string $id): JsonResponse
    {
        try {
            $document = $this->documentService->getDocument($id);
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        // Tenant isolation — reject cross-tenant access.
        $tenantContext = app(TenantContext::class);
        if ($document->tenant_slug !== $tenantContext->slug) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        return response()->json($this->formatDocument($document), 200);
    }

    /**
     * PATCH /api/v1/documents/{id}
     * Update document metadata (tenant-scoped).
     */
    public function updateDocument(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'title'           => ['sometimes', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'category_id'     => ['nullable', 'string', 'uuid'],
            'status'          => ['sometimes', 'string', 'in:draft,published,archived'],
            'mime_type'       => ['nullable', 'string', 'max:100'],
            'tags'            => ['nullable', 'array'],
            'tags.*'          => ['string'],
        ]);

        try {
            $document = $this->documentService->getDocument($id);
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        // Tenant isolation — reject cross-tenant access.
        $tenantContext = app(TenantContext::class);
        if ($document->tenant_slug !== $tenantContext->slug) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        // Update only provided fields.
        $updateFields = array_intersect_key($validated, array_flip([
            'title', 'description', 'document_number', 'category_id', 'status', 'mime_type', 'tags',
        ]));

        if (!empty($updateFields)) {
            Document::where('id', $id)->update($updateFields);
            $document = $document->fresh();
        }

        return response()->json($this->formatDocument($document), 200);
    }

    /**
     * DELETE /api/v1/documents/{id}
     * Soft-delete a document (tenant-scoped).
     */
    public function deleteDocument(Request $request, string $id): JsonResponse
    {
        try {
            $document = $this->documentService->getDocument($id);
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        // Tenant isolation — reject cross-tenant access.
        $tenantContext = app(TenantContext::class);
        if ($document->tenant_slug !== $tenantContext->slug) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        $document->delete();

        return response()->json(['message' => 'Document deleted.'], 200);
    }

    // =========================================================================
    // VERSIONS
    // =========================================================================

    /**
     * POST /api/v1/documents/{id}/versions
     * Add a new version to an existing document (metadata only).
     */
    public function createVersion(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'change_summary' => ['nullable', 'string', 'max:500'],
            'mime_type'      => ['nullable', 'string', 'max:100'],
            'file_size'      => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $document = $this->documentService->getDocument($id);
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        // Tenant isolation.
        $tenantContext = app(TenantContext::class);
        if ($document->tenant_slug !== $tenantContext->slug) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        $actorId = $request->user()?->id;

        try {
            $version = $this->documentService->createVersion(
                documentId: $id,
                createdBy:  $actorId,
                tenantSlug: $tenantContext->slug,
                attributes: array_filter([
                    'change_summary' => $validated['change_summary'] ?? null,
                    'mime_type'      => $validated['mime_type']      ?? null,
                    'file_size'      => $validated['file_size']      ?? null,
                ], fn($v) => $v !== null),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($version->toArray(), 201);
    }

    /**
     * GET /api/v1/documents/{id}/versions/{version?}
     * Retrieve a specific version (or latest) of a document.
     */
    public function getVersion(Request $request, string $id, ?int $version = null): JsonResponse
    {
        try {
            $document = $this->documentService->getDocument($id);
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        // Tenant isolation.
        $tenantContext = app(TenantContext::class);
        if ($document->tenant_slug !== $tenantContext->slug) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        try {
            $versionRecord = $this->documentService->getVersion($id, $version);
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'Version not found.'], 404);
        }

        return response()->json($versionRecord->toArray(), 200);
    }

    // =========================================================================
    // FILE STORAGE — TASK-002D (F-5 APPROVED)
    // =========================================================================

    /**
     * POST /api/v1/documents/{id}/upload
     *
     * Upload a physical file and attach it to an existing Document.
     *
     * Security:
     *  - auth:sanctum required (middleware)
     *  - Tenant isolation enforced via DocumentStorageService
     *  - Server-side size validation: max 20 MB (F-5)
     *  - Server-side MIME/extension validation (F-5)
     *  - storage_path never exposed in response
     *
     * TASK-002D — Document Storage Integration
     */
    public function uploadFile(Request $request, string $id): JsonResponse
    {
        // Validate file presence and basic constraints.
        $request->validate([
            'file' => ['required', 'file'],
        ]);

        $file = $request->file('file');

        if ($file === null || !$file->isValid()) {
            return response()->json(['message' => 'No valid file uploaded.'], 422);
        }

        // Server-side size validation (F-5: max 20 MB).
        if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            return response()->json([
                'message' => 'File exceeds the maximum allowed size of 20 MB.',
            ], 422);
        }

        // Server-side MIME type validation (F-5).
        $mimeType = $file->getMimeType() ?? '';
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return response()->json([
                'message' => 'File type not allowed. Allowed types: PDF, DOCX, XLSX, PNG, JPG, JPEG.',
            ], 422);
        }

        // Server-side extension validation (F-5) — defence-in-depth.
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return response()->json([
                'message' => 'File extension not allowed. Allowed: pdf, docx, xlsx, png, jpg, jpeg.',
            ], 422);
        }

        $tenantContext = app(TenantContext::class);

        try {
            $document = $this->documentStorageService->attachFile(
                documentId: $id,
                tenantSlug:  $tenantContext->slug,
                file:        $file,
            );
        } catch (\InvalidArgumentException $e) {
            // Covers: document not found, tenant mismatch (cross-tenant attempt).
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'File storage failed. Please try again.'], 500);
        }

        return response()->json($this->formatDocument($document), 200);
    }

    /**
     * GET /api/v1/documents/{id}/download
     *
     * Stream the physical file for an existing Document.
     *
     * Security:
     *  - auth:sanctum required (middleware)
     *  - Tenant isolation enforced via DocumentStorageService
     *  - File served as streamed response — never via public URL
     *  - storage_path never exposed
     *
     * TASK-002D — Document Storage Integration
     */
    public function downloadFile(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $tenantContext = app(TenantContext::class);

        // Resolve document and enforce tenant isolation.
        try {
            $document = $this->documentService->getDocument($id);
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        if ($document->tenant_slug !== $tenantContext->slug) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        // Ensure a file has been attached.
        if (empty($document->storage_path)) {
            return response()->json(['message' => 'No file attached to this document.'], 404);
        }

        // Retrieve file contents via storage service (enforces tenant isolation again).
        try {
            $contents = $this->documentStorageService->getFileContents(
                documentId: $id,
                tenantSlug:  $tenantContext->slug,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'File not found in storage.'], 404);
        }

        // Determine filename for Content-Disposition.
        $filename = basename($document->storage_path);
        $mimeType = $document->mime_type ?? 'application/octet-stream';

        // Stream the file contents — never expose storage_path or direct URL.
        return response()->streamDownload(
            function () use ($contents): void {
                echo $contents;
            },
            $filename,
            [
                'Content-Type'        => $mimeType,
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control'       => 'no-store, no-cache, must-revalidate',
                'Pragma'              => 'no-cache',
            ],
        );
    }

    // =========================================================================
    // CATEGORIES
    // =========================================================================

    /**
     * POST /api/v1/document-categories
     * Create a document category.
     */
    public function createCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'parent_id'   => ['nullable', 'string', 'uuid'],
        ]);

        $tenantContext = app(TenantContext::class);

        try {
            $category = $this->documentService->createCategory(
                tenantSlug:  $tenantContext->slug,
                name:        $validated['name'],
                description: $validated['description'] ?? null,
                parentId:    $validated['parent_id']   ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($category->toArray(), 201);
    }

    /**
     * GET /api/v1/document-categories
     * List document categories for the active tenant.
     */
    public function listCategories(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);

        $categories = DocumentCategory::where('tenant_slug', $tenantContext->slug)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json($categories->toArray(), 200);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Format a Document for API response.
     * Excludes internal storage_path (TASK-002D boundary).
     */
    private function formatDocument(Document $document): array
    {
        return [
            'id'              => $document->id,
            'tenant_slug'     => $document->tenant_slug,
            'title'           => $document->title,
            'description'     => $document->description,
            'document_number' => $document->document_number,
            'status'          => $document->status,
            'category_id'     => $document->category_id,
            'mime_type'       => $document->mime_type,
            'file_size'       => $document->file_size,
            'current_version' => $document->current_version,
            'tags'            => $document->tags,
            'created_by'      => $document->created_by,
            'created_at'      => $document->created_at,
            'updated_at'      => $document->updated_at,
            // storage_path intentionally excluded — TASK-002D boundary
        ];
    }
}
