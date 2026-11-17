<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Local shared-schema track: document requirements catalog + attachment review fields
 * + additive verification/approval status columns (existing status preserved).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('mysql')->hasTable('registration_document_requirements')) {
            Schema::connection('mysql')->create('registration_document_requirements', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 64);
                $table->string('label_ar', 255);
                $table->string('label_en', 255)->nullable();
                $table->string('legal_type', 32); // individual|organization|any
                $table->string('activity_type', 32); // contractor|consultant|both|owner|any
                $table->boolean('is_required')->default(true);
                $table->boolean('expires')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(100);
                $table->timestamps();
                $table->unique(['code', 'legal_type', 'activity_type'], 'reg_doc_req_unique');
                $table->index(['legal_type', 'activity_type', 'is_active'], 'reg_doc_req_profile_idx');
            });
        } elseif (! $this->hasIndex('registration_document_requirements', 'reg_doc_req_profile_idx')) {
            Schema::connection('mysql')->table('registration_document_requirements', function (Blueprint $table): void {
                $table->index(['legal_type', 'activity_type', 'is_active'], 'reg_doc_req_profile_idx');
            });
        }

        if (! Schema::connection('mysql')->hasColumn('tenant_registration_attachments', 'document_type')) {
            Schema::connection('mysql')->table('tenant_registration_attachments', function (Blueprint $table): void {
                $table->string('document_type', 64)->nullable()->after('request_id');
                $table->string('review_status', 32)->default('pending')->after('size');
                $table->text('review_note')->nullable()->after('review_status');
                $table->unsignedBigInteger('reviewed_by')->nullable()->after('review_note');
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
                $table->unsignedBigInteger('replaces_attachment_id')->nullable()->after('reviewed_at');
                $table->boolean('is_current')->default(true)->after('replaces_attachment_id');
                $table->index(['request_id', 'document_type', 'is_current'], 'reg_att_doc_current_idx');
                $table->index(['request_id', 'review_status'], 'reg_att_review_idx');
            });
        }

        if (! Schema::connection('mysql')->hasColumn('tenant_registration_requests', 'verification_status')) {
            Schema::connection('mysql')->table('tenant_registration_requests', function (Blueprint $table): void {
                $table->string('verification_status', 32)->default('not_started')->after('status');
                $table->string('approval_status', 32)->default('pending')->after('verification_status');
            });
        }

        $now = now();
        $rows = [
            ['identity_proof', 'إثبات الهوية', 'Identity proof', 'individual', 'contractor', true, false, 10],
            ['identity_proof', 'إثبات الهوية', 'Identity proof', 'individual', 'owner', true, false, 10],
            ['activity_permit', 'مستند مزاولة النشاط', 'Activity permit', 'individual', 'contractor', false, true, 20],
            ['commercial_registration', 'السجل التجاري', 'Commercial registration', 'organization', 'contractor', true, true, 10],
            ['commercial_registration', 'السجل التجاري', 'Commercial registration', 'organization', 'consultant', true, true, 10],
            ['commercial_registration', 'السجل التجاري', 'Commercial registration', 'organization', 'owner', true, true, 10],
            ['commercial_license', 'الرخصة التجارية', 'Commercial license', 'organization', 'contractor', false, true, 20],
            ['contractor_classification', 'شهادة تصنيف المقاولين', 'Contractor classification', 'organization', 'contractor', false, true, 30],
            ['professional_license', 'الترخيص المهني', 'Professional license', 'organization', 'consultant', true, true, 20],
            ['authorized_signatory', 'إثبات صفة المفوض', 'Authorized signatory proof', 'organization', 'contractor', true, false, 40],
            ['authorized_signatory', 'إثبات صفة المفوض', 'Authorized signatory proof', 'organization', 'consultant', true, false, 40],
            ['authorized_signatory', 'إثبات صفة المفوض', 'Authorized signatory proof', 'organization', 'owner', true, false, 40],
            ['ownership_or_mandate', 'إثبات ملكية أو تفويض', 'Ownership or mandate', 'individual', 'owner', false, false, 20],
            ['ownership_or_mandate', 'إثبات ملكية أو تفويض', 'Ownership or mandate', 'organization', 'owner', false, false, 20],
        ];

        foreach ($rows as [$code, $ar, $en, $legal, $activity, $required, $expires, $sort]) {
            $exists = DB::connection('mysql')->table('registration_document_requirements')
                ->where('code', $code)
                ->where('legal_type', $legal)
                ->where('activity_type', $activity)
                ->exists();
            if ($exists) {
                continue;
            }
            DB::connection('mysql')->table('registration_document_requirements')->insert([
                'code' => $code,
                'label_ar' => $ar,
                'label_en' => $en,
                'legal_type' => $legal,
                'activity_type' => $activity,
                'is_required' => $required,
                'expires' => $expires,
                'is_active' => true,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissions = [
            'platform.tenant_registrations.documents.view' => 'عرض مستندات طلبات التسجيل',
            'platform.tenant_registrations.documents.download' => 'تنزيل مستندات طلبات التسجيل',
            'platform.tenant_registrations.documents.review' => 'مراجعة مستندات طلبات التسجيل',
            'platform.registration_document_requirements.manage' => 'إدارة كتالوج مستندات التسجيل',
        ];
        foreach ($permissions as $name => $description) {
            $exists = DB::connection('mysql')->table('platform_permissions')->where('name', $name)->exists();
            if (! $exists) {
                DB::connection('mysql')->table('platform_permissions')->insert([
                    'name' => $name,
                    'description' => $description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $ownerRoleId = DB::connection('mysql')->table('platform_roles')->where('slug', 'platform-owner')->value('id');
        if ($ownerRoleId) {
            $permIds = DB::connection('mysql')->table('platform_permissions')
                ->whereIn('name', array_keys($permissions))
                ->pluck('id');
            foreach ($permIds as $permId) {
                DB::connection('mysql')->table('platform_role_permissions')->insertOrIgnore([
                    'platform_role_id' => $ownerRoleId,
                    'platform_permission_id' => $permId,
                ]);
            }
        }

        // Backfill derived statuses from existing status
        $map = static function (string $status): array {
            return match ($status) {
                'draft' => ['not_started', 'pending'],
                'submitted' => ['pending', 'pending'],
                'under_review' => ['in_progress', 'pending'],
                'needs_information' => ['failed', 'pending'],
                'approved_pending_provisioning' => ['verified', 'approved'],
                'rejected' => ['failed', 'rejected'],
                'provisioned' => ['verified', 'approved'],
                default => ['not_started', 'pending'],
            };
        };
        DB::connection('mysql')->table('tenant_registration_requests')->orderBy('id')->chunkById(100, function ($chunk) use ($map): void {
            foreach ($chunk as $row) {
                [$verification, $approval] = $map((string) $row->status);
                DB::connection('mysql')->table('tenant_registration_requests')->where('id', $row->id)->update([
                    'verification_status' => $verification,
                    'approval_status' => $approval,
                ]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::connection('mysql')->hasColumn('tenant_registration_requests', 'verification_status')) {
            Schema::connection('mysql')->table('tenant_registration_requests', function (Blueprint $table): void {
                $table->dropColumn(['verification_status', 'approval_status']);
            });
        }
        if (Schema::connection('mysql')->hasColumn('tenant_registration_attachments', 'document_type')) {
            Schema::connection('mysql')->table('tenant_registration_attachments', function (Blueprint $table): void {
                $table->dropColumn([
                    'document_type',
                    'review_status',
                    'review_note',
                    'reviewed_by',
                    'reviewed_at',
                    'replaces_attachment_id',
                    'is_current',
                ]);
            });
        }
        Schema::connection('mysql')->dropIfExists('registration_document_requirements');
    }

    private function hasIndex(string $table, string $index): bool
    {
        $rows = DB::connection('mysql')->select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$index]);

        return count($rows) > 0;
    }
};
