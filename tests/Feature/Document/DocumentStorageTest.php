<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Document\Document;
use App\Document\DocumentVersion;
use App\IAM\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DocumentStorageTest — Feature tests for TASK-002D Document Storage Integration.
 *
 * Tests:
 *  - POST /api/v1/documents/{id}/upload   (successful upload)
 *  - GET  /api/v1/documents/{id}/download (successful download)
 *  - Invalid file types → 422
 *  - Files exceeding 20 MB → 422
 *  - Unauthenticated upload → 401
 *  - Unauthenticated download → 401
 *  - Unauthorized (cross-tenant) upload → 404
 *  - Unauthorized (cross-tenant) download → 404
 *  - Missing document upload → 404
 *  - Missing document download → 404
 *  - Download with no file attached → 404
 *  - Streamed file response verification
 *  - storage_path never exposed in upload response
 *
 * F-5 Decision: LocalStorageProvider / local disk / 20 MB / PDF,DOCX,XLSX,PNG,JPG,JPEG
 * TASK-002D — Document Storage Integration
 */
class DocumentStorageTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSlug = 'doc-storage-test';
    private string $token;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpTenant();
        [$this->user, $this->token] = $this->createUserAndToken();
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('tenant')->table('document_versions')->delete();
            DB::connection('tenant')->table('documents')->delete();
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
            'name'        => 'Document Storage Test Tenant',
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
            'name'     => 'Storage Test User',
            'email'    => 'storagetest@example.com',
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

    /**
     * Create a document record directly in the tenant DB.
     */
    private function createDocument(array $overrides = []): Document
    {
        $doc = Document::on('tenant')->create(array_merge([
            'tenant_slug'     => $this->tenantSlug,
            'title'           => 'Test Document',
            'status'          => 'draft',
            'created_by'      => $this->user->id,
            'current_version' => 1,
            'storage_path'    => null,
        ], $overrides));

        DocumentVersion::on('tenant')->create([
            'tenant_slug'    => $this->tenantSlug,
            'document_id'    => $doc->id,
            'version_number' => 1,
            'created_by'     => $this->user->id,
            'change_summary' => 'Initial version',
            'storage_path'   => null,
        ]);

        return $doc;
    }

    // =========================================================================
    // POST /api/v1/documents/{id}/upload — SUCCESSFUL UPLOAD
    // =========================================================================

    /** @test */
    public function upload_pdf_returns_200_and_updates_document(): void
    {
        $document = $this->createDocument();
        $file     = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        $response = $this->postJson(
            "/api/v1/documents/{$document->id}/upload",
            ['file' => $file],
            $this->authHeaders(),
        );

        $response->assertStatus(200);
        $response->assertJsonStructure(['id', 'title', 'mime_type', 'file_size']);
        $response->assertJsonFragment(['mime_type' => 'application/pdf']);

        // storage_path must NOT be exposed.
        $response->assertJsonMissingPath('storage_path');

        // Document record updated in DB.
        $updated = Document::on('tenant')->find($document->id);
        $this->assertNotNull($updated->storage_path);
        $this->assertEquals('application/pdf', $updated->mime_type);
        $this->assertGreaterThan(0, $updated->file_size);
    }

    /** @test */
    public function upload_png_returns_200(): void
    {
        $document = $this->createDocument();
        $file     = UploadedFile::fake()->image('photo.png');

        $response = $this->postJson(
            "/api/v1/documents/{$document->id}/upload",
            ['file' => $file],
            $this->authHeaders(),
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['mime_type' => 'image/png']);
    }

    /** @test */
    public function upload_jpeg_returns_200(): void
    {
        $document = $this->createDocument();
        $file     = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $response = $this->postJson(
            "/api/v1/documents/{$document->id}/upload",
            ['file' => $file],
            $this->authHeaders(),
        );

        $response->assertStatus(200);
    }

    /** @test */
    public function upload_stores_file_in_tenant_scoped_path(): void
    {
        $document = $this->createDocument();
        $file     = UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf');

        $this->postJson(
            "/api/v1/documents/{$document->id}/upload",
            ['file' => $file],
            $this->authHeaders(),
        )->assertStatus(200);

        // Verify file stored under tenant-scoped path.
        $updated = Document::on('tenant')->find($document->id);
        $this->assertStringContainsString($this->tenantSlug, $updated->storage_path);
        $this->assertStringContainsString($document->id, $updated->storage_path);

        Storage::disk('local')->assertExists($updated->storage_path);
    }

    // =========================================================================
    // GET /api/v1/documents/{id}/download — SUCCESSFUL DOWNLOAD
    // =========================================================================

    /** @test */
    public function download_returns_streamed_file_after_upload(): void
    {
        $document = $this->createDocument();
        $file     = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        // Upload first.
        $this->postJson(
            "/api/v1/documents/{$document->id}/upload",
            ['file' => $file],
            $this->authHeaders(),
        )->assertStatus(200);

        // Download.
        $response = $this->get(
            "/api/v1/documents/{$document->id}/download",
            $this->authHeaders(),
        );

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString(
            'attachment',
            $response->headers->get('Content-Disposition') ?? '',
        );
    }

    /** @test */
    public function download_response_does_not_expose_storage_path(): void
    {
        $document = $this->createDocument();
        $file     = UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf');

        $this->postJson(
            "/api/v1/documents/{$document->id}/upload",
            ['file' => $file],
            $this->authHeaders(),
        )->assertStatus(200);

        $response = $this->get(
            "/api/v1/documents/{$document->id}/download",
            $this->authHeaders(),
        );

        $response->assertStatus(200);

        // Verify Content-Disposition header does not expose internal storage path.
        $disposition = $response->headers->get('Content-Disposition') ?? '';
        $this->assertStringNotContainsString('tenants/', $disposition);
        $this->assertStringNotContainsString('storage_path', $disposition);

        // Verify the response is a file download (not a JSON body with storage_path).
        $this->assertStringNotContainsString('storage_path', $disposition);
        $this->assertStringContainsString('attachment', $disposition);
    }

    // =========================================================================
    // INVALID FILE TYPES → 422
    // =========================================================================

    /** @test */
    public function upload_txt_file_returns_422(): void
    {
        $document = $this->createDocument();
        $file     = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $response = $this->postJson(
            "/api/v1/documents/{$document->id}/upload",
            ['file' => $file],
            $this->authHeaders(),
        );

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'File type not allowed. Allowed types: PDF, DOCX, XLSX, PNG, JPG, JPEG.']);
    }

    /** @test */
    public function upload_zip_file_returns_422(): void
    {
        $document = $this->createDocument();
        $file     = UploadedFile::fake()->create('archive.zip', 100, 'application/zip');

        $response = $this->postJson(
            "/api/v1/documents/{$document->id}/upload",
            ['file' => $file],
            $this->authHeaders(),
        );

        $response->assertStatus(422);
    }

    /** @test */
    public function upload_executable_file_returns_422(): void
    {
        $document = $this->createDocument();
        $file     = UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload');

        $response = $this->postJson(
            "/api/v1/documents/{$document->id}/upload",
            ['file' => $file],
            $this->authHeaders(),
        );

        $response->assertStatus(422);
    }

    /** @test */
    public function upload_without_file_returns_422(): void
    {
        $document = $this->createDocument();

        $response = $this->postJson(
            "/api/v1/documents/{$document->id}/upload",
            [],
            $this->authHeaders(),
        );

        $response->assertStatus(422);
    }

    // =========================================================================
    // FILE EXCEEDING 20 MB → 422
    // =========================================================================

    /** @test */
    public function upload_file_exceeding_20mb_returns_422(): void
    {
        $document = $this->createDocument();
        // 21 MB file (size in KB for UploadedFile::fake()->create)
        $file = UploadedFile::fake()->create('large.pdf', 21 * 1024, 'application/pdf');

        $response = $this->postJson(
            "/api/v1/documents/{$document->id}/upload",
            ['file' => $file],
            $this->authHeaders(),
        );

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'File exceeds the maximum allowed size of 20 MB.']);
    }

    /** @test */
    public function upload_file_exactly_20mb_is_accepted(): void
    {
        $document = $this->createDocument();
        // Exactly 20 MB (20 * 1024 KB)
        $file = UploadedFile::fake()->create('exact.pdf', 20 * 1024, 'application/pdf');

        $response = $this->postJson(
            "/api/v1/documents/{$document->id}/upload",
            ['file' => $file],
            $this->authHeaders(),
        );

        $response->assertStatus(200);
    }

    // =========================================================================
    // UNAUTHENTICATED REQUESTS → 401
    // =========================================================================

    /** @test */
    public function upload_without_auth_returns_401(): void
    {
        $document = $this->createDocument();
        $file     = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        $response = $this->postJson(
            "/api/v1/documents/{$document->id}/upload",
            ['file' => $file],
            ['X-Tenant' => $this->tenantSlug],
        );

        $response->assertStatus(401);
    }

    /** @test */
    public function download_without_auth_returns_401(): void
    {
        $document = $this->createDocument();

        // Use Accept: application/json to prevent redirect to login route.
        $response = $this->get(
            "/api/v1/documents/{$document->id}/download",
            [
                'X-Tenant' => $this->tenantSlug,
                'Accept'   => 'application/json',
            ],
        );

        $response->assertStatus(401);
    }

    // =========================================================================
    // CROSS-TENANT ACCESS → 404
    // =========================================================================

    /** @test */
    public function upload_cross_tenant_document_returns_404(): void
    {
        // Create a document belonging to a DIFFERENT tenant.
        $otherTenantSlug = 'other-tenant-storage';
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => $otherTenantSlug,
            'name'        => 'Other Tenant',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Create document with a different tenant_slug.
        $otherDoc = Document::on('tenant')->create([
            'tenant_slug'     => $otherTenantSlug,
            'title'           => 'Other Tenant Document',
            'status'          => 'draft',
            'created_by'      => $this->user->id,
            'current_version' => 1,
            'storage_path'    => null,
        ]);

        DocumentVersion::on('tenant')->create([
            'tenant_slug'    => $otherTenantSlug,
            'document_id'    => $otherDoc->id,
            'version_number' => 1,
            'created_by'     => $this->user->id,
            'change_summary' => 'Initial version',
            'storage_path'   => null,
        ]);

        $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        // Authenticated as doc-storage-test tenant, trying to upload to other-tenant doc.
        $response = $this->postJson(
            "/api/v1/documents/{$otherDoc->id}/upload",
            ['file' => $file],
            $this->authHeaders(),
        );

        $response->assertStatus(404);

        // Cleanup.
        DB::connection('mysql')->table('tenants')->where('slug', $otherTenantSlug)->delete();
    }

    /** @test */
    public function download_cross_tenant_document_returns_404(): void
    {
        $otherTenantSlug = 'other-tenant-dl';
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => $otherTenantSlug,
            'name'        => 'Other Tenant DL',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $otherDoc = Document::on('tenant')->create([
            'tenant_slug'     => $otherTenantSlug,
            'title'           => 'Other Tenant Document',
            'status'          => 'draft',
            'created_by'      => $this->user->id,
            'current_version' => 1,
            'storage_path'    => 'tenants/other-tenant-dl/documents/fake-id/file.pdf',
        ]);

        // Authenticated as doc-storage-test tenant, trying to download other-tenant doc.
        $response = $this->get(
            "/api/v1/documents/{$otherDoc->id}/download",
            $this->authHeaders(),
        );

        $response->assertStatus(404);

        // Cleanup.
        DB::connection('mysql')->table('tenants')->where('slug', $otherTenantSlug)->delete();
    }

    // =========================================================================
    // MISSING DOCUMENT → 404
    // =========================================================================

    /** @test */
    public function upload_to_nonexistent_document_returns_404(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';
        $file   = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        $response = $this->postJson(
            "/api/v1/documents/{$fakeId}/upload",
            ['file' => $file],
            $this->authHeaders(),
        );

        $response->assertStatus(404);
    }

    /** @test */
    public function download_nonexistent_document_returns_404(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->get(
            "/api/v1/documents/{$fakeId}/download",
            $this->authHeaders(),
        );

        $response->assertStatus(404);
    }

    // =========================================================================
    // MISSING STORED FILE → 404
    // =========================================================================

    /** @test */
    public function download_document_with_no_file_attached_returns_404(): void
    {
        // Document exists but has no storage_path (no file uploaded yet).
        $document = $this->createDocument(['storage_path' => null]);

        $response = $this->get(
            "/api/v1/documents/{$document->id}/download",
            $this->authHeaders(),
        );

        $response->assertStatus(404);
        $response->assertJsonFragment(['message' => 'No file attached to this document.']);
    }

    /** @test */
    public function download_document_with_missing_physical_file_returns_404(): void
    {
        // Document has a storage_path but the physical file does not exist in storage.
        $document = $this->createDocument([
            'storage_path' => 'tenants/' . $this->tenantSlug . '/documents/fake-id/ghost.pdf',
            'mime_type'    => 'application/pdf',
        ]);

        $response = $this->get(
            "/api/v1/documents/{$document->id}/download",
            $this->authHeaders(),
        );

        $response->assertStatus(404);
    }

    // =========================================================================
    // STORAGE_PATH NOT EXPOSED IN RESPONSES
    // =========================================================================

    /** @test */
    public function upload_response_does_not_contain_storage_path(): void
    {
        $document = $this->createDocument();
        $file     = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        $response = $this->postJson(
            "/api/v1/documents/{$document->id}/upload",
            ['file' => $file],
            $this->authHeaders(),
        );

        $response->assertStatus(200);
        $response->assertJsonMissingPath('storage_path');
    }

    // =========================================================================
    // SAFE ERROR HANDLING
    // =========================================================================

    /** @test */
    public function upload_returns_json_error_not_exception_on_invalid_type(): void
    {
        $document = $this->createDocument();
        $file     = UploadedFile::fake()->create('script.php', 5, 'application/x-php');

        $response = $this->postJson(
            "/api/v1/documents/{$document->id}/upload",
            ['file' => $file],
            $this->authHeaders(),
        );

        // Must return structured JSON error, not a 500 exception.
        $response->assertStatus(422);
        $response->assertJsonStructure(['message']);
    }

    /** @test */
    public function download_returns_json_error_not_exception_on_missing_doc(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000001';

        $response = $this->get(
            "/api/v1/documents/{$fakeId}/download",
            $this->authHeaders(),
        );

        // Must return structured JSON error, not a 500 exception.
        $response->assertStatus(404);
        $response->assertJsonStructure(['message']);
    }
}
