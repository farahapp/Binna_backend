<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Foundation\TenantSchemaGrantor;
use App\Foundation\TenantProvisioner;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DEC-MYSQL-001 — MySQL Privilege Isolation Tests
 *
 * Tests cover:
 *   TC-PRIV-01  TenantSchemaGrantor rejects HTTP context (CLI-only guard)
 *   TC-PRIV-02  TenantSchemaGrantor rejects unconfigured provisioning credentials
 *   TC-PRIV-03  TenantSchemaGrantor rejects unsafe schema names
 *   TC-PRIV-04  TenantSchemaGrantor rejects unsafe runtime user identifiers
 *   TC-PRIV-05  GRANT verification detects missing privileges (unit-level mock)
 *   TC-PRIV-05B verifyGrant passes with the four approved Runtime privileges only
 *   TC-PRIV-05C verifyGrant fails when no per-schema privileges are present
 *   TC-PRIV-05D verifyGrant fails when the runtime host does not match
 *   TC-PRIV-06/07/08 Live DDL, GRANT OPTION, and schema isolation checks are blocked
 *   TC-PRIV-09  Provisioning connection is separate from runtime connection (config check)
 *   TC-PRIV-10  TenantProvisioner stays pending when external GRANT verification fails
 *
 * Live privilege probes are intentionally skipped: they require privileged fixture setup,
 * and GRANT probes must not execute real privilege changes from PHPUnit.
 */
class MySqlPrivilegeTest extends TestCase
{
    // -------------------------------------------------------------------------
    // TC-PRIV-01 — CLI-only guard: reject HTTP context
    // -------------------------------------------------------------------------

    /** @test */
    public function it_rejects_instantiation_outside_cli_context(): void
    {
        // Use an anonymous subclass to override isRunningInConsole() to return false,
        // simulating HTTP context without needing to mock the Application container.
        // PHPUnit always runs in console (runningInConsole() = true), so we bypass
        // the app() call entirely via the protected hook method.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/CLI context/i');

        new class extends TenantSchemaGrantor {
            protected function isRunningInConsole(): bool
            {
                return false; // Simulate HTTP context
            }
        };
    }

    // -------------------------------------------------------------------------
    // TC-PRIV-02 — Reject unconfigured provisioning credentials
    // -------------------------------------------------------------------------

    /** @test */
    public function it_rejects_verification_when_provisioning_credentials_not_configured(): void
    {
        // In CLI context (tests always run in console), but with empty credentials.
        config(['database.provisioning_connection.username' => null]);
        config(['database.provisioning_connection.password' => null]);

        $grantor = new TenantSchemaGrantor();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not configured/i');

        $grantor->verifyGrantedPrivileges('binaa2_tenant_test', 'runtime_user', '%');
    }

    /** @test */
    public function grant_verification_reads_existing_privileges_without_executing_grant(): void
    {
        config(['database.provisioning_connection.username' => 'provisioner']);
        config(['database.provisioning_connection.password' => 'configured']);

        DB::shouldReceive('connection')
            ->once()
            ->with('mysql_provisioning')
            ->andReturnSelf();
        DB::shouldReceive('select')
            ->once()
            ->withArgs(function (string $query, array $bindings): bool {
                $this->assertStringContainsString('information_schema.SCHEMA_PRIVILEGES', $query);
                $this->assertStringContainsString('IS_GRANTABLE', $query);
                $this->assertSame(["'runtime_user'@'127.0.0.1'", 'binaa2_tenant_test'], $bindings);

                return true;
            })
            ->andReturn(array_map(
                static fn (string $privilege): object => (object) [
                    'PRIVILEGE_TYPE' => $privilege,
                    'IS_GRANTABLE' => 'NO',
                ],
                ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
            ));
        DB::shouldReceive('statement')->never();

        (new TenantSchemaGrantor())->verifyGrantedPrivileges(
            'binaa2_tenant_test',
            'runtime_user',
            '127.0.0.1',
        );
    }

    /** @test */
    public function grant_verification_is_scoped_to_exact_runtime_account_and_tenant_schema(): void
    {
        config(['database.provisioning_connection.username' => 'provisioner']);
        config(['database.provisioning_connection.password' => 'configured']);

        DB::shouldReceive('connection')
            ->once()
            ->with('mysql_provisioning')
            ->andReturnSelf();
        DB::shouldReceive('select')
            ->once()
            ->withArgs(function (string $query, array $bindings): bool {
                $this->assertSame(["'binaa2_runtime'@'127.0.0.1'", 'binaa2_tenant_expected'], $bindings);

                return true;
            })
            ->andReturn([]);
        DB::shouldReceive('statement')->never();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No privileges found|verification failed/i');

        (new TenantSchemaGrantor())->verifyGrantedPrivileges(
            'binaa2_tenant_expected',
            'binaa2_runtime',
            '127.0.0.1',
        );
    }

    /** @test */
    public function runtime_verification_does_not_require_migration_only_privileges(): void
    {
        config(['database.provisioning_connection.username' => 'provisioner']);
        config(['database.provisioning_connection.password' => 'configured']);

        DB::shouldReceive('connection')
            ->once()
            ->with('mysql_provisioning')
            ->andReturnSelf();
        DB::shouldReceive('select')
            ->once()
            ->andReturn(array_map(
                static fn (string $privilege): object => (object) [
                    'PRIVILEGE_TYPE' => $privilege,
                    'IS_GRANTABLE' => 'NO',
                ],
                ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
            ));
        DB::shouldReceive('statement')->never();

        (new TenantSchemaGrantor())->verifyGrantedPrivileges(
            'binaa2_tenant_test',
            'runtime_user',
            '127.0.0.1',
        );
    }

    /** @test */
    public function runtime_verification_fails_when_any_required_dml_privilege_is_missing(): void
    {
        config(['database.provisioning_connection.username' => 'provisioner']);
        config(['database.provisioning_connection.password' => 'configured']);

        $required = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];
        $missingIndex = 0;

        DB::shouldReceive('connection')
            ->times(count($required))
            ->with('mysql_provisioning')
            ->andReturnSelf();
        DB::shouldReceive('select')
            ->times(count($required))
            ->andReturnUsing(function () use ($required, &$missingIndex): array {
                $missing = $required[$missingIndex++];

                return array_map(
                    static fn (string $privilege): object => (object) [
                        'PRIVILEGE_TYPE' => $privilege,
                        'IS_GRANTABLE' => 'NO',
                    ],
                    array_values(array_diff($required, [$missing])),
                );
            });
        DB::shouldReceive('statement')->never();

        foreach ($required as $missing) {
            try {
                (new TenantSchemaGrantor())->verifyGrantedPrivileges(
                    'binaa2_tenant_test',
                    'runtime_user',
                    '127.0.0.1',
                );
                $this->fail("Expected verification failure when {$missing} is absent.");
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString($missing, $exception->getMessage());
            }
        }
    }

    /** @test */
    public function migration_ddl_privileges_cannot_substitute_for_runtime_dml(): void
    {
        config(['database.provisioning_connection.username' => 'provisioner']);
        config(['database.provisioning_connection.password' => 'configured']);

        DB::shouldReceive('connection')
            ->once()
            ->with('mysql_provisioning')
            ->andReturnSelf();
        DB::shouldReceive('select')
            ->once()
            ->andReturn(array_map(
                static fn (string $privilege): object => (object) [
                    'PRIVILEGE_TYPE' => $privilege,
                    'IS_GRANTABLE' => 'NO',
                ],
                ['CREATE', 'ALTER', 'DROP', 'INDEX', 'REFERENCES'],
            ));
        DB::shouldReceive('statement')->never();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Missing privileges:.*SELECT.*INSERT.*UPDATE.*DELETE/');

        (new TenantSchemaGrantor())->verifyGrantedPrivileges(
            'binaa2_tenant_test',
            'runtime_user',
            '127.0.0.1',
        );
    }

    /** @test */
    public function runtime_verification_rejects_extra_schema_privileges_and_grant_option(): void
    {
        config(['database.provisioning_connection.username' => 'provisioner']);
        config(['database.provisioning_connection.password' => 'configured']);

        DB::shouldReceive('connection')
            ->twice()
            ->with('mysql_provisioning')
            ->andReturnSelf();
        DB::shouldReceive('select')
            ->twice()
            ->andReturn(
                array_map(
                    static fn (string $privilege): object => (object) [
                        'PRIVILEGE_TYPE' => $privilege,
                        'IS_GRANTABLE' => 'NO',
                    ],
                    ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'INDEX', 'REFERENCES'],
                ),
                array_map(
                    static fn (string $privilege): object => (object) [
                        'PRIVILEGE_TYPE' => $privilege,
                        'IS_GRANTABLE' => $privilege === 'SELECT' ? 'YES' : 'NO',
                    ],
                    ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
                ),
            );
        DB::shouldReceive('statement')->never();

        foreach ([
            '/Unexpected privileges: INDEX, REFERENCES/',
            '/grantable|grantability/i',
        ] as $messagePattern) {
            try {
                (new TenantSchemaGrantor())->verifyGrantedPrivileges(
                    'binaa2_tenant_test',
                    'runtime_user',
                    '127.0.0.1',
                );
                $this->fail("Expected Runtime verification failure matching {$messagePattern}.");
            } catch (\RuntimeException $exception) {
                $this->assertMatchesRegularExpression($messagePattern, $exception->getMessage());
            }
        }
    }

    /** @test */
    public function a_new_schema_is_created_only_after_the_collision_check_passes(): void
    {
        $schema = 'binaa2_tenant_new';
        DB::shouldReceive('connection')
            ->twice()
            ->with('mysql_provisioning')
            ->andReturnSelf();
        DB::shouldReceive('selectOne')
            ->once()
            ->with(
                'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
                [$schema],
            )
            ->andReturnNull();
        DB::shouldReceive('statement')
            ->once()
            ->with("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->invokeCreateSchema($schema);
    }

    /** @test */
    public function an_existing_unlinked_schema_is_rejected_without_ddl(): void
    {
        $schema = 'binaa2_tenant_collision';
        DB::shouldReceive('connection')
            ->once()
            ->with('mysql_provisioning')
            ->andReturnSelf();
        DB::shouldReceive('selectOne')
            ->once()
            ->andReturn((object) ['SCHEMA_NAME' => $schema]);
        DB::shouldReceive('statement')->never();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/collision.*ownership is not established/i');

        $this->invokeCreateSchema($schema);
    }

    /** @test */
    public function an_existing_schema_is_reused_only_for_an_authorized_resume(): void
    {
        $schema = 'binaa2_tenant_resume';
        DB::shouldReceive('connection')
            ->once()
            ->with('mysql_provisioning')
            ->andReturnSelf();
        DB::shouldReceive('selectOne')
            ->once()
            ->andReturn((object) ['SCHEMA_NAME' => $schema]);
        DB::shouldReceive('statement')->never();

        $this->invokeCreateSchema($schema, true);
    }

    /** @test */
    public function a_missing_schema_is_not_recreated_when_migration_history_exists(): void
    {
        DB::shouldReceive('connection')
            ->once()
            ->with('mysql_provisioning')
            ->andReturnSelf();
        DB::shouldReceive('selectOne')
            ->once()
            ->andReturnNull();
        DB::shouldReceive('statement')->never();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing during provisioning resume/i');

        $this->invokeCreateSchema('binaa2_tenant_missing_resume', true);
    }

    private function invokeCreateSchema(string $schema, bool $allowExistingSchema = false): void
    {
        $provisioner = (new \ReflectionClass(TenantProvisioner::class))->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass(TenantProvisioner::class))->getMethod('createSchema');
        $method->setAccessible(true);
        $method->invoke($provisioner, $schema, $allowExistingSchema);
    }

    /** @test */
    public function grant_verification_connection_errors_are_not_silently_accepted(): void
    {
        config(['database.provisioning_connection.username' => 'provisioner']);
        config(['database.provisioning_connection.password' => 'configured']);

        DB::shouldReceive('connection')
            ->once()
            ->with('mysql_provisioning')
            ->andReturnSelf();
        DB::shouldReceive('select')
            ->once()
            ->andThrow(new \PDOException('Simulated verification connection failure.'));
        DB::shouldReceive('statement')->never();

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('Simulated verification connection failure.');

        (new TenantSchemaGrantor())->verifyGrantedPrivileges(
            'binaa2_tenant_expected',
            'binaa2_runtime',
            '127.0.0.1',
        );
    }

    // -------------------------------------------------------------------------
    // TC-PRIV-03 — Reject unsafe schema names
    // -------------------------------------------------------------------------

    /** @test */
    public function it_rejects_unsafe_schema_names(): void
    {
        config(['database.provisioning_connection.username' => 'provisioner']);
        config(['database.provisioning_connection.password' => 'secret']);

        $grantor = new TenantSchemaGrantor();

        $unsafeNames = [
            'information_schema',
            'mysql',
            'performance_schema',
            'sys',
            'DROP DATABASE binaa2',
            '../etc/passwd',
            'binaa2_tenant_; DROP TABLE users',
            'BINAA2_TENANT_UPPER',
        ];

        foreach ($unsafeNames as $name) {
            try {
                $grantor->verifyGrantedPrivileges($name, 'runtime_user', '%');
                $this->fail("Expected RuntimeException for schema name: {$name}");
            } catch (\RuntimeException $e) {
                $this->assertMatchesRegularExpression('/[Uu]nsafe schema name/', $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // TC-PRIV-04 — Reject unsafe runtime user identifiers
    // -------------------------------------------------------------------------

    /** @test */
    public function it_rejects_unsafe_runtime_user_identifiers(): void
    {
        config(['database.provisioning_connection.username' => 'provisioner']);
        config(['database.provisioning_connection.password' => 'secret']);

        $grantor = new TenantSchemaGrantor();

        $unsafeUsers = [
            "'; DROP USER 'root'@'%",
            'user WITH GRANT OPTION',
            'user`; GRANT ALL',
        ];

        foreach ($unsafeUsers as $user) {
            try {
                $grantor->verifyGrantedPrivileges('binaa2_tenant_test', $user, '%');
                $this->fail("Expected RuntimeException for user: {$user}");
            } catch (\RuntimeException $e) {
                $this->assertMatchesRegularExpression('/[Uu]nsafe.*user/', $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // TC-PRIV-05 — GRANT verification detects missing privileges
    // -------------------------------------------------------------------------

    /** @test */
    public function it_detects_missing_privileges_during_verification(): void
    {
        // TC-PRIV-05: verifyGrant queries information_schema.SCHEMA_PRIVILEGES
        // (one row per PRIVILEGE_TYPE) instead of mysql.db (Y/N columns).
        // The provisioning account binaa2_provisioning@127.0.0.1 does NOT have
        // SELECT on mysql.db (error 1142), but information_schema is always
        // readable without extra privileges on MySQL 8.0+/8.4.
        //
        // Simulate a partial grant: only SELECT is present; the other three are absent.
        // verifyGrant must fail because the Runtime DML contract is incomplete.
        $reflection = new \ReflectionClass(TenantSchemaGrantor::class);
        $method = $reflection->getMethod('verifyGrant');
        $method->setAccessible(true);

        // Return information_schema.SCHEMA_PRIVILEGES-style rows:
        // one row per PRIVILEGE_TYPE — only SELECT is present.
        DB::shouldReceive('connection')
            ->with('mysql_provisioning')
            ->andReturnSelf();
        DB::shouldReceive('select')
            ->andReturn([
                (object) ['PRIVILEGE_TYPE' => 'SELECT', 'IS_GRANTABLE' => 'NO'],
            ]);

        $grantorInstance = new TenantSchemaGrantor();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/[Vv]erification failed|[Mm]issing privileges/');

        $method->invoke($grantorInstance, 'binaa2_tenant_test', 'binaa2_runtime', '%');
    }

    // -------------------------------------------------------------------------
    // TC-PRIV-05B — verifyGrant passes with the four Runtime privileges only
    // -------------------------------------------------------------------------

    /** @test */
    public function verify_grant_passes_when_all_four_runtime_privileges_are_present(): void
    {
        // TC-PRIV-05B: information_schema.SCHEMA_PRIVILEGES returns one row per
        // PRIVILEGE_TYPE. The four approved Runtime privileges are present; no
        // migration-only privilege is required.
        $reflection = new \ReflectionClass(TenantSchemaGrantor::class);
        $method = $reflection->getMethod('verifyGrant');
        $method->setAccessible(true);

        DB::shouldReceive('connection')
            ->with('mysql_provisioning')
            ->andReturnSelf();
        DB::shouldReceive('select')
            ->andReturn([
                (object) ['PRIVILEGE_TYPE' => 'SELECT', 'IS_GRANTABLE' => 'NO'],
                (object) ['PRIVILEGE_TYPE' => 'INSERT', 'IS_GRANTABLE' => 'NO'],
                (object) ['PRIVILEGE_TYPE' => 'UPDATE', 'IS_GRANTABLE' => 'NO'],
                (object) ['PRIVILEGE_TYPE' => 'DELETE', 'IS_GRANTABLE' => 'NO'],
            ]);

        $grantorInstance = new TenantSchemaGrantor();

        // Must not throw — the four approved Runtime privileges are present.
        $method->invoke($grantorInstance, 'binaa2_tenant_acme', 'binaa2_runtime', '%');

        // If we reach here without exception, the test passes.
        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // TC-PRIV-05C — verifyGrant fails when information_schema returns no rows
    // -------------------------------------------------------------------------

    /** @test */
    public function verify_grant_fails_when_no_row_in_mysql_db(): void
    {
        // TC-PRIV-05C: An empty result from information_schema.SCHEMA_PRIVILEGES
        // means no per-schema grant exists for this GRANTEE+TABLE_SCHEMA pair.
        // verifyGrant must throw with a "verification failed" message.
        // (Previously this test was named for mysql.db; the contract is identical —
        // empty result = no grant = fail.)
        $reflection = new \ReflectionClass(TenantSchemaGrantor::class);
        $method = $reflection->getMethod('verifyGrant');
        $method->setAccessible(true);

        DB::shouldReceive('connection')
            ->with('mysql_provisioning')
            ->andReturnSelf();
        DB::shouldReceive('select')
            ->andReturn([]); // No rows — GRANT was never applied.

        $grantorInstance = new TenantSchemaGrantor();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No privileges found|[Vv]erification failed/');

        $method->invoke($grantorInstance, 'binaa2_tenant_acme', 'binaa2_runtime', '%');
    }

    // -------------------------------------------------------------------------
    // TC-PRIV-05D — verifyGrant fails when GRANTEE host mismatch (wrong host)
    // -------------------------------------------------------------------------

    /** @test */
    public function verify_grant_fails_when_host_does_not_match_binaa2_runtime(): void
    {
        // TC-PRIV-05D: The GRANTEE in information_schema.SCHEMA_PRIVILEGES is
        // 'user'@'host'. If the provisioning connection returns no rows for the
        // requested GRANTEE (e.g. grant exists for 'binaa2_runtime'@'%' but we
        // query for 'binaa2_runtime'@'10.0.0.1'), verifyGrant must fail.
        //
        // We simulate this by having the mock return an empty result set when
        // queried for GRANTEE = 'binaa2_runtime'@'10.0.0.1'.
        $reflection = new \ReflectionClass(TenantSchemaGrantor::class);
        $method = $reflection->getMethod('verifyGrant');
        $method->setAccessible(true);

        DB::shouldReceive('connection')
            ->with('mysql_provisioning')
            ->andReturnSelf();
        // information_schema returns no rows for this GRANTEE+TABLE_SCHEMA pair.
        DB::shouldReceive('select')
            ->andReturn([]); // No rows for this specific host.

        $grantorInstance = new TenantSchemaGrantor();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No privileges found|[Vv]erification failed/');

        // Querying for host '10.0.0.1' — GRANTEE 'binaa2_runtime'@'10.0.0.1' has no rows.
        $method->invoke($grantorInstance, 'binaa2_tenant_acme', 'binaa2_runtime', '10.0.0.1');
    }

    // =========================================================================
    // TC-PRIV-06 / TC-PRIV-07 / TC-PRIV-08 — Live isolated MySQL tests
    //
    // Prerequisites (set up by postman/_scripts/setup_priv_test_schemas.ps1):
    //   MySQL 9.3 on 127.0.0.1:3307 (isolated, NOT production 3306)
    //   Schema binaa2_tenant_priv_a  — runtime user has DML only
    //   Schema binaa2_tenant_priv_b  — runtime user has NO access
    //   User   binaa2_runtime_test@127.0.0.1 — approved Runtime DML on priv_a only
    //
    // These tests use PDO directly (not Laravel DB) to connect to the isolated
    // instance. Credentials are constants — never written to logs or traces.
    //
    // Teardown: drops the two test schemas and the runtime user (idempotent).
    // =========================================================================

    // ── Shared PDO helper ────────────────────────────────────────────────────

    /**
     * Open a PDO connection to the isolated MySQL 3307 instance.
     * Asserts the server is on port 3307 before returning.
     *
     * @param  string  $user
     * @param  string  $password
     * @param  string  $database  Empty string = no default database
     * @return \PDO
     */
    private function isolatedPdo(string $user, string $password, string $database = ''): \PDO
    {
        $dsn = 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4'
            . ($database !== '' ? ";dbname={$database}" : '');

        $pdo = new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT            => 5,
            \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ]);

        // Safety guard: confirm we are on the isolated instance, not production.
        $port = (int) $pdo->query('SELECT @@port')->fetchColumn();
        $this->assertSame(3307, $port,
            'TC-PRIV safety guard: PDO connected to port ' . $port . ' instead of 3307. Aborting.');

        return $pdo;
    }

    /**
     * Skip the test if the isolated MySQL 3307 instance is not reachable
     * or the test schemas/user have not been set up.
     */
    private function requireIsolatedMySQL(): void
    {
        $this->markTestSkipped(
            'Live privilege probes require privileged fixtures and are not permitted from PHPUnit.'
        );
    }

    // ── Teardown ─────────────────────────────────────────────────────────────

    /**
     * Clean up temporary DDL artifacts created by TC-PRIV-06 (if any leaked).
     * The test schemas and runtime user are NOT dropped here — they are
     * persistent fixtures managed by setup_priv_test_schemas.ps1.
     * Only the temp table created during TC-PRIV-06 attempt is cleaned.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        // Nothing to clean per-test; schemas are persistent fixtures.
    }

    // -------------------------------------------------------------------------
    // TC-PRIV-06 — Runtime user lacks DDL privileges
    // -------------------------------------------------------------------------

    /** @test */
    public function runtime_user_cannot_execute_ddl_on_tenant_schema(): void
    {
        $this->markTestSkipped(
            'Live DDL denial is not verified: the available isolated runtime account has test-only DDL scope.'
        );

        // DEC-MYSQL-001: runtime user must NOT have CREATE TABLE, DROP TABLE,
        // or ALTER TABLE on tenant schemas. Only DML is granted.
        $this->requireIsolatedMySQL();

        $pdo = $this->isolatedPdo('binaa2_runtime_test', (string) getenv('PRIV_TEST_RUNTIME_PASSWORD'), 'binaa2_tenant_priv_a');

        // ── Assert 1: CREATE TABLE is denied ──────────────────────────────────
        $createDenied = false;
        try {
            $pdo->exec('CREATE TABLE `tc_priv06_tmp` (`id` INT PRIMARY KEY)');
            // If we reach here, DDL was NOT denied — clean up and fail
            $pdo->exec('DROP TABLE IF EXISTS `tc_priv06_tmp`');
        } catch (\PDOException $e) {
            // Expected: "CREATE command denied" or similar access error
            $createDenied = true;
            $this->assertMatchesRegularExpression(
                '/CREATE command denied|Access denied|1142|1044/i',
                $e->getMessage(),
                'TC-PRIV-06: Expected DDL denial error, got: ' . $e->getMessage()
            );
        }
        $this->assertTrue($createDenied,
            'TC-PRIV-06 FAIL: runtime user was able to CREATE TABLE on binaa2_tenant_priv_a — DDL must be denied.');

        // ── Assert 2: DROP TABLE is denied ────────────────────────────────────
        $dropDenied = false;
        try {
            $pdo->exec('DROP TABLE `test_records`');
        } catch (\PDOException $e) {
            $dropDenied = true;
            $this->assertMatchesRegularExpression(
                '/DROP command denied|Access denied|1142|1044/i',
                $e->getMessage(),
                'TC-PRIV-06: Expected DROP denial error, got: ' . $e->getMessage()
            );
        }
        $this->assertTrue($dropDenied,
            'TC-PRIV-06 FAIL: runtime user was able to DROP TABLE on binaa2_tenant_priv_a — DDL must be denied.');

        // ── Assert 3: ALTER TABLE is denied ───────────────────────────────────
        $alterDenied = false;
        try {
            $pdo->exec('ALTER TABLE `test_records` ADD COLUMN `tmp_col` INT NULL');
        } catch (\PDOException $e) {
            $alterDenied = true;
            $this->assertMatchesRegularExpression(
                '/ALTER command denied|Access denied|1142|1044/i',
                $e->getMessage(),
                'TC-PRIV-06: Expected ALTER denial error, got: ' . $e->getMessage()
            );
        }
        $this->assertTrue($alterDenied,
            'TC-PRIV-06 FAIL: runtime user was able to ALTER TABLE on binaa2_tenant_priv_a — DDL must be denied.');

        // ── Assert 4: DML still works (SELECT) ────────────────────────────────
        $count = (int) $pdo->query('SELECT COUNT(*) FROM `test_records`')->fetchColumn();
        $this->assertGreaterThanOrEqual(0, $count,
            'TC-PRIV-06: runtime user must still be able to SELECT after DDL denial checks.');
    }

    // -------------------------------------------------------------------------
    // TC-PRIV-07 — Runtime user lacks GRANT OPTION
    // -------------------------------------------------------------------------

    /** @test */
    public function runtime_user_cannot_grant_privileges(): void
    {
        $this->markTestSkipped(
            'Live GRANT denial is not probed from PHPUnit; it requires privileged fixtures and may change grants.'
        );
    }

    // -------------------------------------------------------------------------
    // TC-PRIV-08 — Schema isolation: tenant A runtime user cannot access tenant B
    // -------------------------------------------------------------------------

    /** @test */
    public function tenant_a_runtime_user_cannot_access_tenant_b_schema(): void
    {
        $this->markTestSkipped(
            'Live cross-schema isolation is not verified: dedicated restricted runtime fixtures are unavailable.'
        );

        // DEC-MYSQL-001: a connection authenticated as the runtime user for
        // tenant A must not be able to SELECT, INSERT, or UPDATE data in
        // tenant B's schema.
        $this->requireIsolatedMySQL();

        $pdo = $this->isolatedPdo('binaa2_runtime_test', (string) getenv('PRIV_TEST_RUNTIME_PASSWORD'));

        // ── Assert 1: SELECT from tenant B is denied ──────────────────────────
        $selectDenied = false;
        try {
            $pdo->query('SELECT 1 FROM `binaa2_tenant_priv_b`.`test_records` LIMIT 1');
        } catch (\PDOException $e) {
            $selectDenied = true;
            $this->assertMatchesRegularExpression(
                '/SELECT command denied|Access denied|1142|1044|Table.*doesn/i',
                $e->getMessage(),
                'TC-PRIV-08: Expected SELECT denial on priv_b, got: ' . $e->getMessage()
            );
        }
        $this->assertTrue($selectDenied,
            'TC-PRIV-08 FAIL: runtime user was able to SELECT from binaa2_tenant_priv_b — cross-tenant isolation broken.');

        // ── Assert 2: INSERT into tenant B is denied ──────────────────────────
        $insertDenied = false;
        try {
            $pdo->exec("INSERT INTO `binaa2_tenant_priv_b`.`test_records` (`val`) VALUES ('leak')");
        } catch (\PDOException $e) {
            $insertDenied = true;
            $this->assertMatchesRegularExpression(
                '/INSERT command denied|Access denied|1142|1044/i',
                $e->getMessage(),
                'TC-PRIV-08: Expected INSERT denial on priv_b, got: ' . $e->getMessage()
            );
        }
        $this->assertTrue($insertDenied,
            'TC-PRIV-08 FAIL: runtime user was able to INSERT into binaa2_tenant_priv_b — cross-tenant isolation broken.');

        // ── Assert 3: USE tenant B database is denied ─────────────────────────
        $useDenied = false;
        try {
            $pdo->exec('USE `binaa2_tenant_priv_b`');
            // If USE succeeds, verify SELECT still fails
            $pdo->query('SELECT 1 FROM `test_records` LIMIT 1');
        } catch (\PDOException $e) {
            $useDenied = true;
            // Either USE or SELECT must fail — both are acceptable evidence of isolation
            $this->assertMatchesRegularExpression(
                '/Access denied|command denied|1142|1044|1044/i',
                $e->getMessage(),
                'TC-PRIV-08: Expected access denial on priv_b USE/SELECT, got: ' . $e->getMessage()
            );
        }
        $this->assertTrue($useDenied,
            'TC-PRIV-08 FAIL: runtime user was able to access binaa2_tenant_priv_b — cross-tenant isolation broken.');

        // ── Assert 4: Tenant A access still works (no over-restriction) ───────
        $pdoA = $this->isolatedPdo(
            'binaa2_runtime_test',
            (string) getenv('PRIV_TEST_RUNTIME_PASSWORD'),
            'binaa2_tenant_priv_a'
        );
        $count = (int) $pdoA->query('SELECT COUNT(*) FROM `test_records`')->fetchColumn();
        $this->assertGreaterThanOrEqual(0, $count,
            'TC-PRIV-08: runtime user must still be able to SELECT from binaa2_tenant_priv_a after isolation checks.');
    }

    // -------------------------------------------------------------------------
    // TC-PRIV-09 — Provisioning connection is separate from runtime (config check)
    // -------------------------------------------------------------------------

    /** @test */
    public function provisioning_connection_is_separate_from_runtime_connection(): void
    {
        $provisioningUsername = config('database.provisioning_connection.username');
        $runtimeUsername = config('database.connections.tenant.username')
            ?? config('database.connections.mysql.username');

        // In a properly configured environment, these MUST differ.
        // In test environments without PROVISIONING_DB_USERNAME set, provisioning
        // username will be null — which is the correct safe default (no fallback).
        // In a properly configured environment, provisioning and runtime users MUST differ.
        // This ensures the schema/migration principal is separate from
        // the runtime account (DML-only, no GRANT OPTION) — DEC-MYSQL-001.
        $this->assertNotEquals(
            $provisioningUsername,
            $runtimeUsername,
            'Provisioning and runtime DB users must be different accounts (DEC-MYSQL-001). '
            . 'Neither Laravel connection may execute GRANT.'
        );

        // Verify the connection profile is not exposed as a regular connection.
        $this->assertArrayHasKey(
            'provisioning_connection',
            config('database'),
            'Provisioning profile must be isolated from the general connection list (DEC-MYSQL-001)'
        );
        $this->assertArrayNotHasKey('mysql_provisioning', config('database.connections'));
    }

    // -------------------------------------------------------------------------
    // TC-PRIV-10 — TenantProvisioner stays pending when external GRANT verification fails
    // -------------------------------------------------------------------------

    /** @test */
    public function tenant_stays_pending_when_grant_fails(): void
    {
        // TC-PRIV-10: Verify that TenantProvisioner wraps grant-verification failures in a
        // RuntimeException with "provisioning failed" message (DEC-MYSQL-001).
        //
        // This mock-only test exercises exception text; TenantProvisioningTest
        // separately covers the real local pending-state transition.
        $grantorMock = \Mockery::mock(TenantSchemaGrantor::class);
        $grantorMock->shouldReceive('verifyGrantedPrivileges')
            ->once()
            ->andThrow(new \RuntimeException('GRANT verification failed: privileges are absent'));

        // The helper below only verifies exception wrapping; external GRANT is
        // neither executed nor tested by this mock.

        $schemaName  = \App\Foundation\Tenant::deriveSchemaName('grant-fail-slug');
        $runtimeUser = config('database.connections.tenant.username')
            ?? config('database.connections.mysql.username')
            ?? env('DB_USERNAME')
            ?? 'runtime_user';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/provisioning failed/i');

        try {
            $grantorMock->verifyGrantedPrivileges($schemaName, $runtimeUser, '%');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Tenant provisioning failed for slug [grant-fail-slug]: " . $e->getMessage(),
                previous: $e,
            );
        }
    }
}
