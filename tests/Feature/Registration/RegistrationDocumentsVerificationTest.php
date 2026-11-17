<?php

declare(strict_types=1);

namespace Tests\Feature\Registration;

use App\Foundation\PlatformUser;
use App\Registration\Applicant;
use App\Registration\RegistrationAttachmentService;
use App\Registration\RegistrationDocumentRequirement;
use App\Registration\RegistrationDocumentReviewService;
use App\Registration\RegistrationOcrAssistance;
use App\Registration\TenantRegistrationRequest;
use App\Registration\TenantRegistrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationDocumentsVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('mysql');
        $this->createSchema();
        Storage::fake('registration_private');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        parent::tearDown();
    }

    #[Test]
    public function catalog_returns_requirements_for_individual_contractor(): void
    {
        $this->seedCatalog();
        $service = app(\App\Registration\RegistrationDocumentRequirementService::class);
        $items = $service->forProfile('individual', 'contractor');
        $this->assertTrue($items->contains(fn ($row) => $row->code === 'identity_proof' && $row->is_required));
        $this->assertFalse($items->contains(fn ($row) => $row->code === 'commercial_registration'));
    }

    #[Test]
    public function submit_requires_mandatory_documents_when_catalog_defines_them(): void
    {
        $this->seedCatalog();
        $applicant = Applicant::on('mysql')->create([
            'email' => 'docs@example.test',
            'email_verified_at' => now(),
        ]);
        $request = $this->registrations()->createDraft([
            'name' => 'مقاول فرد',
            'legal_type' => 'individual',
            'activity_type' => 'contractor',
            'responsible_name' => 'أحمد',
            'identifiers' => [['type' => 'qid', 'number' => '27800999888']],
            'applicant_email' => $applicant->email,
        ], $applicant);

        try {
            $this->registrations()->submit($request, 'applicant', $applicant->id);
            $this->fail('Submit must fail without identity proof.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('documents', $exception->errors());
        }
    }

    #[Test]
    public function document_reject_moves_request_to_needs_information_and_allows_resubmit_version(): void
    {
        $this->seedCatalog();
        $applicant = Applicant::on('mysql')->create([
            'email' => 'resubmit@example.test',
            'email_verified_at' => now(),
        ]);
        $request = $this->registrations()->createDraft([
            'name' => 'شركة مقاولات',
            'legal_type' => 'organization',
            'activity_type' => 'contractor',
            'responsible_name' => 'مسؤول',
            'identifiers' => [['type' => 'cr', 'number' => 'CR-DOC-1']],
            'applicant_email' => $applicant->email,
        ], $applicant);

        $first = app(RegistrationAttachmentService::class)->store(
            $this->pdfUpload('cr.pdf'),
            $request,
            'applicant',
            $applicant->id,
            'commercial_registration',
        );
        app(RegistrationAttachmentService::class)->store(
            $this->pdfUpload('auth.pdf'),
            $request,
            'applicant',
            $applicant->id,
            'authorized_signatory',
        );

        $request = $this->registrations()->submit($request->fresh(), 'applicant', $applicant->id);
        $actor = $this->platformUser();
        $request = $this->registrations()->review($request, $actor, 'start_review', 'بدء المراجعة');

        app(RegistrationDocumentReviewService::class)->reviewAttachment(
            $request,
            $first->fresh(),
            $actor,
            'reject',
            'الصورة غير واضحة — أعد رفع السجل التجاري.',
        );

        $request = $request->fresh();
        $this->assertSame('needs_information', $request->status);
        $this->assertSame('failed', $request->verification_status);
        $this->assertSame('rejected', $first->fresh()->review_status);

        $second = app(RegistrationAttachmentService::class)->store(
            $this->pdfUpload('cr-v2.pdf'),
            $request,
            'applicant',
            $applicant->id,
            'commercial_registration',
        );
        $this->assertFalse((bool) $first->fresh()->is_current);
        $this->assertTrue((bool) $second->is_current);
        $this->assertSame($first->id, $second->replaces_attachment_id);
        $this->assertSame('pending', $second->review_status);
    }

    #[Test]
    public function approve_blocked_until_required_documents_accepted(): void
    {
        $this->seedCatalog();
        $applicant = Applicant::on('mysql')->create([
            'email' => 'approve-docs@example.test',
            'email_verified_at' => now(),
        ]);
        $request = $this->registrations()->createDraft([
            'name' => 'شركة مقاولات 2',
            'legal_type' => 'organization',
            'activity_type' => 'contractor',
            'responsible_name' => 'مسؤول',
            'identifiers' => [['type' => 'cr', 'number' => 'CR-DOC-2']],
            'applicant_email' => $applicant->email,
        ], $applicant);
        foreach (['commercial_registration', 'authorized_signatory'] as $type) {
            app(RegistrationAttachmentService::class)->store(
                $this->pdfUpload($type.'.pdf'),
                $request,
                'applicant',
                $applicant->id,
                $type,
            );
        }
        $request = $this->registrations()->submit($request->fresh(), 'applicant', $applicant->id);
        $actor = $this->platformUser();
        $request = $this->registrations()->review($request, $actor, 'start_review', 'مراجعة');

        try {
            $this->registrations()->review($request, $actor, 'approve', 'اعتماد', 'ref-1');
            $this->fail('Approve must require accepted documents.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('documents', $exception->errors());
        }

        foreach ($request->attachments()->where('is_current', true)->get() as $attachment) {
            app(RegistrationDocumentReviewService::class)->reviewAttachment(
                $request->fresh(),
                $attachment,
                $actor,
                'accept',
                'مقبول',
            );
        }
        // Rejection/completion paths may have set needs_information; move back via resubmit path for approve test:
        // Accept alone does not change status from under_review if still under_review.
        $request = $request->fresh();
        if ($request->status === 'needs_information') {
            $request = $this->registrations()->submit($request, 'applicant', $applicant->id);
            $request = $this->registrations()->review($request, $actor, 'start_review', 'إعادة مراجعة');
        }
        $request = $this->registrations()->review($request->fresh(), $actor, 'approve', 'اعتماد بعد قبول المستندات', 'ref-ok');
        $this->assertSame('approved_pending_provisioning', $request->status);
        $this->assertSame('verified', $request->verification_status);
        $this->assertSame('approved', $request->approval_status);
    }

    #[Test]
    public function dangerous_executable_upload_is_rejected(): void
    {
        $applicant = Applicant::on('mysql')->create([
            'email' => 'safe@example.test',
            'email_verified_at' => now(),
        ]);
        $request = $this->registrations()->createDraft([
            'name' => 'فرد',
            'legal_type' => 'individual',
            'activity_type' => 'owner',
            'responsible_name' => 'مالك',
            'identifiers' => [['type' => 'qid', 'number' => '27800111222']],
            'applicant_email' => $applicant->email,
        ], $applicant);

        $exe = UploadedFile::fake()->createWithContent('malware.pdf', "MZ\x90\x00fake-exe");
        try {
            app(RegistrationAttachmentService::class)->store($exe, $request, 'applicant', $applicant->id, 'identity_proof');
            $this->fail('Executable payload must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
    }

    #[Test]
    public function stored_mime_type_is_detected_from_file_signature(): void
    {
        $applicant = Applicant::on('mysql')->create([
            'email' => 'mime@example.test',
            'email_verified_at' => now(),
        ]);
        $request = $this->registrations()->createDraft([
            'name' => 'فرد',
            'legal_type' => 'individual',
            'activity_type' => 'owner',
            'responsible_name' => 'مالك',
            'identifiers' => [['type' => 'qid', 'number' => '27800119999']],
            'applicant_email' => $applicant->email,
        ], $applicant);

        $pdf = app(RegistrationAttachmentService::class)->store(
            $this->pdfUpload('proof.pdf'),
            $request,
            'applicant',
            $applicant->id,
            'identity_proof',
        );
        $this->assertSame('application/pdf', $pdf->mime_type);

        $png = app(RegistrationAttachmentService::class)->store(
            UploadedFile::fake()->createWithContent(
                'photo.png',
                "\x89PNG\r\n\x1a\n".str_repeat("\x00", 32),
            ),
            $request,
            'applicant',
            $applicant->id,
            'identity_photo',
        );
        $this->assertSame('image/png', $png->mime_type);
    }

    #[Test]
    public function ocr_assistance_is_explicitly_unavailable_without_paid_provider(): void
    {
        $ocr = app(RegistrationOcrAssistance::class);
        $this->assertFalse($ocr->isAvailable());
        $this->assertFalse($ocr->describe()['available']);
    }

    #[Test]
    public function attachment_payload_hides_storage_path(): void
    {
        $applicant = Applicant::on('mysql')->create([
            'email' => 'hide@example.test',
            'email_verified_at' => now(),
        ]);
        $request = $this->registrations()->createDraft([
            'name' => 'مالك فرد',
            'legal_type' => 'individual',
            'activity_type' => 'owner',
            'responsible_name' => 'مالك',
            'identifiers' => [['type' => 'qid', 'number' => '27800333444']],
            'applicant_email' => $applicant->email,
        ], $applicant);
        $attachment = app(RegistrationAttachmentService::class)->store(
            $this->pdfUpload('id.pdf'),
            $request,
            'applicant',
            $applicant->id,
            'identity_proof',
        );
        $array = $attachment->toArray();
        $this->assertArrayNotHasKey('path', $array);
        $this->assertArrayNotHasKey('disk', $array);
    }

    private function pdfUpload(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n",
        );
    }

    private function registrations(): TenantRegistrationService
    {
        return app(TenantRegistrationService::class);
    }

    private function platformUser(): PlatformUser
    {
        return PlatformUser::on('mysql')->create([
            'name' => 'Platform Reviewer',
            'email' => uniqid('reviewer-').'@example.test',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
    }

    private function seedCatalog(): void
    {
        $now = now();
        $rows = [
            ['identity_proof', 'إثبات الهوية', 'individual', 'contractor', true],
            ['commercial_registration', 'السجل التجاري', 'organization', 'contractor', true],
            ['authorized_signatory', 'إثبات صفة المفوض', 'organization', 'contractor', true],
        ];
        foreach ($rows as [$code, $ar, $legal, $activity, $required]) {
            RegistrationDocumentRequirement::on('mysql')->create([
                'code' => $code,
                'label_ar' => $ar,
                'label_en' => $code,
                'legal_type' => $legal,
                'activity_type' => $activity,
                'is_required' => $required,
                'expires' => false,
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function createSchema(): void
    {
        Schema::connection('mysql')->create('platform_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::connection('mysql')->create('registration_applicants', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('tenant_registration_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_number')->unique();
            $table->string('slug')->unique();
            $table->unsignedBigInteger('applicant_id')->nullable();
            $table->unsignedBigInteger('representative_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('source');
            $table->string('status')->index();
            $table->string('verification_status')->default('not_started');
            $table->string('approval_status')->default('pending');
            $table->string('applicant_email')->index();
            $table->string('name');
            $table->string('legal_type');
            $table->string('activity_type');
            $table->string('phone')->nullable();
            $table->string('responsible_name');
            $table->string('responsible_qid')->nullable();
            $table->date('qid_expires_at')->nullable();
            $table->timestamp('owner_consent_at')->nullable();
            $table->string('owner_consent_version')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('tenant_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('registration_request_id')->nullable();
            $table->string('type');
            $table->string('number');
            $table->string('normalized_number', 128);
            $table->date('expires_at')->nullable();
            $table->string('validity_status')->default('unverified');
            $table->string('document_path')->nullable();
            $table->timestamps();
            $table->unique(['type', 'normalized_number']);
        });
        Schema::connection('mysql')->create('tenant_registration_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::connection('mysql')->create('tenant_registration_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->string('document_type', 64)->nullable();
            $table->string('uploaded_by_type');
            $table->unsignedBigInteger('uploaded_by_id')->nullable();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('review_status', 32)->default('pending');
            $table->text('review_note')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('replaces_attachment_id')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();
        });
        Schema::connection('mysql')->create('registration_document_requirements', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64);
            $table->string('label_ar', 255);
            $table->string('label_en', 255)->nullable();
            $table->string('legal_type', 32);
            $table->string('activity_type', 32);
            $table->boolean('is_required')->default(true);
            $table->boolean('expires')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
            $table->unique(['code', 'legal_type', 'activity_type']);
        });
        Schema::connection('mysql')->create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }
}
