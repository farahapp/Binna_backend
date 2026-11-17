<?php

declare(strict_types=1);

use App\Http\Controllers\Accounting\AccountingController;
use App\Http\Controllers\BI\BIController;
use App\Http\Controllers\Commercial\CommercialController;
use App\Http\Controllers\CostControl\CostControlController;
use App\Http\Controllers\CRM\CRMController;
use App\Http\Controllers\Document\DocumentController;
use App\Http\Controllers\IAM\AuthController;
use App\Http\Controllers\IAM\TenantResolutionController;
use App\Http\Controllers\IAM\RoleController;
use App\Http\Controllers\IAM\UserController;
use App\Http\Controllers\Inventory\GoodsReceiptController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\Procurement\PurchaseOrderController;
use App\Http\Controllers\Subcontracts\SubcontractAgreementController;
use App\Http\Controllers\Subcontracts\SubcontractProgressController;
use App\Http\Controllers\Procurement\PurchaseRequestController;
use App\Http\Controllers\Procurement\RFQController;
use App\Http\Controllers\Equipment\EquipmentController;
use App\Http\Controllers\Finance\FinanceController;
use App\Http\Controllers\HR\HrController;
use App\Http\Controllers\Projects\ProjectsController;
use App\Http\Controllers\Network\NetworkProfileController;
use App\Http\Controllers\Network\TenderController;
use App\Http\Controllers\Network\BidController;
use App\Http\Controllers\Network\EvaluationController;
use App\Http\Controllers\Network\AwardController;
use App\Http\Controllers\Network\NetworkProfileVerificationController;
use App\Http\Controllers\Network\InvitationController;
use App\Http\Controllers\Network\ClarificationController;
use App\Http\Controllers\Core\CoreProjectController;
use App\Http\Controllers\Core\CollaborationController;
use App\Http\Controllers\Platform\PlatformAuditController;
use App\Http\Controllers\Platform\PlatformAuthController;
use App\Http\Controllers\Platform\PlatformDashboardController;
use App\Http\Controllers\Platform\PlatformPermissionController;
use App\Http\Controllers\Platform\PlatformRoleController;
use App\Http\Controllers\Platform\PlatformTenantController;
use App\Http\Controllers\Platform\PlatformUserController;
use App\Http\Controllers\Platform\PlatformRepresentativeController;
use App\Http\Controllers\Platform\PlatformTenantRegistrationController;
use App\Http\Controllers\Platform\PlatformRegistrationDocumentRequirementController;
use App\Http\Controllers\Platform\PlatformWhatsAppController;
use App\Http\Controllers\Registration\ApplicantIdentityController;
use App\Http\Controllers\Registration\ApplicantRegistrationController;
use App\Http\Controllers\Registration\RepresentativeAuthController;
use App\Http\Controllers\Registration\RepresentativeRegistrationController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/*
|--------------------------------------------------------------------------
| API Routes — BINAA2
|--------------------------------------------------------------------------
|
| All routes require X-Tenant header (resolved by 'tenant' middleware).
| Authentication routes use Sanctum PAT (HB-1).
|
*/

// ─── Health check — no auth, no tenant middleware ─────────────────────────────
// Health endpoint used for deployment checks: /api/health
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'BINAA2 API',
        'timestamp' => now()->toIso8601String(),
    ], 200);
})->name('health');

// Applicant and representative registration identities are separate from tenant IAM and Platform IAM.
Route::prefix('registration')->name('registration.')->group(function () {
    Route::post('/applicant/otp/request', [ApplicantIdentityController::class, 'requestOtp'])
        ->middleware('throttle:registration-otp')->name('applicant.otp.request');
    Route::post('/applicant/otp/verify', [ApplicantIdentityController::class, 'verifyOtp'])
        ->middleware('throttle:registration-otp')->name('applicant.otp.verify');

    Route::post('/representative/login', [RepresentativeAuthController::class, 'login'])
        ->middleware('throttle:representative-auth')->name('representative.login');
    Route::post('/representative/password-reset', [RepresentativeAuthController::class, 'requestPasswordReset'])
        ->middleware('throttle:representative-auth')->name('representative.password-reset');
    Route::post('/representative/invitation/consume', [RepresentativeAuthController::class, 'consumeInvitation'])
        ->middleware('throttle:representative-auth')->name('representative.invitation.consume');

    Route::middleware('auth:applicant-token')->prefix('applicant')->name('applicant.')->group(function () {
        Route::get('/me', [ApplicantIdentityController::class, 'me'])->name('me');
        Route::post('/logout', [ApplicantIdentityController::class, 'logout'])->name('logout');
        Route::get('/requests', [ApplicantRegistrationController::class, 'index'])->name('requests.index');
        Route::post('/requests/claim-assisted', [ApplicantRegistrationController::class, 'claim'])->name('requests.claim');
        Route::post('/requests', [ApplicantRegistrationController::class, 'store'])->name('requests.store');
        Route::get('/requests/{id}', [ApplicantRegistrationController::class, 'show'])->name('requests.show');
        Route::put('/requests/{id}', [ApplicantRegistrationController::class, 'update'])->name('requests.update');
        Route::post('/requests/{id}/submit', [ApplicantRegistrationController::class, 'submit'])->name('requests.submit');
        Route::post('/requests/{id}/information', [ApplicantRegistrationController::class, 'respond'])->name('requests.information');
        Route::get('/requests/{id}/documents/checklist', [ApplicantRegistrationController::class, 'checklist'])
            ->name('requests.documents.checklist');
        Route::post('/requests/{id}/attachments', [ApplicantRegistrationController::class, 'upload'])->name('requests.attachments.store');
        Route::get('/requests/{id}/attachments/{attachmentId}', [ApplicantRegistrationController::class, 'downloadAttachment'])
            ->name('requests.attachments.download');
    });

    Route::middleware('auth:representative-token')->prefix('representative')->name('representative.')->group(function () {
        Route::get('/me', [RepresentativeAuthController::class, 'me'])->name('me');
        Route::post('/logout', [RepresentativeAuthController::class, 'logout'])->name('logout');
        Route::get('/requests', [RepresentativeRegistrationController::class, 'index'])->name('requests.index');
        Route::post('/requests', [RepresentativeRegistrationController::class, 'store'])->name('requests.store');
        Route::get('/requests/{id}', [RepresentativeRegistrationController::class, 'show'])->name('requests.show');
        Route::put('/requests/{id}', [RepresentativeRegistrationController::class, 'update'])->name('requests.update');
        Route::post('/requests/{id}/submit', [RepresentativeRegistrationController::class, 'submit'])->name('requests.submit');
        Route::post('/requests/{id}/attachments', [RepresentativeRegistrationController::class, 'upload'])->name('requests.attachments.store');
        Route::get('/requests/{id}/attachments/{attachmentId}', [RepresentativeRegistrationController::class, 'downloadAttachment'])
            ->name('requests.attachments.download');
    });
});

// ─── Platform Admin: No X-Tenant required — shared schema only ───────────────
// Platform operators authenticate against platform_users (shared mysql schema).
// These routes are entirely separate from tenant routes.
// Pipeline: [no tenant middleware] → Platform session or PAT auth → platform.operator → Controller.
// Browser-origin requests use Sanctum stateful cookies + CSRF. Native PAT login
// remains stateless and explicitly excludes the stateful middleware.

// Public: Platform operator login (no auth required)
Route::prefix('platform')
    ->name('platform.')
    ->group(function () {

    Route::post('/auth/login', [PlatformAuthController::class, 'login'])
        ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
        ->name('auth.login');

    Route::post('/auth/web/login', [PlatformAuthController::class, 'webLogin'])
        ->middleware('throttle:platform-web-auth')
        ->name('auth.web.login');
    Route::post('/auth/web/logout', [PlatformAuthController::class, 'webLogout'])
        ->middleware(['auth:platform-web', 'platform.operator'])
        ->name('auth.web.logout');

    // Protected: require authenticated platform operator
    // Either Platform's dedicated browser session or its separate mobile PAT is accepted.
    // platform.operator rejects tenant identities; no tenant middleware is applied here.
    Route::middleware(['auth:platform-web,platform-token', 'platform.operator'])->group(function () {

        Route::post('/auth/logout', [PlatformAuthController::class, 'logout'])
            ->name('auth.logout');

        Route::get('/auth/me', [PlatformAuthController::class, 'me'])
            ->name('auth.me');

        Route::post('/auth/change-password', [PlatformAuthController::class, 'changePassword'])
            ->name('auth.change-password');

        Route::get('/dashboard', [PlatformDashboardController::class, 'show'])
            ->name('dashboard.show');

        Route::get('/audit-entries', [PlatformAuditController::class, 'index'])
            ->middleware('platform.permission:platform.audit.view')
            ->name('audit-entries.index');

        Route::get('/settings/whatsapp', [PlatformWhatsAppController::class, 'show'])
            ->middleware('platform.permission:platform.settings.whatsapp.manage')
            ->name('settings.whatsapp.show');
        Route::patch('/settings/whatsapp', [PlatformWhatsAppController::class, 'updateSettings'])
            ->middleware('platform.permission:platform.settings.whatsapp.manage')
            ->name('settings.whatsapp.update');
        Route::post('/settings/whatsapp/link', [PlatformWhatsAppController::class, 'startLink'])
            ->middleware('platform.permission:platform.settings.whatsapp.manage')
            ->name('settings.whatsapp.link');
        Route::get('/settings/whatsapp/qr', [PlatformWhatsAppController::class, 'qr'])
            ->middleware('platform.permission:platform.settings.whatsapp.manage')
            ->name('settings.whatsapp.qr');
        Route::post('/settings/whatsapp/qr/refresh', [PlatformWhatsAppController::class, 'refreshQr'])
            ->middleware('platform.permission:platform.settings.whatsapp.manage')
            ->name('settings.whatsapp.qr.refresh');
        Route::post('/settings/whatsapp/candidate/cancel', [PlatformWhatsAppController::class, 'cancelCandidate'])
            ->middleware('platform.permission:platform.settings.whatsapp.manage')
            ->name('settings.whatsapp.candidate.cancel');
        Route::post('/settings/whatsapp/identity/sync', [PlatformWhatsAppController::class, 'syncIdentity'])
            ->middleware('platform.permission:platform.settings.whatsapp.manage')
            ->name('settings.whatsapp.identity.sync');
        Route::post('/settings/whatsapp/approve', [PlatformWhatsAppController::class, 'approve'])
            ->middleware('platform.permission:platform.settings.whatsapp.manage')
            ->name('settings.whatsapp.approve');
        Route::post('/settings/whatsapp/rollback', [PlatformWhatsAppController::class, 'rollback'])
            ->middleware('platform.permission:platform.settings.whatsapp.manage')
            ->name('settings.whatsapp.rollback');
        Route::post('/settings/whatsapp/disconnect', [PlatformWhatsAppController::class, 'disconnect'])
            ->middleware('platform.permission:platform.settings.whatsapp.manage')
            ->name('settings.whatsapp.disconnect');
        Route::post('/settings/whatsapp/test-connection', [PlatformWhatsAppController::class, 'testConnection'])
            ->middleware('platform.permission:platform.settings.whatsapp.manage')
            ->name('settings.whatsapp.test-connection');
        Route::post('/settings/whatsapp/test-message', [PlatformWhatsAppController::class, 'testMessage'])
            ->middleware('platform.permission:platform.settings.whatsapp.manage')
            ->name('settings.whatsapp.test-message');
        Route::post('/settings/whatsapp/security/backup', [PlatformWhatsAppController::class, 'createBackup'])
            ->middleware('platform.permission:platform.settings.whatsapp.manage')
            ->name('settings.whatsapp.security.backup');
        Route::post('/settings/whatsapp/security/verify', [PlatformWhatsAppController::class, 'verifyStorage'])
            ->middleware('platform.permission:platform.settings.whatsapp.manage')
            ->name('settings.whatsapp.security.verify');
        Route::post('/settings/whatsapp/security/recovery-test', [PlatformWhatsAppController::class, 'recoveryTest'])
            ->middleware('platform.permission:platform.settings.whatsapp.manage')
            ->name('settings.whatsapp.security.recovery-test');

        // Platform IAM uses shared-schema roles and permissions, never Tenant IAM.
        Route::get('/users', [PlatformUserController::class, 'index'])
            ->middleware('platform.permission:platform.users.view')
            ->name('users.index');
        Route::post('/users', [PlatformUserController::class, 'store'])
            ->middleware([
                'platform.permission:platform.users.create',
                'platform.permission:platform.users.assign-roles',
                'platform.permission:platform.roles.view',
            ])
            ->name('users.store');
        Route::patch('/users/{id}', [PlatformUserController::class, 'update'])
            ->middleware('platform.permission:platform.users.update')
            ->name('users.update');
        Route::put('/users/{id}/roles', [PlatformUserController::class, 'syncRoles'])
            ->middleware([
                'platform.permission:platform.users.assign-roles',
                'platform.permission:platform.roles.view',
            ])
            ->name('users.roles.sync');

        Route::get('/roles', [PlatformRoleController::class, 'index'])
            ->middleware('platform.permission:platform.roles.view')
            ->name('roles.index');
        Route::post('/roles', [PlatformRoleController::class, 'store'])
            ->middleware([
                'platform.permission:platform.roles.create',
                'platform.permission:platform.roles.assign-permissions',
                'platform.permission:platform.permissions.view',
            ])
            ->name('roles.store');
        Route::patch('/roles/{id}', [PlatformRoleController::class, 'update'])
            ->middleware('platform.permission:platform.roles.update')
            ->name('roles.update');
        Route::put('/roles/{id}/permissions', [PlatformRoleController::class, 'syncPermissions'])
            ->middleware([
                'platform.permission:platform.roles.assign-permissions',
                'platform.permission:platform.permissions.view',
            ])
            ->name('roles.permissions.sync');
        Route::delete('/roles/{id}', [PlatformRoleController::class, 'destroy'])
            ->middleware('platform.permission:platform.roles.delete')
            ->name('roles.destroy');

        Route::get('/permissions', [PlatformPermissionController::class, 'index'])
            ->middleware('platform.permission:platform.permissions.view')
            ->name('permissions.index');

        // Tenant / entity directory management
        Route::get('/tenants/summary', [PlatformTenantController::class, 'summary'])
            ->middleware('platform.permission:platform.tenants.view')
            ->name('tenants.summary');
        Route::get('/tenants', [PlatformTenantController::class, 'index'])
            ->middleware('platform.permission:platform.tenants.view')
            ->name('tenants.index');
        Route::get('/tenant-registrations', [PlatformTenantRegistrationController::class, 'index'])
            ->middleware('platform.permission:platform.tenant_registrations.view')
            ->name('tenant-registrations.index');
        Route::get('/tenant-registrations/{id}', [PlatformTenantRegistrationController::class, 'show'])
            ->middleware('platform.permission:platform.tenant_registrations.view')
            ->name('tenant-registrations.show');
        Route::post('/tenant-registrations/{id}/review', [PlatformTenantRegistrationController::class, 'review'])
            ->middleware([
                'platform.permission:platform.tenant_registrations.review',
                'platform.permission:platform.tenant_registrations.view',
            ])
            ->name('tenant-registrations.review');
        Route::post('/tenant-registrations/{id}/representatives', [PlatformTenantRegistrationController::class, 'assign'])
            ->middleware('platform.permission:platform.tenant_registrations.assign-representatives')
            ->name('tenant-registrations.representatives.assign');
        Route::delete('/tenant-registrations/{id}/representatives/{representativeId}', [PlatformTenantRegistrationController::class, 'unassign'])
            ->middleware('platform.permission:platform.tenant_registrations.assign-representatives')
            ->name('tenant-registrations.representatives.unassign');
        Route::post('/tenant-registrations/{id}/attachments/{attachmentId}/review', [PlatformTenantRegistrationController::class, 'reviewAttachment'])
            ->middleware([
                'platform.permission:platform.tenant_registrations.documents.review',
                'platform.permission:platform.tenant_registrations.view',
            ])
            ->name('tenant-registrations.attachments.review');
        Route::get('/tenant-registrations/{id}/attachments/{attachmentId}', [PlatformTenantRegistrationController::class, 'downloadAttachment'])
            ->middleware('platform.permission:platform.tenant_registrations.view')
            ->name('tenant-registrations.attachments.download');

        Route::get('/registration-document-requirements', [PlatformRegistrationDocumentRequirementController::class, 'index'])
            ->middleware('platform.permission:platform.registration_document_requirements.manage')
            ->name('registration-document-requirements.index');
        Route::post('/registration-document-requirements', [PlatformRegistrationDocumentRequirementController::class, 'store'])
            ->middleware('platform.permission:platform.registration_document_requirements.manage')
            ->name('registration-document-requirements.store');
        Route::put('/registration-document-requirements/{id}', [PlatformRegistrationDocumentRequirementController::class, 'update'])
            ->middleware('platform.permission:platform.registration_document_requirements.manage')
            ->name('registration-document-requirements.update');

        Route::get('/registration-representatives', [PlatformRepresentativeController::class, 'index'])
            ->middleware('platform.permission:platform.registration_representatives.view')
            ->name('registration-representatives.index');
        Route::post('/registration-representatives', [PlatformRepresentativeController::class, 'store'])
            ->middleware([
                'platform.permission:platform.registration_representatives.create',
                'platform.permission:platform.registration_representatives.view',
            ])
            ->name('registration-representatives.store');
        Route::get('/registration-representatives/{id}/audit', [PlatformRepresentativeController::class, 'audit'])
            ->middleware('platform.permission:platform.registration_representatives.view')
            ->name('registration-representatives.audit');
        Route::post('/registration-representatives/{id}/approve', [PlatformRepresentativeController::class, 'approve'])
            ->middleware('platform.permission:platform.registration_representatives.approve')
            ->name('registration-representatives.approve');
        Route::post('/registration-representatives/{id}/resend-invitation', [PlatformRepresentativeController::class, 'resendInvitation'])
            ->middleware('platform.permission:platform.registration_representatives.approve')
            ->name('registration-representatives.resend-invitation');
        Route::post('/registration-representatives/{id}/suspend', [PlatformRepresentativeController::class, 'suspend'])
            ->middleware('platform.permission:platform.registration_representatives.suspend')
            ->name('registration-representatives.suspend');
        Route::post('/registration-representatives/{id}/reactivate', [PlatformRepresentativeController::class, 'reactivate'])
            ->middleware('platform.permission:platform.registration_representatives.approve')
            ->name('registration-representatives.reactivate');

        Route::get('/tenants/{id}', [PlatformTenantController::class, 'show'])
            ->middleware('platform.permission:platform.tenants.view')
            ->name('tenants.show');
        Route::patch('/tenants/{id}', [PlatformTenantController::class, 'update'])
            ->middleware([
                'platform.permission:platform.tenants.update',
                'platform.permission:platform.tenants.view',
            ])
            ->name('tenants.update');
        Route::post('/tenants/{id}/identifiers', [PlatformTenantController::class, 'storeIdentifier'])
            ->middleware([
                'platform.permission:platform.tenants.update',
                'platform.permission:platform.tenants.view',
            ])
            ->name('tenants.identifiers.store');
        Route::patch('/tenants/{id}/identifiers/{identifierId}', [PlatformTenantController::class, 'updateIdentifier'])
            ->middleware([
                'platform.permission:platform.tenants.update',
                'platform.permission:platform.tenants.view',
            ])
            ->name('tenants.identifiers.update');
        Route::post('/tenants/{id}/identifiers/{identifierId}/approve', [PlatformTenantController::class, 'approveIdentifier'])
            ->middleware([
                'platform.permission:platform.tenants.identifiers.approve',
                'platform.permission:platform.tenants.view',
            ])
            ->name('tenants.identifiers.approve');
        Route::delete('/tenants/{id}/identifiers/{identifierId}', [PlatformTenantController::class, 'destroyIdentifier'])
            ->middleware([
                'platform.permission:platform.tenants.update',
                'platform.permission:platform.tenants.view',
            ])
            ->name('tenants.identifiers.destroy');

        Route::patch('/tenants/{id}/suspend', [PlatformTenantController::class, 'suspend'])
            ->middleware([
                'platform.permission:platform.tenants.suspend',
                'platform.permission:platform.tenants.view',
            ])
            ->name('tenants.suspend');

        Route::patch('/tenants/{id}/activate', [PlatformTenantController::class, 'activate'])
            ->middleware([
                'platform.permission:platform.tenants.activate',
                'platform.permission:platform.tenants.view',
            ])
            ->name('tenants.activate');
    });
});

// ─── Public: Two-step tenant login ───────────────────────────────────────────
Route::post('/auth/tenant/resolve', [TenantResolutionController::class, 'resolve'])
    ->name('auth.tenant.resolve');

Route::middleware(['tenant.login-token', 'tenant', 'tenant.schema'])->group(function () {
    Route::post('/auth/tenant/login', [AuthController::class, 'login'])
        ->name('auth.tenant.login');

    Route::post('/auth/tenant/spa/login', [AuthController::class, 'spaLogin'])
        ->name('auth.tenant.spa.login');
});

// ─── Public: Legacy Flutter login retained with verified CR/QID checks ────────
Route::middleware(['tenant', 'tenant.schema'])->group(function () {

    // Authentication — Login (HB-1) — PAT
    // ResolveTenantContext → SwitchToTenantSchema → Controller
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->name('auth.login');

    // SPA Authentication — Login (C-002 §26 — Web SPA Cookie Auth)
    // Session-based — no PAT issued. EnsureFrontendRequestsAreStateful handles cookie session.
    Route::post('/auth/spa/login', [AuthController::class, 'spaLogin'])
        ->name('auth.spa.login');
});

// ─── Protected: all routes require tenant context + Sanctum auth ──────────────
Route::middleware(['tenant', 'tenant.schema', 'auth:sanctum', 'tenant.membership'])->group(function () {

    // Authentication — Logout PAT (HB-1)
    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->name('auth.logout');

    // Current User Identity (B-01 — Mobile /api/auth/me)
    Route::get('/auth/me', [AuthController::class, 'me'])
        ->name('auth.me');

    // SPA Authentication — Logout (C-002 §26 — Web SPA Cookie Auth)
    // Session-based auth via EnsureFrontendRequestsAreStateful + auth:sanctum
    Route::post('/auth/spa/logout', [AuthController::class, 'spaLogout'])
        ->name('auth.spa.logout');

    // IAM — Tenant users & roles (Wave E.4 — additive; does not change auth contracts)
    Route::get('/iam/users', [UserController::class, 'index'])->name('iam.users.index');
    Route::post('/iam/users', [UserController::class, 'store'])->name('iam.users.store');
    Route::patch('/iam/users/{id}', [UserController::class, 'update'])->name('iam.users.update');
    Route::put('/iam/users/{id}/roles', [UserController::class, 'syncRoles'])->name('iam.users.roles.sync');
    Route::get('/iam/roles', [RoleController::class, 'index'])->name('iam.roles.index');

    // IAM — Permission Change (TASK-001C Unit 3)
    Route::post('/iam/roles/{role}/permissions/{permission}', [RoleController::class, 'assignPermission'])
        ->name('iam.roles.permissions.assign');

    // -------------------------------------------------------------------------
    // CRM — Client Management (TASK-003C)
    // ResolveTenantContext → SwitchToTenantSchema → auth:sanctum → Controller
    // -------------------------------------------------------------------------
    Route::prefix('v1/crm')->name('crm.')->group(function () {

        // Clients
        Route::post('/clients', [CRMController::class, 'createClient'])
            ->name('clients.create');
        Route::get('/clients', [CRMController::class, 'listClients'])
            ->name('clients.list');
        Route::get('/clients/{id}', [CRMController::class, 'getClient'])
            ->name('clients.show');

        // Contacts
        Route::get('/contacts/{id}', [CRMController::class, 'getContact'])
            ->name('contacts.show');

        // Leads
        Route::post('/leads', [CRMController::class, 'createLead'])
            ->name('leads.create');
        // CAP-020 — Pipeline List View + Stage Update
        Route::get('/leads', [CRMController::class, 'listLeads'])
            ->name('leads.list');
        Route::patch('/leads/{id}/stage', [CRMController::class, 'updateLeadStage'])
            ->name('leads.stage');

        // Opportunities
        Route::post('/opportunities', [CRMController::class, 'createOpportunity'])
            ->name('opportunities.create');
        Route::patch('/opportunities/{id}/won', [CRMController::class, 'markOpportunityWon'])
            ->name('opportunities.won');
        Route::patch('/opportunities/{id}/lost', [CRMController::class, 'markOpportunityLost'])
            ->name('opportunities.lost');
        // CAP-020 — Pipeline List View + Stage Update
        Route::get('/opportunities', [CRMController::class, 'listOpportunities'])
            ->name('opportunities.list');
        Route::patch('/opportunities/{id}/stage', [CRMController::class, 'updateOpportunityStage'])
            ->name('opportunities.stage');

        // Activities
        Route::post('/activities', [CRMController::class, 'recordActivity'])
            ->name('activities.create');
    });

    // -------------------------------------------------------------------------
    // Commercial — Commercial Management (TASK-004C)
    // ResolveTenantContext → SwitchToTenantSchema → auth:sanctum → Controller
    // -------------------------------------------------------------------------
    Route::prefix('v1/commercial')->name('commercial.')->group(function () {

        // Public Contracts (B1)
        Route::get('/contracts/{id}', [CommercialController::class, 'getContract'])
            ->name('contracts.show');
        Route::get('/contracts/{id}/value', [CommercialController::class, 'getContractValue'])
            ->name('contracts.value');
        Route::get('/boqs/{id}', [CommercialController::class, 'getBOQ'])
            ->name('boqs.show');

        // Tenders List (WEB-001 PHASE 4)
        Route::get('/tenders', [CommercialController::class, 'listTenders'])
            ->name('tenders.list');

        // Contracts List (WEB-001 PHASE 4)
        Route::get('/contracts', [CommercialController::class, 'listContracts'])
            ->name('contracts.list');

        // Quotations List + Show (WEB-001 PHASE 4)
        Route::get('/quotations', [CommercialController::class, 'listQuotations'])
            ->name('quotations.list');
        Route::get('/quotations/{id}', [CommercialController::class, 'getQuotation'])
            ->name('quotations.show');

        // Tenders
        Route::post('/tenders', [CommercialController::class, 'createTender'])
            ->name('tenders.create');
        Route::patch('/tenders/{id}/award', [CommercialController::class, 'awardTender'])
            ->name('tenders.award');

        // BOQs
        Route::post('/boqs', [CommercialController::class, 'createBOQ'])
            ->name('boqs.create');

        // Quotations
        Route::post('/quotations', [CommercialController::class, 'createQuotation'])
            ->name('quotations.create');
        Route::patch('/quotations/{id}/accept', [CommercialController::class, 'acceptQuotation'])
            ->name('quotations.accept');
        Route::post('/quotations/{id}/submit-for-approval', [CommercialController::class, 'submitQuotationForApproval'])
            ->name('quotations.submit-for-approval');

        // Contracts
        Route::post('/contracts', [CommercialController::class, 'createContract'])
            ->name('contracts.create');
        Route::patch('/contracts/{id}/sign', [CommercialController::class, 'signContract'])
            ->name('contracts.sign');
        Route::post('/contracts/{id}/submit-for-approval', [CommercialController::class, 'submitContractForApproval'])
            ->name('contracts.submit-for-approval');

        // Variations — CAP-033 Contract Variations
        Route::post('/variations', [CommercialController::class, 'createVariation'])
            ->name('variations.create');
        Route::get('/variations/{id}', [CommercialController::class, 'getVariation'])
            ->name('variations.show');
        Route::get('/contracts/{id}/variations', [CommercialController::class, 'listVariations'])
            ->name('contracts.variations.list');
        Route::patch('/variations/{id}/approve', [CommercialController::class, 'approveVariation'])
            ->name('variations.approve');
        Route::patch('/variations/{id}/reject', [CommercialController::class, 'rejectVariation'])
            ->name('variations.reject');
    });

    // -------------------------------------------------------------------------
    // Projects — Project Management (TASK-005C)
    // ResolveTenantContext → SwitchToTenantSchema → auth:sanctum → Controller
    // -------------------------------------------------------------------------
    Route::prefix('v1/projects')->name('projects.')->group(function () {

        // Projects List (WEB-001 PHASE 4)
        Route::get('/', [ProjectsController::class, 'listProjects'])
            ->name('list');

        // Public Contracts
        Route::get('/{id}', [ProjectsController::class, 'getProject'])
            ->name('get');
        Route::get('/{id}/status', [ProjectsController::class, 'getProjectStatus'])
            ->name('status');
        Route::get('/{id}/team', [ProjectsController::class, 'getProjectTeam'])
            ->name('team');
        Route::get('/{id}/can-close', [ProjectsController::class, 'canCloseProject'])
            ->name('can-close');

        // Project Operations
        Route::post('/', [ProjectsController::class, 'createProject'])
            ->name('create');
        Route::patch('/{id}/activate', [ProjectsController::class, 'activateProject'])
            ->name('activate');
        Route::patch('/{id}/close', [ProjectsController::class, 'closeProject'])
            ->name('close');
        Route::patch('/{id}/progress', [ProjectsController::class, 'updateProgress'])
            ->name('progress');

        // Activity Operations
        // SESSION 27D-6: ability enforcement on activity routes.
        // projects:read covers GET project/status/team/can-close/list/parties/daily-reports.
        // projects:activities:create covers POST /{id}/activities.
        // projects:activities:update-status covers PATCH /activities/{id}/status.
        // SPA cookie auth uses auth:sanctum session guard — tokenCan() always returns true
        // for session-authenticated users (no PAT abilities to check), so SPA is unaffected.
        // Existing wildcard '*' PATs also satisfy tokenCan() — no breakage.
        Route::post('/{id}/activities', [ProjectsController::class, 'addActivity'])
            ->name('activities.add')
            ->middleware('abilities:projects:activities:create');
        // Activity Status Update — API-GAP-M2-01
        // Lifecycle: pending → in_progress → completed / cancelled
        Route::patch('/activities/{activityId}/status', [ProjectsController::class, 'updateActivityStatus'])
            ->name('activities.update-status')
            ->middleware('abilities:projects:activities:update-status');

        // Daily Report Operations
        Route::get('/{id}/daily-reports', [ProjectsController::class, 'listDailyReports'])
            ->name('daily-reports.list');
        Route::post('/{id}/daily-reports', [ProjectsController::class, 'submitDailyReport'])
            ->name('daily-reports.submit');

        // Milestone Operations
        Route::post('/{id}/milestones', [ProjectsController::class, 'addMilestone'])
            ->name('milestones.add');
        Route::patch('/milestones/{milestoneId}/reached', [ProjectsController::class, 'markMilestoneReached'])
            ->name('milestones.reached');

        // Team Operations
        Route::post('/{id}/team', [ProjectsController::class, 'assignTeamMember'])
            ->name('team.assign');

        // Party Operations (CORE-01C-2)
        Route::get('/{id}/parties', [ProjectsController::class, 'listProjectParties'])
            ->name('parties.list');
        Route::put('/{id}/parties', [ProjectsController::class, 'replaceProjectParties'])
            ->name('parties.replace');
    });

    // -------------------------------------------------------------------------
    // Cost Control — Budget, Commitment, Actual Cost (TASK-006C)
    // ResolveTenantContext → SwitchToTenantSchema → auth:sanctum → Controller
    // -------------------------------------------------------------------------
    Route::prefix('v1/cost-control')->name('cost-control.')->group(function () {

        // Budgets — Public Contracts
        Route::get('/budgets/{projectId}', [CostControlController::class, 'getBudget'])
            ->name('budgets.show');

        // Budgets — Operations
        Route::post('/budgets', [CostControlController::class, 'createBudget'])
            ->name('budgets.create');
        Route::patch('/budgets/{budgetId}/approve', [CostControlController::class, 'approveBudget'])
            ->name('budgets.approve');

        // Commitments — Operations
        Route::post('/commitments', [CostControlController::class, 'recordCommitment'])
            ->name('commitments.create');

        // Commitments — Public Contracts
        Route::get('/projects/{projectId}/commitment-balance', [CostControlController::class, 'getCommitmentBalance'])
            ->name('projects.commitment-balance');

        // Actual Costs — Operations
        Route::post('/actual-costs', [CostControlController::class, 'recordActualCost'])
            ->name('actual-costs.create');

        // Cost Position — Public Contracts
        Route::get('/projects/{projectId}/cost-position', [CostControlController::class, 'getCostPosition'])
            ->name('projects.cost-position');
    });

    // -------------------------------------------------------------------------
    // Procurement — Procurement Management (TASK-007C)
    // CAP-061 Purchase Requisition, CAP-062 RFQ & Quotation, CAP-063 Purchase Order
    // ResolveTenantContext → SwitchToTenantSchema → auth:sanctum → Controller
    // -------------------------------------------------------------------------
    Route::prefix('v1/procurement')->name('procurement.')->group(function () {

        // CAP-061 — Purchase Requests
        Route::get('/purchase-requests', [PurchaseRequestController::class, 'list'])
            ->name('purchase-requests.list');
        Route::post('/purchase-requests', [PurchaseRequestController::class, 'create'])
            ->name('purchase-requests.create');
        Route::get('/purchase-requests/{id}', [PurchaseRequestController::class, 'show'])
            ->name('purchase-requests.show');
        Route::patch('/purchase-requests/{id}/submit', [PurchaseRequestController::class, 'submit'])
            ->name('purchase-requests.submit');
        Route::patch('/purchase-requests/{id}/approve', [PurchaseRequestController::class, 'approve'])
            ->name('purchase-requests.approve');
        Route::patch('/purchase-requests/{id}/reject', [PurchaseRequestController::class, 'reject'])
            ->name('purchase-requests.reject');
        Route::patch('/purchase-requests/{id}/cancel', [PurchaseRequestController::class, 'cancel'])
            ->name('purchase-requests.cancel');

        // CAP-062 — RFQs & Quotations
        Route::get('/rfqs', [RFQController::class, 'list'])
            ->name('rfqs.list');
        Route::post('/rfqs', [RFQController::class, 'create'])
            ->name('rfqs.create');
        Route::get('/rfqs/{id}', [RFQController::class, 'show'])
            ->name('rfqs.show');
        Route::patch('/rfqs/{id}/send', [RFQController::class, 'send'])
            ->name('rfqs.send');
        Route::patch('/rfqs/{id}/close', [RFQController::class, 'close'])
            ->name('rfqs.close');
        Route::post('/rfqs/{id}/quotations', [RFQController::class, 'submitQuotation'])
            ->name('rfqs.quotations.submit');
        Route::get('/rfqs/{id}/quotations/compare', [RFQController::class, 'compareQuotations'])
            ->name('rfqs.quotations.compare');
        Route::patch('/rfqs/{rfqId}/quotations/{quotationId}/select', [RFQController::class, 'selectQuotation'])
            ->name('rfqs.quotations.select');

        // CAP-063 — Purchase Orders List (WEB-001 PHASE 4)
        Route::get('/purchase-orders', [PurchaseOrderController::class, 'list'])
            ->name('purchase-orders.list');

        // CAP-063 — Purchase Orders
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'create'])
            ->name('purchase-orders.create');
        Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show'])
            ->name('purchase-orders.show');
        Route::post('/purchase-orders/{id}/items', [PurchaseOrderController::class, 'addItem'])
            ->name('purchase-orders.items.add');
        Route::patch('/purchase-orders/{id}/submit', [PurchaseOrderController::class, 'submit'])
            ->name('purchase-orders.submit');
        Route::patch('/purchase-orders/{id}/approve', [PurchaseOrderController::class, 'approve'])
            ->name('purchase-orders.approve');
        Route::patch('/purchase-orders/{id}/issue', [PurchaseOrderController::class, 'issue'])
            ->name('purchase-orders.issue');
        Route::patch('/purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])
            ->name('purchase-orders.cancel');
    });

    // -------------------------------------------------------------------------
    // Inventory — Inventory Management (TASK-008C)
    // CAP-070 GRN & Goods Receipt, CAP-071 Stock Management
    // ResolveTenantContext → SwitchToTenantSchema → auth:sanctum → Controller
    // -------------------------------------------------------------------------
    Route::prefix('v1/inventory')->name('inventory.')->group(function () {

        // CAP-070 — Goods Receipt Notes (GRN)
        // WEB-001P — OI-4: GRN list endpoint
        Route::get('/grns', [GoodsReceiptController::class, 'list'])
            ->name('grns.list');

        Route::post('/grns', [GoodsReceiptController::class, 'create'])
            ->name('grns.create');
        Route::get('/grns/{id}', [GoodsReceiptController::class, 'show'])
            ->name('grns.show');
        Route::post('/grns/{id}/items', [GoodsReceiptController::class, 'addItem'])
            ->name('grns.items.add');
        Route::patch('/grns/{id}/confirm', [GoodsReceiptController::class, 'confirm'])
            ->name('grns.confirm');
        Route::patch('/grns/{id}/cancel', [GoodsReceiptController::class, 'cancel'])
            ->name('grns.cancel');

        // CAP-071 — Inventory Items List (WEB-001 PHASE 4)
        Route::get('/items', [StockController::class, 'listItems'])
            ->name('items.list');

        // CAP-071 — Stock Management
        Route::get('/items/{id}', [StockController::class, 'showItem'])
            ->name('items.show');
        Route::get('/items/{id}/stock-level', [StockController::class, 'showStockLevel'])
            ->name('items.stock-level');
        Route::get('/items/{id}/stock-levels', [StockController::class, 'listStockLevels'])
            ->name('items.stock-levels');
        Route::get('/items/{id}/total-quantity', [StockController::class, 'totalQuantity'])
            ->name('items.total-quantity');
        Route::get('/items/{id}/assert-stock', [StockController::class, 'assertStock'])
            ->name('items.assert-stock');

        // CAP-071 — Stock Movements (TASK-012C)
        Route::post('/items/{id}/issue', [StockController::class, 'issueStock'])
            ->name('items.issue');
        Route::post('/items/{id}/transfer', [StockController::class, 'transferStock'])
            ->name('items.transfer');
    });

    // -------------------------------------------------------------------------
    // Subcontracts — Subcontract Management (TASK-009C)
    // CAP-080 Subcontract Agreement, Progress & Certification
    // ResolveTenantContext → SwitchToTenantSchema → auth:sanctum → Controller
    // -------------------------------------------------------------------------
    Route::prefix('v1/subcontracts')->name('subcontracts.')->group(function () {

        // CAP-080 — Agreements
        // WEB-001P — OI-5: Subcontract agreements list endpoint
        Route::get('/agreements', [SubcontractAgreementController::class, 'listAgreements'])
            ->name('agreements.list');

        Route::post('/agreements', [SubcontractAgreementController::class, 'createAgreement'])
            ->name('agreements.create');
        Route::get('/agreements/{id}', [SubcontractAgreementController::class, 'show'])
            ->name('agreements.show');
        Route::patch('/agreements/{id}/submit', [SubcontractAgreementController::class, 'submitForApproval'])
            ->name('agreements.submit');
        Route::patch('/agreements/{id}/activate', [SubcontractAgreementController::class, 'activateAgreement'])
            ->name('agreements.activate');
        Route::patch('/agreements/{id}/complete', [SubcontractAgreementController::class, 'completeAgreement'])
            ->name('agreements.complete');
        Route::patch('/agreements/{id}/cancel', [SubcontractAgreementController::class, 'cancelAgreement'])
            ->name('agreements.cancel');
        Route::get('/agreements/{id}/value', [SubcontractAgreementController::class, 'getSubcontractValue'])
            ->name('agreements.value');

        // CAP-080 — Scope
        Route::post('/agreements/{id}/scopes', [SubcontractAgreementController::class, 'addScope'])
            ->name('agreements.scopes.add');

        // CAP-080 — Progress
        Route::post('/agreements/{id}/progress', [SubcontractProgressController::class, 'recordProgress'])
            ->name('agreements.progress.record');
        Route::get('/agreements/{id}/progress', [SubcontractProgressController::class, 'getSubcontractProgress'])
            ->name('agreements.progress.list');

        // CAP-080 — Certificates
        Route::post('/agreements/{id}/certificates', [SubcontractProgressController::class, 'issueCertificate'])
            ->name('agreements.certificates.issue');
        Route::patch('/certificates/{id}/approve', [SubcontractProgressController::class, 'approveCertificate'])
            ->name('certificates.approve');
    });

    // -------------------------------------------------------------------------
    // HR — Workforce Management (TASK-010C)
    // CAP-090 V1 — No Payroll/WPS (CAP-091 excluded — OD-008 IMPL-BLOCKING)
    // ResolveTenantContext → SwitchToTenantSchema → auth:sanctum → Controller
    // -------------------------------------------------------------------------
    Route::prefix('v1/hr')->name('hr.')->group(function () {

        // Employees
        Route::post('/employees', [HrController::class, 'registerEmployee'])
            ->name('employees.register');
        Route::get('/employees', [HrController::class, 'listActiveEmployees'])
            ->name('employees.list');
        Route::get('/employees/{id}', [HrController::class, 'getEmployee'])
            ->name('employees.show');
        Route::patch('/employees/{id}/terminate', [HrController::class, 'terminateEmployee'])
            ->name('employees.terminate');

        // Project Assignment
        Route::post('/employees/{id}/project-assignments', [HrController::class, 'assignToProject'])
            ->name('employees.project-assignments.assign');

        // Attendance
        Route::post('/employees/{id}/attendance', [HrController::class, 'recordAttendance'])
            ->name('employees.attendance.record');
        Route::get('/employees/{id}/attendance', [HrController::class, 'getAttendance'])
            ->name('employees.attendance.list');

        // Leave Requests
        Route::post('/employees/{id}/leave-requests', [HrController::class, 'submitLeaveRequest'])
            ->name('employees.leave-requests.submit');
        Route::get('/employees/{id}/leave-requests', [HrController::class, 'listLeaveRequests'])
            ->name('employees.leave-requests.list');
        Route::patch('/leave-requests/{id}/approve', [HrController::class, 'approveLeaveRequest'])
            ->name('leave-requests.approve');
        Route::patch('/leave-requests/{id}/reject', [HrController::class, 'rejectLeaveRequest'])
            ->name('leave-requests.reject');

        // Cost Allocations
        Route::post('/employees/{id}/cost-allocations', [HrController::class, 'recordCostAllocation'])
            ->name('employees.cost-allocations.record');
        Route::get('/employees/{id}/cost-allocations', [HrController::class, 'listCostAllocations'])
            ->name('employees.cost-allocations.list');
    });

    // -------------------------------------------------------------------------
    // Equipment — Asset Management (TASK-011C)
    // CAP-100 V1.5 — No Equipment Rental (OD-012 IMPL-DEFERRABLE — V2/Future)
    // ResolveTenantContext → SwitchToTenantSchema → auth:sanctum → Controller
    // -------------------------------------------------------------------------
    Route::prefix('v1/equipment')->name('equipment.')->group(function () {

        // Assets
        Route::post('/assets', [EquipmentController::class, 'registerAsset'])
            ->name('assets.register');
        Route::get('/assets', [EquipmentController::class, 'listAssets'])
            ->name('assets.list');
        Route::get('/assets/{id}', [EquipmentController::class, 'getAsset'])
            ->name('assets.show');

        // Allocation
        Route::post('/assets/{id}/allocate', [EquipmentController::class, 'allocateToProject'])
            ->name('assets.allocate');
        Route::post('/assets/{id}/release', [EquipmentController::class, 'releaseFromProject'])
            ->name('assets.release');

        // Maintenance
        Route::post('/assets/{id}/maintenance', [EquipmentController::class, 'sendToMaintenance'])
            ->name('assets.maintenance.send');
        Route::post('/maintenance/{id}/complete', [EquipmentController::class, 'completeMaintenance'])
            ->name('maintenance.complete');

        // Lifecycle
        Route::post('/assets/{id}/decommission', [EquipmentController::class, 'decommissionAsset'])
            ->name('assets.decommission');

        // Cost Allocation
        Route::post('/assets/{id}/cost-allocation', [EquipmentController::class, 'recordCostAllocation'])
            ->name('assets.cost-allocation.record');
    });

    // -------------------------------------------------------------------------
    // Finance — Finance Management (TASK-013C)
    // CAP-110 Cash Management, CAP-111 Bank Management,
    // CAP-180 Client Billing & Invoicing, CAP-181 Receivables & Collections
    // ResolveTenantContext → SwitchToTenantSchema → auth:sanctum → Controller
    // -------------------------------------------------------------------------
    Route::prefix('v1/finance')->name('finance.')->group(function () {

        // CAP-110 — Cash Management
        Route::post('/cash-accounts', [FinanceController::class, 'createCashAccount'])
            ->name('cash-accounts.create');
        Route::post('/cash-transactions', [FinanceController::class, 'recordCashTransaction'])
            ->name('cash-transactions.create');

        // CAP-111 — Bank Management
        Route::post('/bank-accounts', [FinanceController::class, 'createBankAccount'])
            ->name('bank-accounts.create');
        Route::post('/bank-transactions', [FinanceController::class, 'recordBankTransaction'])
            ->name('bank-transactions.create');
        Route::post('/bank-reconciliations', [FinanceController::class, 'reconcileBank'])
            ->name('bank-reconciliations.create');
        Route::get('/bank-accounts/{id}/balance', [FinanceController::class, 'getBankBalance'])
            ->name('bank-accounts.balance');
        Route::get('/cash-position', [FinanceController::class, 'getCashPosition'])
            ->name('cash-position');

        // CAP-180 — Client Billing & Invoicing
        Route::post('/invoices', [FinanceController::class, 'createInvoice'])
            ->name('invoices.create');
        Route::post('/invoices/{id}/items', [FinanceController::class, 'addInvoiceItem'])
            ->name('invoices.items.add');
        Route::patch('/invoices/{id}/issue', [FinanceController::class, 'issueInvoice'])
            ->name('invoices.issue');
        Route::get('/invoices/{id}', [FinanceController::class, 'getInvoice'])
            ->name('invoices.show');

        // CAP-181 — Receivables & Collections
        Route::post('/collections', [FinanceController::class, 'recordCollection'])
            ->name('collections.create');
        Route::post('/payments', [FinanceController::class, 'recordPayment'])
            ->name('payments.create');

        // WEB-001L — List Endpoints
        Route::get('/invoices', [FinanceController::class, 'listInvoices'])
            ->name('invoices.list');
        Route::get('/bank-accounts', [FinanceController::class, 'listBankAccounts'])
            ->name('bank-accounts.list');
        Route::get('/cash-accounts', [FinanceController::class, 'listCashAccounts'])
            ->name('cash-accounts.list');
        Route::get('/payments', [FinanceController::class, 'listPayments'])
            ->name('payments.list');
        Route::get('/collections', [FinanceController::class, 'listCollections'])
            ->name('collections.list');
    });

    // -------------------------------------------------------------------------
    // BI — Dashboards & Reporting (TASK-015C)
    // Read-only; tenant context and Sanctum authentication are enforced above.
    // -------------------------------------------------------------------------
    Route::prefix('v1/bi')->name('bi.')->group(function () {
        Route::get('/dashboard', [BIController::class, 'getDashboard'])
            ->name('dashboard.show');
    });

    // -------------------------------------------------------------------------
    // Accounting — Accounting Management (TASK-014C)
    // CAP-120 Chart of Accounts & Accounting Setup, CAP-121 Journal Entries & Posting Rules
    // ResolveTenantContext → SwitchToTenantSchema → auth:sanctum → Controller
    // -------------------------------------------------------------------------
    Route::prefix('v1/accounting')->name('accounting.')->group(function () {

        // CAP-120 — Chart of Accounts
        Route::post('/accounts', [AccountingController::class, 'createAccount'])
            ->name('accounts.create');
        Route::patch('/accounts/{id}/deactivate', [AccountingController::class, 'deactivateAccount'])
            ->name('accounts.deactivate');
        Route::get('/accounts/{id}/balance', [AccountingController::class, 'getAccountBalance'])
            ->name('accounts.balance');
        Route::get('/trial-balance', [AccountingController::class, 'getTrialBalance'])
            ->name('trial-balance');

        // CAP-120 — Accounting Periods
        Route::post('/periods', [AccountingController::class, 'createPeriod'])
            ->name('periods.create');
        Route::patch('/periods/{id}/close', [AccountingController::class, 'closePeriod'])
            ->name('periods.close');
        Route::get('/periods/{id}/is-open', [AccountingController::class, 'isPeriodOpen'])
            ->name('periods.is-open');

        // CAP-121 — Journal Entries
        Route::post('/journal-entries', [AccountingController::class, 'createJournalEntry'])
            ->name('journal-entries.create');
        Route::patch('/journal-entries/{id}/post', [AccountingController::class, 'postJournalEntry'])
            ->name('journal-entries.post');
        Route::post('/journal-entries/{id}/reverse', [AccountingController::class, 'reverseJournalEntry'])
            ->name('journal-entries.reverse');
        Route::get('/journal-entries/{id}', [AccountingController::class, 'getJournalEntry'])
            ->name('journal-entries.show');

        // CAP-121 — Posting Rules
        Route::post('/posting-rules', [AccountingController::class, 'createPostingRule'])
            ->name('posting-rules.create');

        // WEB-001N — List Endpoints (OI-1 through OI-4 resolved)
        Route::get('/accounts', [AccountingController::class, 'listAccounts'])
            ->name('accounts.list');
        Route::get('/journal-entries', [AccountingController::class, 'listJournalEntries'])
            ->name('journal-entries.list');
        Route::get('/periods', [AccountingController::class, 'listPeriods'])
            ->name('periods.list');
        Route::get('/posting-rules', [AccountingController::class, 'listPostingRules'])
            ->name('posting-rules.list');
    });

    // -------------------------------------------------------------------------
    // Documents — CAP-130 (TASK-002C)
    // A1 MOD-15 — Document Management (Metadata Layer)
    // ResolveTenantContext → SwitchToTenantSchema → auth:sanctum → Controller
    // METADATA ONLY — storage_path is excluded from responses (TASK-002D boundary)
    // -------------------------------------------------------------------------
    Route::prefix('v1')->name('documents.')->group(function () {

        // Document CRUD
        Route::post('/documents', [DocumentController::class, 'createDocument'])
            ->name('create');
        Route::get('/documents', [DocumentController::class, 'listDocuments'])
            ->name('list');
        Route::get('/documents/{id}', [DocumentController::class, 'getDocument'])
            ->name('show');
        Route::patch('/documents/{id}', [DocumentController::class, 'updateDocument'])
            ->name('update');
        Route::delete('/documents/{id}', [DocumentController::class, 'deleteDocument'])
            ->name('delete');

        // Document Versions
        Route::post('/documents/{id}/versions', [DocumentController::class, 'createVersion'])
            ->name('versions.create');
        Route::get('/documents/{id}/versions/{version?}', [DocumentController::class, 'getVersion'])
            ->name('versions.show');

        // Document File Storage — TASK-002D (F-5 APPROVED)
        // POST: upload physical file (multipart/form-data, field: file)
        // GET:  download physical file (streamed, authenticated, tenant-scoped)
        Route::post('/documents/{id}/upload', [DocumentController::class, 'uploadFile'])
            ->name('upload');
        Route::get('/documents/{id}/download', [DocumentController::class, 'downloadFile'])
            ->name('download');

        // Document Categories
        Route::post('/document-categories', [DocumentController::class, 'createCategory'])
            ->name('categories.create');
        Route::get('/document-categories', [DocumentController::class, 'listCategories'])
            ->name('categories.list');
    });

    // -------------------------------------------------------------------------
    // Notifications — CAP-210 (TASK-002B)
    // A1 MOD-19 — Notifications Shared Service
    // ResolveTenantContext → SwitchToTenantSchema → auth:sanctum → Controller
    // -------------------------------------------------------------------------
    Route::prefix('v1/notifications')->name('notifications.')->group(function () {

        // List notifications for the authenticated user
        Route::get('/', [NotificationController::class, 'index'])
            ->name('index');

        // Mark all notifications as read (must come before /{id}/read)
        Route::patch('/read-all', [NotificationController::class, 'markAllAsRead'])
            ->name('read-all');

        // Mark a single notification as read
        Route::patch('/{id}/read', [NotificationController::class, 'markAsRead'])
            ->name('mark-read');

        // Notification preferences
        Route::get('/preferences', [NotificationController::class, 'getPreferences'])
            ->name('preferences.index');
        Route::put('/preferences', [NotificationController::class, 'setPreference'])
            ->name('preferences.set');
    });

    // -------------------------------------------------------------------------
    // Network — Construction Business Network (NETWORK-001)
    // CAP-160/161 Foundation — Network Profile Management
    // OD-005: Dual Identity + Optional Link
    // OD-006: Free V1 + Extension Points
    // ResolveTenantContext → SwitchToTenantSchema → auth:sanctum → Controller
    // Note: NetworkProfile lives in global (mysql) schema — not tenant schema.
    // -------------------------------------------------------------------------
    Route::prefix('v1/network')->name('network.')->group(function () {

        // Network Profiles
        Route::post('/profiles', [NetworkProfileController::class, 'create'])
            ->name('profiles.create');
        Route::get('/profiles', [NetworkProfileController::class, 'list'])
            ->name('profiles.list');
        Route::get('/profiles/{id}', [NetworkProfileController::class, 'show'])
            ->name('profiles.show');
        Route::patch('/profiles/{id}', [NetworkProfileController::class, 'update'])
            ->name('profiles.update');
        Route::patch('/profiles/{id}/activate', [NetworkProfileController::class, 'activate'])
            ->name('profiles.activate');

        // OD-005 — Optional TenantSupplier ↔ NetworkProfile linking
        Route::post('/profiles/{id}/link-supplier', [NetworkProfileController::class, 'linkSupplier'])
            ->name('profiles.link-supplier');
        Route::delete('/suppliers/{supplierId}/network-link', [NetworkProfileController::class, 'unlinkSupplier'])
            ->name('suppliers.unlink');

        // Network Profile Verification — trust layer, not a permission gate
        Route::post('/profiles/{profileId}/verification', [NetworkProfileVerificationController::class, 'submit'])
            ->name('profile-verification.submit');
        Route::get('/profiles/{profileId}/verification/status', [NetworkProfileVerificationController::class, 'status'])
            ->name('profile-verification.status');
        Route::get('/profiles/{profileId}/verification', [NetworkProfileVerificationController::class, 'own'])
            ->name('profile-verification.own');
        Route::get('/verification-requests', [NetworkProfileVerificationController::class, 'pending'])
            ->name('profile-verification.pending');
        Route::get('/verification-requests/{profileId}', [NetworkProfileVerificationController::class, 'review'])
            ->name('profile-verification.review');
        Route::patch('/verification-requests/{profileId}/approve', [NetworkProfileVerificationController::class, 'approve'])
            ->name('profile-verification.approve');
        Route::patch('/verification-requests/{profileId}/reject', [NetworkProfileVerificationController::class, 'reject'])
            ->name('profile-verification.reject');
        Route::patch('/verification-requests/{profileId}/revoke', [NetworkProfileVerificationController::class, 'revoke'])
            ->name('profile-verification.revoke');

        // -------------------------------------------------------------------------
        // Network Tenders — NETWORK-002 — Network Tender Foundation
        // CAP-160 — Tender Network V1
        // -------------------------------------------------------------------------
        Route::post('/tenders', [TenderController::class, 'create'])
            ->name('tenders.create');
        Route::get('/tenders', [TenderController::class, 'list'])
            ->name('tenders.list');
        Route::get('/tenders/{id}', [TenderController::class, 'show'])
            ->name('tenders.show');
        Route::patch('/tenders/{id}', [TenderController::class, 'update'])
            ->name('tenders.update');
        Route::patch('/tenders/{id}/publish', [TenderController::class, 'publish'])
            ->name('tenders.publish');
        Route::patch('/tenders/{id}/close', [TenderController::class, 'close'])
            ->name('tenders.close');
        Route::patch('/tenders/{id}/award', [TenderController::class, 'award'])
            ->name('tenders.award');

        // Network Bids — NETWORK-003 — Bid Submission
        Route::post('/bids', [BidController::class, 'submit'])
            ->name('bids.submit');
        Route::get('/bids/{id}', [BidController::class, 'show'])
            ->name('bids.show');
        Route::post('/bids/{id}/revisions', [BidController::class, 'revise'])
            ->name('bids.revise');
        Route::patch('/bids/{id}/withdraw', [BidController::class, 'withdraw'])
            ->name('bids.withdraw');

        // Network Evaluations — NETWORK-004 — Bid Evaluation
        Route::post('/tenders/{tenderId}/evaluation', [EvaluationController::class, 'start'])
            ->name('evaluations.start');
        Route::get('/evaluations/{id}', [EvaluationController::class, 'show'])
            ->name('evaluations.show');
        Route::patch('/evaluations/{id}/complete', [EvaluationController::class, 'complete'])
            ->name('evaluations.complete');
        Route::post('/evaluations/{id}/notes', [EvaluationController::class, 'addNote'])
            ->name('evaluations.notes.add');
        Route::get('/evaluations/{id}/bids', [EvaluationController::class, 'bids'])
            ->name('evaluations.bids');

        // NETWORK-005 — Award
        Route::post('/tenders/{tenderId}/award', [AwardController::class, 'create'])
            ->name('awards.create');
        Route::get('/tenders/{tenderId}/award', [AwardController::class, 'showByTender'])
            ->name('awards.showByTender');
        Route::get('/awards/{id}', [AwardController::class, 'show'])
            ->name('awards.show');

        // NETWORK-006 — Tender Invitations
        Route::post('/tenders/{tenderId}/invitations', [InvitationController::class, 'invite'])
            ->name('invitations.create');
        Route::get('/tenders/{tenderId}/invitations', [InvitationController::class, 'listForTender'])
            ->name('invitations.list');
        Route::get('/invitations/mine', [InvitationController::class, 'listMine'])
            ->name('invitations.mine');
        Route::patch('/invitations/{id}/respond', [InvitationController::class, 'respond'])
            ->name('invitations.respond');

        // NETWORK-007 — Clarifications
        Route::post('/clarifications', [ClarificationController::class, 'ask'])
            ->name('clarifications.ask');
        Route::patch('/clarifications/{id}/answer', [ClarificationController::class, 'answer'])
            ->name('clarifications.answer');
        Route::get('/tenders/{tenderId}/clarifications', [ClarificationController::class, 'listForTender'])
            ->name('clarifications.list');
    });

    // -------------------------------------------------------------------------
    // Core Ecosystem — Property / Project Identity / Participation / Engagement
    // Shared (mysql) schema — TenantContext for authority; no client tenant_slug.
    // -------------------------------------------------------------------------
    Route::prefix('v1/core')->name('core.')->group(function () {
        Route::post('/properties', [CoreProjectController::class, 'createProperty'])
            ->name('properties.create');
        Route::get('/properties', [CoreProjectController::class, 'listProperties'])
            ->name('properties.list');
        Route::get('/properties/{id}', [CoreProjectController::class, 'showProperty'])
            ->name('properties.show');

        Route::post('/project-identities', [CoreProjectController::class, 'createIdentity'])
            ->name('project-identities.create');
        Route::get('/project-identities', [CoreProjectController::class, 'listIdentities'])
            ->name('project-identities.list');
        Route::get('/project-identities/{id}', [CoreProjectController::class, 'showIdentity'])
            ->name('project-identities.show');
        Route::post('/project-identities/{identityId}/participations', [CoreProjectController::class, 'inviteParticipant'])
            ->name('participations.invite');
        Route::patch('/participations/{participationId}/respond', [CoreProjectController::class, 'respondParticipation'])
            ->name('participations.respond');

        Route::get('/project-link-requests', [CoreProjectController::class, 'listLinkRequests'])
            ->name('link-requests.list');
        Route::post('/project-link-requests', [CoreProjectController::class, 'createLinkRequest'])
            ->name('link-requests.create');
        Route::patch('/project-link-requests/{id}/decide', [CoreProjectController::class, 'decideLinkRequest'])
            ->name('link-requests.decide');

        Route::get('/engagements', [CoreProjectController::class, 'listEngagements'])
            ->name('engagements.list');
        Route::get('/engagements/{id}', [CoreProjectController::class, 'showEngagement'])
            ->name('engagements.show');
        Route::post('/engagements', [CoreProjectController::class, 'createEngagement'])
            ->name('engagements.create');

        Route::post('/submissions', [CollaborationController::class, 'submit'])
            ->name('submissions.create');
        Route::get('/project-identities/{identityId}/submissions', [CollaborationController::class, 'listSubmissions'])
            ->name('submissions.list');
        Route::post('/submissions/{submissionId}/reviews', [CollaborationController::class, 'review'])
            ->name('submissions.review');
        Route::post('/submissions/{submissionId}/owner-decisions', [CollaborationController::class, 'ownerDecide'])
            ->name('submissions.owner-decide');
    });
});
