<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use App\Registration\RegistrationAuditService;
use App\Registration\TenantRegistrationRequest;
use App\Registration\TenantRegistrationIdentityAuditEvent;
use App\Registration\TenantRegistrationService;
use App\Foundation\TenantSetting;

/**
 * TenantProvisioner — creates a new tenant following the NEW-002 lifecycle:
 *
 *   1. Insert Tenant record (status = pending) in shared schema.
 *   2. CREATE DATABASE for the tenant schema (server-generated name).
 *   3. Apply and record the per-tenant baseline migration.
 *   4. Apply and record IAM migrations.
 *   5. Apply and record all domain migrations.
 *   6. Verify the exact-schema runtime grants applied by the authorized external operator.
 *   7. Finalize registration data, then set status = active.
 *
 * A tenant is NEVER activated unless schema creation, migrations, grant verification,
 * and registration finalization all succeed.
 * This guarantees that every active tenant has a login-ready schema with
 * correct, verified MySQL privileges.
 *
 * DEC-MYSQL-001 (Path A):
 *   - Schema creation and migrations use the separate provisioning connection.
 *   - Runtime grants are applied by the authorized external operator and verified here.
 *   - Laravel does not issue GRANT; runtime has no GRANT OPTION or DDL.
 *
 * schema_name is ALWAYS derived server-side from the slug.
 * The client MUST NOT supply schema_name.
 */
class TenantProvisioner
{
    public function __construct(
        private readonly TenantBaselineMigrator $baselineMigrator,
        private readonly IamMigrator            $iamMigrator,
        private readonly TenantSchemaGrantor    $grantor,
        private readonly DomainMigrator         $domainMigrator,
    ) {}

    /**
     * Provision a new tenant.
     *
     * @param  string  $slug  URL-safe identifier (e.g. "acme")
     * @param  string  $name  Human-readable name
     * @return Tenant The newly provisioned, active tenant
     *
     * @throws RuntimeException if provisioning fails
     */
    public function provision(string $slug, string $name): Tenant
    {
        $this->validateSlug($slug);

        // Step 1 — Insert Tenant record (status = pending) in a single write.
        // schema_name is assigned directly on the model instance before save()
        // so that the INSERT includes it, eliminating the NULL schema_name
        // window that existed when two separate saves were used (F-002).
        // Direct assignment is intentional: schema_name is not in $fillable
        // because it must never be accepted from client input.
        $schemaName = Tenant::deriveSchemaName($slug);
        $this->assertSchemaDoesNotExist($schemaName);

        /** @var Tenant $tenant */
        $tenant = new Tenant([
            'slug' => $slug,
            'name' => $name,
            'status' => 'pending',
        ]);
        $tenant->schema_name = $schemaName;
        $tenant->save();

        return $this->provisionPendingTenant($tenant, $slug);
    }

    /**
     * Provision only an already-approved registration through the operator CLI.
     * The request is linked to a pending tenant before DDL begins so retries can
     * resume that tenant instead of creating another one.
     */
    public function provisionRegistration(int $requestId): Tenant
    {
        $lockName = 'binaa2_regprov_'.$requestId;
        $lockResult = DB::connection('mysql_provisioning')
            ->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
        if ((int) ($lockResult->acquired ?? 0) !== 1) {
            throw new RuntimeException('Provisioning is already running for this registration.');
        }

        try {
            $registration = TenantRegistrationRequest::on('mysql')->findOrFail($requestId);
            if ($registration->status !== 'approved_pending_provisioning') {
                throw new RuntimeException('Only an approved registration can be provisioned.');
            }
            app(TenantRegistrationService::class)->assertReadyForProvisioning($registration);

            $allowExistingSchema = false;
            $tenant = DB::connection('mysql')->transaction(function () use ($requestId, &$allowExistingSchema): Tenant {
                $locked = TenantRegistrationRequest::on('mysql')->whereKey($requestId)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'approved_pending_provisioning') {
                    throw new RuntimeException('Registration approval changed before provisioning began.');
                }

                if ($locked->tenant_id !== null) {
                    $existing = Tenant::on('mysql')->whereKey($locked->tenant_id)->firstOrFail();
                    $expectedSchema = Tenant::deriveSchemaName($locked->slug);
                    if (
                        $existing->status !== 'pending'
                        || $existing->slug !== $locked->slug
                        || $existing->schema_name !== $expectedSchema
                    ) {
                        throw new RuntimeException('The linked tenant is not in a resumable pending state.');
                    }

                    $allowExistingSchema = TenantMigration::on('mysql')
                        ->where('tenant_id', $existing->id)
                        ->exists();

                    return $existing;
                }

                $this->validateSlug($locked->slug);
                $schemaName = Tenant::deriveSchemaName($locked->slug);
                $this->assertSchemaDoesNotExist($schemaName);
                $tenant = new Tenant([
                    'slug' => $locked->slug,
                    'name' => $locked->name,
                    'legal_type' => $locked->legal_type,
                    'activity_type' => $locked->activity_type,
                    'phone' => $locked->phone,
                    'email' => $locked->applicant_email,
                    'responsible_name' => $locked->responsible_name,
                    'responsible_qid' => $locked->responsible_qid,
                    'qid_expires_at' => $locked->qid_expires_at,
                    'status' => 'pending',
                ]);
                $tenant->schema_name = $schemaName;
                $tenant->save();
                $locked->tenant_id = $tenant->id;
                $locked->save();

                return $tenant;
            });

            $this->provisionPendingTenant($tenant, $tenant->slug, function (Tenant $provisionedTenant): void {
                DB::connection('mysql')->transaction(function () use ($provisionedTenant): void {
                    $registration = TenantRegistrationRequest::on('mysql')
                        ->where('tenant_id', $provisionedTenant->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    if ($registration->status !== 'approved_pending_provisioning') {
                        throw new RuntimeException('Registration is no longer awaiting provisioning.');
                    }
                    app(TenantRegistrationService::class)->assertReadyForProvisioning($registration);

                    $identifiers = $registration->identifiers()->get();
                    foreach ($identifiers as $identifier) {
                        $identifier->tenant_id = $provisionedTenant->id;
                        $identifier->registration_request_id = null;
                        $identifier->save();
                    }
                    TenantSetting::on('mysql')->updateOrCreate(
                        ['tenant_id' => $provisionedTenant->id, 'key' => 'registration.request_number'],
                        ['value' => $registration->request_number],
                    );

                    $from = $registration->status;
                    $registration->status = 'provisioned';
                    $registration->save();
                    app(RegistrationAuditService::class)->record(
                        $registration->id,
                        'operator',
                        null,
                        'provisioning.completed',
                        $from,
                        'provisioned',
                    );
                    TenantRegistrationIdentityAuditEvent::create([
                        'actor_type' => 'operator',
                        'actor_id' => null,
                        'subject_type' => 'tenant',
                        'subject_id' => $provisionedTenant->id,
                        'event' => 'tenant.provisioned_from_registration',
                        'metadata' => ['registration_request_id' => $registration->id],
                        'created_at' => now(),
                    ]);
                });
            }, $allowExistingSchema);

            return Tenant::on('mysql')->findOrFail($tenant->id);
        } finally {
            DB::connection('mysql_provisioning')
                ->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    private function provisionPendingTenant(
        Tenant $tenant,
        string $slug,
        ?callable $beforeActivation = null,
        bool $allowExistingSchema = false,
    ): Tenant {
        $schemaName = Tenant::deriveSchemaName($slug);

        try {
            $this->createSchema($schemaName, $allowExistingSchema);
            $runtimeUser = config('database.connections.tenant.username')
                ?? config('database.connections.mysql.username')
                ?? env('DB_USERNAME');
            $runtimeHost = config('database.connections.mysql.host', '127.0.0.1');
            $this->baselineMigrator->run($tenant);
            $this->iamMigrator->run($tenant);
            $this->domainMigrator->run($tenant);
            $this->grantor->verifyGrantedPrivileges($schemaName, $runtimeUser, $runtimeHost);

            DB::connection('mysql')->transaction(function () use ($tenant, $beforeActivation): void {
                if ($beforeActivation !== null) {
                    $beforeActivation($tenant);
                }
                $lockedTenant = Tenant::on('mysql')->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                if ($lockedTenant->status !== 'pending') {
                    throw new RuntimeException('Tenant is not pending and cannot be activated.');
                }
                $lockedTenant->status = 'active';
                $lockedTenant->save();
            });
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Tenant provisioning failed for slug [{$slug}]: ".$e->getMessage(),
                previous: $e,
            );
        }

        return $tenant->fresh();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function validateSlug(string $slug): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9\-]{1,61}[a-z0-9]$/', $slug)) {
            throw new \InvalidArgumentException(
                "Invalid tenant slug [{$slug}]. Must be 3–63 lowercase alphanumeric/hyphen characters."
            );
        }

        // The guarded provisioning connection is exposed only by the approved
        // tenant:provision-registration command.
        if (Tenant::on('mysql_provisioning')->where('slug', $slug)->exists()) {
            throw new \InvalidArgumentException("Tenant slug [{$slug}] is already taken.");
        }
    }

    private function assertSchemaDoesNotExist(string $schemaName): void
    {
        $existing = DB::connection('mysql_provisioning')->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$schemaName],
        );

        if ($existing !== null) {
            throw new RuntimeException(
                "Tenant schema collision for [{$schemaName}]: an unlinked schema already exists."
            );
        }
    }

    /**
     * CREATE DATABASE `{schemaName}` using the operator-only provisioning connection.
     *
     * DEC-MYSQL-001: Uses mysql_provisioning connection (not runtime user).
     * The schema name is validated server-side before use.
     */
    private function createSchema(string $schemaName, bool $allowExistingSchema = false): void
    {
        // Extra safety: only allow names matching our server-generated pattern.
        if (! preg_match('/^binaa2_tenant_[a-z0-9_]+$/', $schemaName)) {
            throw new RuntimeException("Unsafe schema name [{$schemaName}] rejected.");
        }

        $existing = DB::connection('mysql_provisioning')->selectOne(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$schemaName],
        );
        if ($existing !== null) {
            if ($allowExistingSchema) {
                return;
            }

            throw new RuntimeException(
                "Tenant schema collision for [{$schemaName}]: existing schema ownership is not established."
            );
        }

        if ($allowExistingSchema) {
            throw new RuntimeException(
                "Tenant schema [{$schemaName}] is missing during provisioning resume; refusing to recreate it."
            );
        }

        // Use backtick-quoted identifier — PDO does not support binding identifiers.
        // Plain CREATE also fails closed if a schema appears after the preflight query.
        DB::connection('mysql_provisioning')->statement(
            "CREATE DATABASE `{$schemaName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }
}
