<?php
declare(strict_types=1);
namespace Tests\Unit\Document;

use App\Document\DocumentVersion;
use LogicException;
use PHPUnit\Framework\TestCase;

class DocumentVersionModelTest extends TestCase
{
    public function test_table_is_correct(): void
    {
        $model = new DocumentVersion();
        $this->assertSame('document_versions', $model->getTable());
    }

    public function test_connection_is_tenant(): void
    {
        $model = new DocumentVersion();
        $this->assertSame('tenant', $model->getConnectionName());
    }

    public function test_fillable_contains_required_fields(): void
    {
        $model = new DocumentVersion();
        $fillable = $model->getFillable();
        $this->assertContains('tenant_slug', $fillable);
        $this->assertContains('document_id', $fillable);
        $this->assertContains('version_number', $fillable);
        $this->assertContains('created_by', $fillable);
        $this->assertContains('storage_path', $fillable);
    }

    public function test_version_is_immutable_update_throws(): void
    {
        $this->expectException(LogicException::class);
        $model = new DocumentVersion();
        $model->update(['change_summary' => 'changed']);
    }

    public function test_version_is_immutable_delete_throws(): void
    {
        $this->expectException(LogicException::class);
        $model = new DocumentVersion();
        $model->delete();
    }

    public function test_version_number_cast_to_integer(): void
    {
        $model = new DocumentVersion();
        $casts = $model->getCasts();
        $this->assertArrayHasKey('version_number', $casts);
        $this->assertSame('integer', $casts['version_number']);
    }
}
