<?php
declare(strict_types=1);
namespace App\Document;

use Illuminate\Database\Eloquent\Collection;

/**
 * DocumentService — Public capability for document metadata management.
 *
 * Architectural constraints:
 *  - METADATA ONLY (TASK-002C). Physical file storage is TASK-002D.
 *  - Does NOT perform authentication, authorization, or tenant resolution.
 *  - Tenant identity comes from the caller via $tenantSlug.
 *  - storage_path is a nullable placeholder — not populated in TASK-002C.
 *
 * Public Capabilities (MOD-15):
 *  - createDocument()
 *  - getDocument()
 *  - createVersion()
 *  - getVersion()
 *
 * @see App\Document\Document
 * @see App\Document\DocumentVersion
 * @see App\Document\DocumentCategory
 * @see docs/BINAA2_MODULE_BOUNDARIES.md MOD-15
 */
class DocumentService
{
    // -------------------------------------------------------------------------
    // Document operations
    // -------------------------------------------------------------------------

    /**
     * Create a new Document record (metadata only).
     *
     * @param string               $tenantSlug     The tenant slug (from TenantContext).
     * @param string               $createdBy      UUID of the IAM User creating the document.
     * @param string               $title          Document title.
     * @param array<string, mixed> $attributes     Optional: category_id, description, document_number, tags, mime_type, file_size.
     * @return Document
     * @throws \InvalidArgumentException
     */
    public function createDocument(
        string $tenantSlug,
        string $createdBy,
        string $title,
        array $attributes = [],
    ): Document {
        if (empty($tenantSlug) || empty($createdBy) || empty($title)) {
            throw new \InvalidArgumentException(
                'tenantSlug, createdBy, and title are required.'
            );
        }

        $document = Document::create([
            'tenant_slug'     => $tenantSlug,
            'created_by'      => $createdBy,
            'title'           => $title,
            'category_id'     => $attributes['category_id']     ?? null,
            'description'     => $attributes['description']     ?? null,
            'document_number' => $attributes['document_number'] ?? null,
            'tags'            => $attributes['tags']            ?? null,
            'mime_type'       => $attributes['mime_type']       ?? null,
            'file_size'       => $attributes['file_size']       ?? null,
            'status'          => $attributes['status']          ?? 'draft',
            'current_version' => 1,
            'storage_path'    => null,  // TASK-002D
        ]);

        // Create the initial version record.
        DocumentVersion::create([
            'tenant_slug'    => $tenantSlug,
            'document_id'    => $document->id,
            'version_number' => 1,
            'created_by'     => $createdBy,
            'change_summary' => 'Initial version',
            'mime_type'      => $attributes['mime_type'] ?? null,
            'file_size'      => $attributes['file_size'] ?? null,
            'storage_path'   => null,  // TASK-002D
        ]);

        return $document;
    }

    /**
     * Retrieve a Document by ID.
     *
     * @param string $documentId The document UUID.
     * @return Document
     * @throws \InvalidArgumentException If not found.
     */
    public function getDocument(string $documentId): Document
    {
        $document = Document::find($documentId);

        if ($document === null) {
            throw new \InvalidArgumentException("Document [{$documentId}] not found.");
        }

        return $document;
    }

    /**
     * Create a new DocumentVersion for an existing Document.
     *
     * Increments the document's current_version counter.
     *
     * @param string               $documentId     The document UUID.
     * @param string               $createdBy      UUID of the IAM User creating the version.
     * @param string               $tenantSlug     The tenant slug.
     * @param array<string, mixed> $attributes     Optional: change_summary, mime_type, file_size.
     * @return DocumentVersion
     * @throws \InvalidArgumentException
     */
    public function createVersion(
        string $documentId,
        string $createdBy,
        string $tenantSlug,
        array $attributes = [],
    ): DocumentVersion {
        if (empty($documentId) || empty($createdBy) || empty($tenantSlug)) {
            throw new \InvalidArgumentException(
                'documentId, createdBy, and tenantSlug are required.'
            );
        }

        $document = $this->getDocument($documentId);

        $newVersionNumber = $document->current_version + 1;

        $version = DocumentVersion::create([
            'tenant_slug'    => $tenantSlug,
            'document_id'    => $document->id,
            'version_number' => $newVersionNumber,
            'created_by'     => $createdBy,
            'change_summary' => $attributes['change_summary'] ?? null,
            'mime_type'      => $attributes['mime_type']      ?? null,
            'file_size'      => $attributes['file_size']      ?? null,
            'storage_path'   => null,  // TASK-002D
        ]);

        // Update the document's current version counter.
        Document::where('id', $document->id)->update(['current_version' => $newVersionNumber]);

        return $version;
    }

    /**
     * Retrieve a specific DocumentVersion.
     *
     * @param string   $documentId     The document UUID.
     * @param int|null $versionNumber  The version number (null = latest).
     * @return DocumentVersion
     * @throws \InvalidArgumentException
     */
    public function getVersion(string $documentId, int|null $versionNumber = null): DocumentVersion
    {
        $query = DocumentVersion::where('document_id', $documentId);

        if ($versionNumber !== null) {
            $query->where('version_number', $versionNumber);
        } else {
            $query->orderByDesc('version_number');
        }

        $version = $query->first();

        if ($version === null) {
            $msg = $versionNumber !== null
                ? "DocumentVersion [{$documentId} v{$versionNumber}] not found."
                : "No versions found for Document [{$documentId}].";
            throw new \InvalidArgumentException($msg);
        }

        return $version;
    }

    // -------------------------------------------------------------------------
    // Category operations
    // -------------------------------------------------------------------------

    /**
     * Create a DocumentCategory.
     *
     * @param string      $tenantSlug   The tenant slug.
     * @param string      $name         Category name.
     * @param string|null $description  Optional description.
     * @param string|null $parentId     Optional parent category UUID.
     * @return DocumentCategory
     * @throws \InvalidArgumentException
     */
    public function createCategory(
        string $tenantSlug,
        string $name,
        string|null $description = null,
        string|null $parentId = null,
    ): DocumentCategory {
        if (empty($tenantSlug) || empty($name)) {
            throw new \InvalidArgumentException('tenantSlug and name are required.');
        }

        return DocumentCategory::create([
            'tenant_slug' => $tenantSlug,
            'name'        => $name,
            'description' => $description,
            'parent_id'   => $parentId,
            'is_active'   => true,
        ]);
    }
}
