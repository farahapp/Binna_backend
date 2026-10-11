<?php
declare(strict_types=1);
namespace App\Document;

use App\Document\Storage\FileStorageProviderInterface;
use Illuminate\Http\UploadedFile;

/**
 * DocumentStorageService — Orchestrates physical file storage for Documents.
 *
 * Architectural constraints (PHASE 7 §6):
 *  - Tenant isolation: files stored under tenants/{tenant_slug}/documents/{uuid}
 *  - Files served through application — not via direct storage URLs
 *  - No cross-tenant file access
 *  - Each upload creates/updates a DocumentVersion record
 *  - storage_path is internal — not exposed to consumers
 *  - Physical storage technology is OPEN ARCHITECTURAL ITEM (§6.8)
 *    → LocalStorageProvider is the default (no external provider selected)
 *
 * Audit integration (§6.10): file operations SHOULD be recorded in Audit Module.
 * Audit integration is NOT implemented in TASK-002D — it is a cross-cutting
 * concern deferred to the Audit integration phase.
 *
 * @see App\Document\Storage\FileStorageProviderInterface
 * @see App\Document\Document
 * @see App\Document\DocumentVersion
 */
class DocumentStorageService
{
    public function __construct(
        private readonly FileStorageProviderInterface $storageProvider,
    ) {}

    /**
     * Attach a physical file to an existing Document.
     *
     * Stores the file via the provider, updates the Document metadata,
     * and updates the current DocumentVersion's storage_path.
     *
     * @param string       $documentId  The document UUID.
     * @param string       $tenantSlug  The tenant slug — enforces tenant isolation.
     * @param UploadedFile $file        The uploaded file.
     * @return Document The updated Document.
     * @throws \InvalidArgumentException If document not found or tenant mismatch.
     * @throws \RuntimeException         If storage fails.
     */
    public function attachFile(
        string $documentId,
        string $tenantSlug,
        UploadedFile $file,
    ): Document {
        $document = Document::find($documentId);

        if ($document === null) {
            throw new \InvalidArgumentException("Document [{$documentId}] not found.");
        }

        // Enforce tenant isolation — document must belong to the requesting tenant.
        if ($document->tenant_slug !== $tenantSlug) {
            throw new \InvalidArgumentException(
                "Document [{$documentId}] does not belong to tenant [{$tenantSlug}]."
            );
        }

        // Store the file via the provider (tenant-scoped path).
        $storagePath = $this->storageProvider->store($file, $tenantSlug, $documentId);

        // Update Document metadata (bypass immutability — Document is mutable).
        Document::where('id', $documentId)->update([
            'storage_path' => $storagePath,
            'mime_type'    => $file->getMimeType(),
            'file_size'    => $file->getSize(),
        ]);

        // Update the current DocumentVersion's storage_path.
        // DocumentVersion is immutable via model methods, so use query builder.
        DocumentVersion::where('document_id', $documentId)
            ->where('version_number', $document->current_version)
            ->update([
                'storage_path' => $storagePath,
                'mime_type'    => $file->getMimeType(),
                'file_size'    => $file->getSize(),
            ]);

        return $document->fresh();
    }

    /**
     * Retrieve the raw file contents for a Document.
     *
     * Enforces tenant isolation — the document must belong to the requesting tenant.
     *
     * @param string $documentId  The document UUID.
     * @param string $tenantSlug  The tenant slug — enforces tenant isolation.
     * @return string The file contents.
     * @throws \InvalidArgumentException If document not found, tenant mismatch, or no file attached.
     */
    public function getFileContents(string $documentId, string $tenantSlug): string
    {
        $document = Document::find($documentId);

        if ($document === null) {
            throw new \InvalidArgumentException("Document [{$documentId}] not found.");
        }

        // Enforce tenant isolation.
        if ($document->tenant_slug !== $tenantSlug) {
            throw new \InvalidArgumentException(
                "Document [{$documentId}] does not belong to tenant [{$tenantSlug}]."
            );
        }

        if (empty($document->storage_path)) {
            throw new \InvalidArgumentException(
                "Document [{$documentId}] has no physical file attached."
            );
        }

        $contents = $this->storageProvider->get($document->storage_path);

        if ($contents === null) {
            throw new \RuntimeException(
                "File for Document [{$documentId}] not found in storage."
            );
        }

        return $contents;
    }

    /**
     * Check whether a Document has a physical file attached and accessible.
     *
     * @param string $documentId  The document UUID.
     * @param string $tenantSlug  The tenant slug — enforces tenant isolation.
     * @return bool
     * @throws \InvalidArgumentException If document not found or tenant mismatch.
     */
    public function fileExists(string $documentId, string $tenantSlug): bool
    {
        $document = Document::find($documentId);

        if ($document === null) {
            throw new \InvalidArgumentException("Document [{$documentId}] not found.");
        }

        if ($document->tenant_slug !== $tenantSlug) {
            throw new \InvalidArgumentException(
                "Document [{$documentId}] does not belong to tenant [{$tenantSlug}]."
            );
        }

        if (empty($document->storage_path)) {
            return false;
        }

        return $this->storageProvider->exists($document->storage_path);
    }

    /**
     * Soft-delete the physical file for a Document.
     *
     * Per architecture §6.11: files are soft-deleted — physical deletion is delayed.
     * This method marks the storage_path as null in the metadata (logical soft-delete).
     * The physical file is NOT immediately deleted from storage.
     *
     * @param string $documentId  The document UUID.
     * @param string $tenantSlug  The tenant slug — enforces tenant isolation.
     * @return bool True if the file reference was cleared.
     * @throws \InvalidArgumentException If document not found or tenant mismatch.
     */
    public function softDeleteFile(string $documentId, string $tenantSlug): bool
    {
        $document = Document::find($documentId);

        if ($document === null) {
            throw new \InvalidArgumentException("Document [{$documentId}] not found.");
        }

        if ($document->tenant_slug !== $tenantSlug) {
            throw new \InvalidArgumentException(
                "Document [{$documentId}] does not belong to tenant [{$tenantSlug}]."
            );
        }

        // Clear the storage_path reference (logical soft-delete).
        // Physical file is retained in storage per §6.11.
        Document::where('id', $documentId)->update(['storage_path' => null]);

        return true;
    }

    /**
     * Return the storage provider name (for diagnostics).
     */
    public function providerName(): string
    {
        return $this->storageProvider->providerName();
    }
}
