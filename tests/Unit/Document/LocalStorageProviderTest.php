<?php
declare(strict_types=1);
namespace Tests\Unit\Document;

use App\Document\Storage\LocalStorageProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocalStorageProviderTest extends TestCase
{
    private LocalStorageProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->provider = new LocalStorageProvider('local');
    }

    public function test_store_saves_file_under_tenant_scoped_path(): void
    {
        $file = UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf');
        $tenantSlug = 'acme-corp';
        $documentId = 'doc-uuid-123';

        $storagePath = $this->provider->store($file, $tenantSlug, $documentId);

        $this->assertStringContainsString('tenants/acme-corp/documents/doc-uuid-123', $storagePath);
        Storage::disk('local')->assertExists($storagePath);
    }

    public function test_store_different_tenants_use_different_paths(): void
    {
        $file1 = UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf');
        $file2 = UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf');

        $path1 = $this->provider->store($file1, 'tenant-a', 'doc-001');
        $path2 = $this->provider->store($file2, 'tenant-b', 'doc-001');

        $this->assertNotSame($path1, $path2);
        $this->assertStringContainsString('tenant-a', $path1);
        $this->assertStringContainsString('tenant-b', $path2);
    }

    public function test_exists_returns_true_for_stored_file(): void
    {
        $file = UploadedFile::fake()->create('test.pdf', 10, 'application/pdf');
        $path = $this->provider->store($file, 'tenant-x', 'doc-abc');

        $this->assertTrue($this->provider->exists($path));
    }

    public function test_exists_returns_false_for_missing_file(): void
    {
        $this->assertFalse($this->provider->exists('tenants/ghost/documents/none/missing.pdf'));
    }

    public function test_get_returns_file_contents(): void
    {
        $file = UploadedFile::fake()->create('readme.txt', 1, 'text/plain');
        $path = $this->provider->store($file, 'tenant-y', 'doc-xyz');

        $contents = $this->provider->get($path);
        $this->assertNotNull($contents);
    }

    public function test_get_returns_null_for_missing_file(): void
    {
        $result = $this->provider->get('tenants/ghost/documents/none/missing.pdf');
        $this->assertNull($result);
    }

    public function test_delete_removes_stored_file(): void
    {
        $file = UploadedFile::fake()->create('delete-me.pdf', 10, 'application/pdf');
        $path = $this->provider->store($file, 'tenant-z', 'doc-del');

        $result = $this->provider->delete($path);

        $this->assertTrue($result);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_delete_returns_false_for_missing_file(): void
    {
        $result = $this->provider->delete('tenants/ghost/documents/none/missing.pdf');
        $this->assertFalse($result);
    }
}
