<?php
declare(strict_types=1);
namespace Tests\Unit\Document;

use App\Document\Storage\FileStorageProviderInterface;
use App\Document\Storage\LocalStorageProvider;
use PHPUnit\Framework\TestCase;

class FileStorageProviderInterfaceTest extends TestCase
{
    public function test_local_storage_provider_implements_interface(): void
    {
        $provider = new LocalStorageProvider();
        $this->assertInstanceOf(FileStorageProviderInterface::class, $provider);
    }

    public function test_provider_name_contains_local(): void
    {
        $provider = new LocalStorageProvider();
        $this->assertStringContainsString('local', $provider->providerName());
    }

    public function test_provider_name_contains_disk_name(): void
    {
        $provider = new LocalStorageProvider('local');
        $this->assertSame('local:local', $provider->providerName());
    }
}
