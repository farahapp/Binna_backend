<?php
declare(strict_types=1);
namespace Tests\Unit\Document;

use App\Document\DocumentStorageService;
use App\Document\Storage\FileStorageProviderInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DocumentStorageServiceTest extends TestCase
{
    public function test_service_instantiates_with_provider(): void
    {
        $provider = $this->createMock(FileStorageProviderInterface::class);
        $provider->method('providerName')->willReturn('mock:test');

        $service = new DocumentStorageService($provider);
        $this->assertSame('mock:test', $service->providerName());
    }

    public function test_attach_file_throws_on_missing_document(): void
    {
        $this->expectException(\Throwable::class);

        $provider = $this->createMock(FileStorageProviderInterface::class);
        $service = new DocumentStorageService($provider);

        $file = $this->createMock(\Illuminate\Http\UploadedFile::class);
        $service->attachFile('non-existent-uuid', 'tenant-slug', $file);
    }

    public function test_get_file_contents_throws_on_missing_document(): void
    {
        $this->expectException(\Throwable::class);

        $provider = $this->createMock(FileStorageProviderInterface::class);
        $service = new DocumentStorageService($provider);

        $service->getFileContents('non-existent-uuid', 'tenant-slug');
    }

    public function test_file_exists_throws_on_missing_document(): void
    {
        $this->expectException(\Throwable::class);

        $provider = $this->createMock(FileStorageProviderInterface::class);
        $service = new DocumentStorageService($provider);

        $service->fileExists('non-existent-uuid', 'tenant-slug');
    }

    public function test_soft_delete_throws_on_missing_document(): void
    {
        $this->expectException(\Throwable::class);

        $provider = $this->createMock(FileStorageProviderInterface::class);
        $service = new DocumentStorageService($provider);

        $service->softDeleteFile('non-existent-uuid', 'tenant-slug');
    }
}
