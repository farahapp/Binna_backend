<?php
declare(strict_types=1);
namespace Tests\Unit\Document;

use App\Document\DocumentCategory;
use PHPUnit\Framework\TestCase;

class DocumentCategoryModelTest extends TestCase
{
    public function test_table_is_correct(): void
    {
        $model = new DocumentCategory();
        $this->assertSame('document_categories', $model->getTable());
    }

    public function test_connection_is_tenant(): void
    {
        $model = new DocumentCategory();
        $this->assertSame('tenant', $model->getConnectionName());
    }

    public function test_fillable_contains_required_fields(): void
    {
        $model = new DocumentCategory();
        $fillable = $model->getFillable();
        $this->assertContains('tenant_slug', $fillable);
        $this->assertContains('name', $fillable);
        $this->assertContains('parent_id', $fillable);
        $this->assertContains('is_active', $fillable);
    }

    public function test_is_active_cast_to_boolean(): void
    {
        $model = new DocumentCategory();
        $casts = $model->getCasts();
        $this->assertArrayHasKey('is_active', $casts);
        $this->assertSame('boolean', $casts['is_active']);
    }
}
