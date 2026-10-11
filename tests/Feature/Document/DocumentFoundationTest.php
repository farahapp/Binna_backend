<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Document\Document;
use App\Document\DocumentCategory;
use App\Document\DocumentService;
use App\Document\DocumentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DocumentFoundationTest — Feature tests for TASK-002C Documents Metadata Layer.
 *
 * Verifies:
 *  1. Schema/migrations — all document tables exist in tenant schema
 *  2. Migration files exist in correct path
 *  3. Document model persistence (CRUD lifecycle)
 *  4. DocumentVersion model persistence (immutability)
 *  5. DocumentCategory persistence
 *  6. DocumentService — create, retrieve, version, category
 *  7. storage_path remains null (metadata-only, TASK-002D boundary)
 *  8. Soft deletes
 *
 * TASK-002C — Documents Metadata Layer
 */
class DocumentFoundationTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSlug = 'doc-foundation-test';
    private string $userId     = '00000000-0000-0000-0000-000000000001';

    // =========================================================================
    // Schema / Migration Tests
    // =========================================================================

    /** @test */
    public function documents_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('documents'),
            'documents table must exist in the tenant schema'
        );
    }

    /** @test */
    public function document_categories_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('document_categories'),
            'document_categories table must exist in the tenant schema'
        );
    }

    /** @test */
    public function document_versions_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('document_versions'),
            'document_versions table must exist in the tenant schema'
        );
    }

    /** @test */
    public function document_migrations_exist_in_correct_path(): void
    {
        $this->assertFileExists(
            database_path('migrations/document/2026_09_26_000001_create_document_categories_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/document/2026_09_26_000002_create_documents_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/document/2026_09_26_000003_create_document_versions_table.php')
        );
    }

    /** @test */
    public function document_migrations_are_not_in_shared_path(): void
    {
        $this->assertFileDoesNotExist(
            database_path('migrations/shared/2026_09_26_000002_create_documents_table.php'),
            'Document migrations must NOT be in the shared migration path'
        );
    }

    // =========================================================================
    // Document Model Persistence
    // =========================================================================

    /** @test */
    public function document_can_be_created_with_required_fields(): void
    {
        $document = Document::create([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Test Document',
            'created_by'      => $this->userId,
            'status'          => 'draft',
            'current_version' => 1,
        ]);

        $this->assertNotNull($document->id);
        $this->assertEquals($this->tenantSlug, $document->tenant_slug);
        $this->assertEquals('Test Document', $document->title);
        $this->assertEquals('draft', $document->status);
        $this->assertEquals(1, $document->current_version);
    }

    /** @test */
    public function document_can_be_created_with_all_metadata_fields(): void
    {
        $document = Document::create([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Construction Site Plan',
            'description'     => 'Main site layout drawing',
            'document_number' => 'DOC-2026-001',
            'status'          => 'published',
            'created_by'      => $this->userId,
            'mime_type'       => 'application/pdf',
            'file_size'       => 204800,
            'current_version' => 1,
            'tags'            => ['site', 'drawing'],
            'storage_path'    => null, // TASK-002D
        ]);

        $this->assertNotNull($document->id);
        $this->assertEquals('Construction Site Plan', $document->title);
        $this->assertEquals('DOC-2026-001', $document->document_number);
        $this->assertEquals('published', $document->status);
        $this->assertEquals('application/pdf', $document->mime_type);
        $this->assertEquals(204800, $document->file_size);
        $this->assertNull($document->storage_path, 'storage_path must remain null (TASK-002D)');
        $this->assertIsArray($document->tags);
        $this->assertContains('site', $document->tags);
    }

    /** @test */
    public function document_storage_path_is_null_in_metadata_only_context(): void
    {
        $document = Document::create([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Metadata Only Document',
            'created_by'      => $this->userId,
            'status'          => 'draft',
            'current_version' => 1,
        ]);

        $this->assertNull($document->storage_path, 'storage_path must be null — TASK-002D boundary');
    }

    /** @test */
    public function document_can_be_retrieved_by_id(): void
    {
        $document = Document::create([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Retrievable Document',
            'created_by'      => $this->userId,
            'status'          => 'draft',
            'current_version' => 1,
        ]);

        $found = Document::find($document->id);

        $this->assertNotNull($found);
        $this->assertEquals($document->id, $found->id);
        $this->assertEquals('Retrievable Document', $found->title);
    }

    /** @test */
    public function document_status_helpers_work_correctly(): void
    {
        $draft = Document::create([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Draft Doc',
            'created_by'      => $this->userId,
            'status'          => 'draft',
            'current_version' => 1,
        ]);
        $this->assertTrue($draft->isDraft());
        $this->assertFalse($draft->isPublished());

        $published = Document::create([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Published Doc',
            'created_by'      => $this->userId,
            'status'          => 'published',
            'current_version' => 1,
        ]);
        $this->assertTrue($published->isPublished());
        $this->assertFalse($published->isDraft());
    }

    /** @test */
    public function document_supports_soft_delete(): void
    {
        $document = Document::create([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Soft Deletable',
            'created_by'      => $this->userId,
            'status'          => 'draft',
            'current_version' => 1,
        ]);

        $document->delete();

        // Soft deleted — not found in normal query
        $this->assertNull(Document::find($document->id));

        // Found with trashed
        $trashed = Document::withTrashed()->find($document->id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
    }

    // =========================================================================
    // DocumentVersion Persistence
    // =========================================================================

    /** @test */
    public function document_version_can_be_created(): void
    {
        $document = Document::create([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Versioned Document',
            'created_by'      => $this->userId,
            'status'          => 'draft',
            'current_version' => 1,
        ]);

        $version = DocumentVersion::create([
            'tenant_slug'    => $this->tenantSlug,
            'document_id'    => $document->id,
            'version_number' => 1,
            'created_by'     => $this->userId,
            'change_summary' => 'Initial version',
            'storage_path'   => null, // TASK-002D
        ]);

        $this->assertNotNull($version->id);
        $this->assertEquals(1, $version->version_number);
        $this->assertEquals('Initial version', $version->change_summary);
        $this->assertNull($version->storage_path, 'version storage_path must be null (TASK-002D)');
    }

    /** @test */
    public function document_version_update_throws_logic_exception(): void
    {
        $document = Document::create([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Immutable Test',
            'created_by'      => $this->userId,
            'status'          => 'draft',
            'current_version' => 1,
        ]);

        $version = DocumentVersion::create([
            'tenant_slug'    => $this->tenantSlug,
            'document_id'    => $document->id,
            'version_number' => 1,
            'created_by'     => $this->userId,
            'change_summary' => 'Initial',
        ]);

        $this->expectException(\LogicException::class);
        $version->update(['change_summary' => 'Modified']);
    }

    /** @test */
    public function document_version_delete_throws_logic_exception(): void
    {
        $document = Document::create([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Immutable Delete Test',
            'created_by'      => $this->userId,
            'status'          => 'draft',
            'current_version' => 1,
        ]);

        $version = DocumentVersion::create([
            'tenant_slug'    => $this->tenantSlug,
            'document_id'    => $document->id,
            'version_number' => 1,
            'created_by'     => $this->userId,
        ]);

        $this->expectException(\LogicException::class);
        $version->delete();
    }

    // =========================================================================
    // DocumentCategory Persistence
    // =========================================================================

    /** @test */
    public function document_category_can_be_created(): void
    {
        $category = DocumentCategory::create([
            'tenant_slug' => $this->tenantSlug,
            'name'        => 'Engineering Drawings',
            'description' => 'All engineering drawing documents',
            'is_active'   => true,
        ]);

        $this->assertNotNull($category->id);
        $this->assertEquals('Engineering Drawings', $category->name);
        $this->assertTrue($category->is_active);
    }

    /** @test */
    public function document_category_supports_parent_child_hierarchy(): void
    {
        $parent = DocumentCategory::create([
            'tenant_slug' => $this->tenantSlug,
            'name'        => 'Parent Category',
            'is_active'   => true,
        ]);

        $child = DocumentCategory::create([
            'tenant_slug' => $this->tenantSlug,
            'name'        => 'Child Category',
            'parent_id'   => $parent->id,
            'is_active'   => true,
        ]);

        $this->assertEquals($parent->id, $child->parent_id);
        $this->assertEquals($parent->id, $child->parent->id);
        $this->assertEquals(1, $parent->children->count());
    }

    // =========================================================================
    // DocumentService Tests
    // =========================================================================

    /** @test */
    public function document_service_creates_document_with_initial_version(): void
    {
        $service  = app(DocumentService::class);
        $document = $service->createDocument(
            tenantSlug: $this->tenantSlug,
            createdBy:  $this->userId,
            title:      'Service Created Document',
            attributes: ['description' => 'Created via service'],
        );

        $this->assertNotNull($document->id);
        $this->assertEquals('Service Created Document', $document->title);
        $this->assertEquals(1, $document->current_version);

        // Verify initial version was created
        $version = DocumentVersion::where('document_id', $document->id)
            ->where('version_number', 1)
            ->first();

        $this->assertNotNull($version, 'Initial version (v1) must be created by DocumentService');
        $this->assertEquals('Initial version', $version->change_summary);
    }

    /** @test */
    public function document_service_retrieves_document_by_id(): void
    {
        $service  = app(DocumentService::class);
        $created  = $service->createDocument($this->tenantSlug, $this->userId, 'Findable Doc');
        $found    = $service->getDocument($created->id);

        $this->assertEquals($created->id, $found->id);
        $this->assertEquals('Findable Doc', $found->title);
    }

    /** @test */
    public function document_service_throws_on_not_found(): void
    {
        $service = app(DocumentService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->getDocument('00000000-0000-0000-0000-nonexistent00');
    }

    /** @test */
    public function document_service_creates_additional_version(): void
    {
        $service  = app(DocumentService::class);
        $document = $service->createDocument($this->tenantSlug, $this->userId, 'Multi-Version Doc');

        $v2 = $service->createVersion(
            documentId: $document->id,
            createdBy:  $this->userId,
            tenantSlug: $this->tenantSlug,
            attributes: ['change_summary' => 'Revision 2'],
        );

        $this->assertEquals(2, $v2->version_number);

        // Document's current_version should be incremented
        $refreshed = Document::find($document->id);
        $this->assertEquals(2, $refreshed->current_version);
    }

    /** @test */
    public function document_service_get_version_returns_latest_when_no_version_specified(): void
    {
        $service  = app(DocumentService::class);
        $document = $service->createDocument($this->tenantSlug, $this->userId, 'Latest Version Doc');
        $service->createVersion($document->id, $this->userId, $this->tenantSlug);

        $latest = $service->getVersion($document->id);
        $this->assertEquals(2, $latest->version_number);
    }

    /** @test */
    public function document_service_creates_category(): void
    {
        $service  = app(DocumentService::class);
        $category = $service->createCategory(
            tenantSlug:  $this->tenantSlug,
            name:        'Legal Documents',
            description: 'All legal-related documents',
        );

        $this->assertNotNull($category->id);
        $this->assertEquals('Legal Documents', $category->name);
        $this->assertEquals($this->tenantSlug, $category->tenant_slug);
    }
}
