<?php
declare(strict_types=1);
namespace App\Document\Storage;

use Illuminate\Http\UploadedFile;

/**
 * FileStorageProviderInterface — Provider-agnostic file storage contract.
 *
 * Physical storage technology is an OPEN ARCHITECTURAL ITEM (PHASE 7 §6.8).
 * This interface satisfies the mandatory Provider Abstraction requirement (DEC-012).
 *
 * Implementations:
 *  - LocalStorageProvider (default — Laravel local disk)
 *  - Future: S3StorageProvider, AzureBlobStorageProvider, etc.
 */
interface FileStorageProviderInterface
{
    /**
     * Store a file under a tenant-scoped path.
     *
     * @param UploadedFile $file        The file to store.
     * @param string       $tenantSlug  The tenant slug — used to scope the storage path.
     * @param string       $documentId  The document UUID — used to build the storage path.
     * @return string The storage path (internal — not exposed to consumers).
     */
    public function store(UploadedFile $file, string $tenantSlug, string $documentId): string;

    /**
     * Retrieve the raw file contents for a stored file.
     *
     * @param string $storagePath The internal storage path returned by store().
     * @return string|null The file contents, or null if not found.
     */
    public function get(string $storagePath): string|null;

    /**
     * Check whether a stored file exists.
     *
     * @param string $storagePath The internal storage path.
     * @return bool
     */
    public function exists(string $storagePath): bool;

    /**
     * Delete a stored file.
     *
     * @param string $storagePath The internal storage path.
     * @return bool True if deleted, false if not found.
     */
    public function delete(string $storagePath): bool;

    /**
     * Return the disk/provider name for logging and diagnostics.
     */
    public function providerName(): string;
}
