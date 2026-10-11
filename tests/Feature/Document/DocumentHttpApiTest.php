<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Document\Document;
use App\Document\DocumentCategory;
use App\IAM\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * DocumentHttpApiTest — Feature tests for TASK-002C Document HTTP API Layer.
 *
 * Tests:
 *  - POST   /api/v1/documents             (create metadata + validation)
 *  - GET    /api/v1/documents             (list)
 *  - GET    /api/v1/documents/{id}        (show + not found)
 *  - PATCH  /api/v1/documents/{id}        (update metadata)
 *  - DELETE /api/v1/documents/{id}        (soft delete)
 *  - POST   /api/v1/documents/{id}/versions (create version)
 *  - GET    /api/v1/documents/{id}/versions/{version?} (get version)
 *  - POST   /api/v1/document-categories   (create category)
 *  - GET    /api/v1/document-categories   (list categories)
 *  - Unauthenticated → 401
 *  - storage_path not exposed in responses (TASK-002D boundary)
 *
 * TASK-002C — Documents Metadata Layer
 */
class DocumentHttpApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSlug = 'doc-http-test';
    private string $token;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        [$this->user, $this->token] = $this->createUserAndToken();
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('tenant')->table('document_versions')->delete();
            DB::connection('tenant')->table('documents')->delete();
            DB::connection('tenant')->table('document_categories')->delete();
            DB::connection('tenant')->table('personal_access_tokens')->delete();
            DB::connection('tenant')->table('users')->delete();
            DB::connection('mysql')->table('tenants')->where('slug', $this->tenantSlug)->delete();
        } catch (\Throwable) {}
        parent::tearDown();
    }

    // =========================================================================
    // SETUP HELPERS
    // =========================================================================

    private function setUpTenant(): void
    {
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => $this->tenantSlug,
            'name'        => 'Document HTTP Test Tenant',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        Config::set('database.connections.tenant.driver', 'mysql');
        Config::set('database.connections.tenant.host', '127.0.0.1');
        Config::set('database.connections.tenant.port', '3306');
        Config::set('database.connections.tenant.database', 'binaa2_testing');
        Config::set('database.connections.tenant.username', config('database.connections.mysql.username'));
        Config::set('database.connections.tenant.password', config('database.connections.mysql.password'));
        Config::set('database.connections.tenant.charset', 'utf8mb4');
        Config::set('database.connections.tenant.collation', 'utf8mb4_unicode_ci');
        Config::set('database.connections.tenant.prefix', '');
        Config::set('database.connections.tenant.strict', true);
        DB::purge('tenant');
    }

    private function createUserAndToken(): array
    {
        $user  = User::on('tenant')->create([
            'name'     => 'Document Test User',
            'email'    => 'doctest@example.com',
            'password' => Hash::make('password'),
        ]);
        $token = $user->createToken('test-token')->plainTextToken;
        return [$user, $token];
    }

    private function authHeaders(): array
    {
        return [
            'X-Tenant'      => $this->tenantSlug,
            'Authorization' => 'Bearer ' . $this->token,
        ];
    }

    // =========================================================================
    // POST /api/v1/documents — CREATE
    // =========================================================================

    /** @test */
    public function create_document_returns_201_with_valid_data(): void
    {
        $response = $this->postJson('/api/v1/documents', [
            'title'           => 'Site Inspection Report',
            'description'     => 'Weekly site inspection',
            'document_number' => 'DOC-2026-001',
            'status'          => 'draft',
            'mime_type'       => 'application/pdf',
        ], $this->authHeaders());

        $response->assertStatus(201)
            ->assertJsonFragment(['title' => 'Site Inspection Report'])
            ->assertJsonFragment(['status' => 'draft'])
            ->assertJsonFragment(['document_number' => 'DOC-2026-001']);

        // storage_path must NOT be exposed
        $response->assertJsonMissingPath('storage_path');
    }

    /** @test */
    public function create_document_sets_storage_path_to_null(): void
    {
        $response = $this->postJson('/api/v1/documents', [
            'title' => 'Metadata Only Doc',
        ], $this->authHeaders());

        $response->assertStatus(201);

        // Verify in DB that storage_path is null
        $data       = $response->json();
        $docId      = $data['id'];
        $dbDocument = Document::find($docId);

        $this->assertNull($dbDocument->storage_path, 'storage_path must remain null — TASK-002D boundary');
    }

    /** @test */
    public function create_document_returns_422_when_title_missing(): void
    {
        $response = $this->postJson('/api/v1/documents', [
            'description' => 'No title provided',
        ], $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    /** @test */
    public function create_document_returns_422_for_invalid_status(): void
    {
        $response = $this->postJson('/api/v1/documents', [
            'title'  => 'Bad Status Doc',
            'status' => 'invalid_status',
        ], $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    /** @test */
    public function create_document_returns_422_for_invalid_category_id(): void
    {
        $response = $this->postJson('/api/v1/documents', [
            'title'       => 'Bad Category',
            'category_id' => 'not-a-uuid',
        ], $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    /** @test */
    public function create_document_accepts_tags_array(): void
    {
        $response = $this->postJson('/api/v1/documents', [
            'title' => 'Tagged Document',
            'tags'  => ['engineering', 'drawings', 'site'],
        ], $this->authHeaders());

        $response->assertStatus(201);
        $tags = $response->json('tags');
        $this->assertIsArray($tags);
        $this->assertContains('engineering', $tags);
    }

    // =========================================================================
    // GET /api/v1/documents — LIST
    // =========================================================================

    /** @test */
    public function list_documents_returns_200_with_empty_list(): void
    {
        $response = $this->getJson('/api/v1/documents', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJson([]);
    }

    /** @test */
    public function list_documents_returns_only_tenant_documents(): void
    {
        // Create 2 documents for this tenant
        Document::create([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Doc A',
            'created_by'      => $this->user->id,
            'status'          => 'draft',
            'current_version' => 1,
        ]);
        Document::create([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Doc B',
            'created_by'      => $this->user->id,
            'status'          => 'published',
            'current_version' => 1,
        ]);

        $response = $this->getJson('/api/v1/documents', $this->authHeaders());

        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
    }

    // =========================================================================
    // GET /api/v1/documents/{id} — SHOW
    // =========================================================================

    /** @test */
    public function get_document_returns_200_for_valid_id(): void
    {
        $doc = Document::create([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Findable Doc',
            'created_by'      => $this->user->id,
            'status'          => 'draft',
            'current_version' => 1,
        ]);

        $response = $this->getJson('/api/v1/documents/' . $doc->id, $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'Findable Doc']);

        // storage_path must NOT be exposed
        $response->assertJsonMissingPath('storage_path');
    }

    /** @test */
    public function get_document_returns_404_for_unknown_id(): void
    {
        $response = $this->getJson(
            '/api/v1/documents/00000000-0000-0000-0000-000000000000',
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    // =========================================================================
    // PATCH /api/v1/documents/{id} — UPDATE
    // =========================================================================

    /** @test */
    public function update_document_returns_200_with_updated_fields(): void
    {
        $doc = Document::create([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Original Title',
            'created_by'      => $this->user->id,
            'status'          => 'draft',
            'current_version' => 1,
        ]);

        $response = $this->patchJson('/api/v1/documents/' . $doc->id, [
            'title'  => 'Updated Title',
            'status' => 'published',
        ], $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'Updated Title'])
            ->assertJsonFragment(['status' => 'published']);
    }

    /** @test */
    public function update_document_returns_404_for_unknown_id(): void
    {
        $response = $this->patchJson(
            '/api/v1/documents/00000000-0000-0000-0000-000000000000',
            ['title' => 'New Title'],
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    /** @test */
    public function update_document_returns_422_for_invalid_status(): void
    {
        $doc = Document::create([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Doc for Status Test',
            'created_by'      => $this->user->id,
            'status'          => 'draft',
            'current_version' => 1,
        ]);

        $response = $this->patchJson('/api/v1/documents/' . $doc->id, [
            'status' => 'invalid',
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    // =========================================================================
    // DELETE /api/v1/documents/{id} — SOFT DELETE
    // =========================================================================

    /** @test */
    public function delete_document_returns_200_and_soft_deletes(): void
    {
        $doc = Document::create([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Deletable Document',
            'created_by'      => $this->user->id,
            'status'          => 'draft',
            'current_version' => 1,
        ]);

        $response = $this->deleteJson('/api/v1/documents/' . $doc->id, [], $this->authHeaders());

        $response->assertStatus(200);

        // Verify soft deleted — not found in normal query
        $this->assertNull(Document::find($doc->id));
        $trashed = Document::withTrashed()->find($doc->id);
        $this->assertNotNull($trashed);
    }

    /** @test */
    public function delete_document_returns_404_for_unknown_id(): void
    {
        $response = $this->deleteJson(
            '/api/v1/documents/00000000-0000-0000-0000-000000000000',
            [],
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    // =========================================================================
    // VERSIONS
    // =========================================================================

    /** @test */
    public function create_version_returns_201(): void
    {
        $service  = app(\App\Document\DocumentService::class);
        $document = $service->createDocument($this->tenantSlug, $this->user->id, 'Versionable Doc');

        $response = $this->postJson('/api/v1/documents/' . $document->id . '/versions', [
            'change_summary' => 'Second revision',
        ], $this->authHeaders());

        $response->assertStatus(201)
            ->assertJsonFragment(['version_number' => 2]);
    }

    /** @test */
    public function get_version_returns_latest_when_no_version_specified(): void
    {
        $service  = app(\App\Document\DocumentService::class);
        $document = $service->createDocument($this->tenantSlug, $this->user->id, 'Latest Version Test');
        $service->createVersion($document->id, $this->user->id, $this->tenantSlug, ['change_summary' => 'v2']);

        $response = $this->getJson(
            '/api/v1/documents/' . $document->id . '/versions',
            $this->authHeaders()
        );

        $response->assertStatus(200)
            ->assertJsonFragment(['version_number' => 2]);
    }

    /** @test */
    public function get_version_returns_specific_version(): void
    {
        $service  = app(\App\Document\DocumentService::class);
        $document = $service->createDocument($this->tenantSlug, $this->user->id, 'Specific Version Test');
        $service->createVersion($document->id, $this->user->id, $this->tenantSlug, ['change_summary' => 'v2']);

        $response = $this->getJson(
            '/api/v1/documents/' . $document->id . '/versions/1',
            $this->authHeaders()
        );

        $response->assertStatus(200)
            ->assertJsonFragment(['version_number' => 1]);
    }

    // =========================================================================
    // CATEGORIES
    // =========================================================================

    /** @test */
    public function create_category_returns_201(): void
    {
        $response = $this->postJson('/api/v1/document-categories', [
            'name'        => 'Engineering',
            'description' => 'Engineering documents',
        ], $this->authHeaders());

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'Engineering']);
    }

    /** @test */
    public function create_category_returns_422_when_name_missing(): void
    {
        $response = $this->postJson('/api/v1/document-categories', [
            'description' => 'No name',
        ], $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /** @test */
    public function list_categories_returns_200(): void
    {
        DocumentCategory::create([
            'tenant_slug' => $this->tenantSlug,
            'name'        => 'Legal',
            'is_active'   => true,
        ]);

        $response = $this->getJson('/api/v1/document-categories', $this->authHeaders());

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json());
    }

    // =========================================================================
    // AUTHENTICATION
    // =========================================================================

    /** @test */
    public function unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/documents', [
            'X-Tenant' => $this->tenantSlug,
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function create_document_without_auth_returns_401(): void
    {
        $response = $this->postJson('/api/v1/documents', [
            'title' => 'Unauthorized Document',
        ], ['X-Tenant' => $this->tenantSlug]);

        $response->assertStatus(401);
    }

    // =========================================================================
    // API CONTRACT — storage_path boundary
    // =========================================================================

    /** @test */
    public function storage_path_is_never_exposed_in_document_response(): void
    {
        $service  = app(\App\Document\DocumentService::class);
        $document = $service->createDocument($this->tenantSlug, $this->user->id, 'Boundary Test');

        // List endpoint
        $listResponse = $this->getJson('/api/v1/documents', $this->authHeaders());
        $listResponse->assertStatus(200);
        foreach ($listResponse->json() as $doc) {
            $this->assertArrayNotHasKey('storage_path', $doc, 'storage_path must not appear in list response');
        }

        // Show endpoint
        $showResponse = $this->getJson('/api/v1/documents/' . $document->id, $this->authHeaders());
        $showResponse->assertStatus(200)
            ->assertJsonMissingPath('storage_path');
    }
}
