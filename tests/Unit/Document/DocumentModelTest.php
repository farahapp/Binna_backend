<?php
declare(strict_types=1);
namespace Tests\Unit\Document;

use App\Document\Document;
use PHPUnit\Framework\TestCase;

class DocumentModelTest extends TestCase
{
    public function test_table_is_correct(): void
    {
        $model = new Document();
        $this->assertSame('documents', $model->getTable());
    }

    public function test_connection_is_tenant(): void
    {
        $model = new Document();
        $this->assertSame('tenant', $model->getConnectionName());
    }

    public function test_fillable_contains_required_fields(): void
    {
        $model = new Document();
        $fillable = $model->getFillable();
        $this->assertContains('tenant_slug', $fillable);
        $this->assertContains('title', $fillable);
        $this->assertContains('created_by', $fillable);
        $this->assertContains('status', $fillable);
        $this->assertContains('storage_path', $fillable);
        $this->assertContains('current_version', $fillable);
    }

    public function test_is_draft_returns_true_for_draft_status(): void
    {
        $model = new Document();
        $model->status = 'draft';
        $this->assertTrue($model->isDraft());
        $this->assertFalse($model->isPublished());
    }

    public function test_is_published_returns_true_for_published_status(): void
    {
        $model = new Document();
        $model->status = 'published';
        $this->assertTrue($model->isPublished());
        $this->assertFalse($model->isDraft());
    }

    public function test_tags_cast_to_array(): void
    {
        $model = new Document();
        $casts = $model->getCasts();
        $this->assertArrayHasKey('tags', $casts);
        $this->assertSame('array', $casts['tags']);
    }
}
