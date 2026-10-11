<?php
declare(strict_types=1);
namespace Tests\Unit\Document;

use App\Document\Storage\LocalStorageProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TenantIsolationStorageTest — verifies that storage paths are tenant-scoped
 * and that cross-tenant path collisions cannot occur.
 */
class TenantIsolationStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_same_document_id_different_tenants_produce_different_paths(): void
    {
        $provider = new LocalStorageProvider('local');
        $documentId = 'shared-doc-uuid';

        $file1 = UploadedFile::fake()->create('report.pdf', 20, 'application/pdf');
        $file2 = UploadedFile::fake()->create('report.pdf', 20, 'application/pdf');

        $path1 = $provider->store($file1, 'tenant-alpha', $documentId);
        $path2 = $provider->store($file2, 'tenant-beta', $documentId);

        // Paths must differ — tenant isolation at filesystem level.
        $this->assertNotSame($path1, $path2);
        $this->assertStringContainsString('tenant-alpha', $path1);
        $this->assertStringContainsString('tenant-beta', $path2);
    }

    public function test_tenant_a_file_not_accessible_via_tenant_b_path(): void
    {
        $provider = new LocalStorageProvider('local');

        $file = UploadedFile::fake()->create('secret.pdf', 10, 'application/pdf');
        $pathA = $provider->store($file, 'tenant-a', 'doc-secret');

        // Construct what tenant-b's path would be for the same document.
        $pathB = str_replace('tenant-a', 'tenant-b', $pathA);

        // Tenant B's path should not exist.
        $this->assertFalse($provider->exists($pathB));
        $this->assertNull($provider->get($pathB));
    }

    public function test_storage_path_format_is_tenant_scoped(): void
    {
        $provider = new LocalStorageProvider('local');
        $file = UploadedFile::fake()->create('drawing.pdf', 50, 'application/pdf');

        $path = $provider->store($file, 'construction-co', 'doc-12345');

        // Path must start with tenants/{tenant_slug}/documents/{documentId}
        $this->assertStringStartsWith('tenants/construction-co/documents/doc-12345', $path);
    }
}
