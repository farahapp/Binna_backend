<?php
declare(strict_types=1);
namespace App\Document\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * LocalStorageProvider — Default FileStorageProvider using Laravel's local disk.
 *
 * Physical storage technology is an OPEN ARCHITECTURAL ITEM (PHASE 7 §6.8).
 * This provider uses the 'local' disk (storage/app/private) as the default.
 * No external provider has been architecturally selected.
 *
 * Tenant isolation: files are stored under tenants/{tenant_slug}/documents/{documentId}/
 * This ensures no cross-tenant path collisions.
 */
class LocalStorageProvider implements FileStorageProviderInterface
{
    /**
     * The Laravel disk name to use.
     * Defaults to 'local' (storage/app/private).
     */
    public function __construct(
        private readonly string $disk = 'local',
    ) {}

    /**
     * Store a file under a tenant-scoped path.
     *
     * Path format: tenants/{tenant_slug}/documents/{documentId}/{original_filename}
     * This ensures tenant isolation at the filesystem level.
     */
    public function store(UploadedFile $file, string $tenantSlug, string $documentId): string
    {
        $directory = "tenants/{$tenantSlug}/documents/{$documentId}";
        $filename = $file->getClientOriginalName();

        // Store the file and return the full path.
        $path = Storage::disk($this->disk)->putFileAs($directory, $file, $filename);

        if ($path === false) {
            throw new \RuntimeException(
                "LocalStorageProvider: failed to store file for document [{$documentId}] tenant [{$tenantSlug}]."
            );
        }

        return $path;
    }

    /**
     * Retrieve the raw file contents.
     */
    public function get(string $storagePath): string|null
    {
        if (!Storage::disk($this->disk)->exists($storagePath)) {
            return null;
        }

        return Storage::disk($this->disk)->get($storagePath);
    }

    /**
     * Check whether a stored file exists.
     */
    public function exists(string $storagePath): bool
    {
        return Storage::disk($this->disk)->exists($storagePath);
    }

    /**
     * Delete a stored file.
     */
    public function delete(string $storagePath): bool
    {
        if (!Storage::disk($this->disk)->exists($storagePath)) {
            return false;
        }

        return Storage::disk($this->disk)->delete($storagePath);
    }

    /**
     * Return the provider name.
     */
    public function providerName(): string
    {
        return "local:{$this->disk}";
    }
}
