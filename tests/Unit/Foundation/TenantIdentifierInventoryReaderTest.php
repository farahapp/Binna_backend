<?php

declare(strict_types=1);

namespace Tests\Unit\Foundation;

use App\Support\TenantIdentifierInventoryReader;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TenantIdentifierInventoryReaderTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function sql_dump_report_counts_types_missing_mismatches_expiry_and_duplicates_without_exposing_numbers(): void
    {
        $path = $this->temporaryFile('.sql');
        file_put_contents($path, <<<'SQL'
CREATE TABLE `tenants` (
  `id` bigint NOT NULL,
  `legal_type` varchar(32) DEFAULT NULL,
  `responsible_qid` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`)
);
CREATE TABLE `tenant_identifiers` (
  `id` bigint NOT NULL,
  `tenant_id` bigint NOT NULL,
  `type` varchar(64) NOT NULL,
  `number` varchar(128) NOT NULL,
  `normalized_number` varchar(128) NOT NULL,
  `validity_status` varchar(32) DEFAULT 'unverified',
  `expires_at` date DEFAULT NULL
);
INSERT INTO `tenants` VALUES
  (1, 'organization', 'QID-RESP-001'),
  (2, 'individual', NULL),
  (3, NULL, NULL),
  (4, 'individual', NULL);
INSERT INTO `tenant_identifiers` VALUES
  (1, 1, 'cr', 'CR-12345', 'CR12345', 'valid', NULL),
  (2, 1, 'qid', 'QID-55588', 'QID55588', 'valid', '2020-01-01'),
  (3, 2, 'qid', 'QID-77', 'QID77', 'unverified', '2020-01-01'),
  (4, 2, 'qid', 'QID77', 'QID77', 'unverified', '2030-01-01');
SQL);

        $report = (new TenantIdentifierInventoryReader())->read(
            $path,
            new DateTimeImmutable('2026-11-08'),
        );

        $this->assertSame(4, $report['tenants']['count']);
        $this->assertSame(1, $report['tenants']['legal_type_counts']['organization']);
        $this->assertSame(2, $report['tenants']['legal_type_counts']['individual']);
        $this->assertSame(1, $report['tenants']['legal_type_counts']['missing']);
        $this->assertSame(1, $report['tenants']['legacy_identifier_candidate_fields_populated_tenant_counts']['responsible_qid']);
        $this->assertSame(4, $report['identifiers']['count']);
        $this->assertSame(1, count($report['identifiers']['type_mismatch_records']));
        $this->assertSame([4], $report['identifiers']['missing_login_identifier_tenant_ids']);
        $this->assertSame(['cr' => 0, 'qid' => 1], $report['identifiers']['missing_login_identifier_counts_by_expected_type']);
        $this->assertSame([2, 4], $report['identifiers']['no_current_valid_login_identifier_tenant_ids']);
        $this->assertSame(2, $report['identifiers']['by_expiry']['expired']);
        $this->assertSame(1, count($report['identifiers']['duplicate_normalized_numbers']));
        $this->assertSame('***77', $report['identifiers']['duplicate_normalized_numbers'][0]['masked_number']);
        $this->assertFalse($report['privacy']['full_identifier_numbers_emitted']);
        $this->assertStringNotContainsString('QID-RESP-001', json_encode($report));
        $this->assertStringNotContainsString('QID-55588', json_encode($report));
        $this->assertStringNotContainsString('QID-77', json_encode($report));
    }

    #[Test]
    public function sqlite_file_is_read_only_and_missing_identifier_table_is_reported(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is not installed.');
        }

        $path = $this->temporaryFile('.sqlite');
        $database = new PDO('sqlite:'.$path);
        $database->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, legal_type TEXT, responsible_qid TEXT)');
        $database->exec("INSERT INTO tenants VALUES (1, 'organization', 'CR-LOCAL-900')");
        $database->exec('PRAGMA user_version = 17');
        $database = null;

        $report = (new TenantIdentifierInventoryReader())->read(
            $path,
            new DateTimeImmutable('2026-11-08'),
        );

        $this->assertFalse($report['source']['identifier_table_present']);
        $this->assertSame([1], $report['identifiers']['missing_login_identifier_tenant_ids']);
        $this->assertSame(17, (new PDO('sqlite:'.$path))->query('PRAGMA user_version')->fetchColumn());
        $this->assertStringNotContainsString('CR-LOCAL-900', json_encode($report));
    }

    #[Test]
    public function standalone_cli_emits_json_without_booting_laravel(): void
    {
        $path = $this->temporaryFile('.sql');
        file_put_contents($path, <<<'SQL'
CREATE TABLE tenants (id bigint, legal_type varchar(32));
INSERT INTO tenants VALUES (9, 'organization');
SQL);

        $process = new Process([
            PHP_BINARY,
            base_path('bin/tenant-identifier-inventory.php'),
            $path,
            '--as-of=2026-11-08',
        ], base_path());
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $stdout = trim($process->getOutput());
        $jsonStart = strpos($stdout, '{');
        $this->assertNotFalse($jsonStart, $process->getOutput().$process->getErrorOutput());
        $report = json_decode(substr($stdout, $jsonStart), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['tenants']['count']);
        $this->assertSame('sql_dump_parsed_without_execution', $report['source']['kind']);
    }

    private function temporaryFile(string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tenant-inventory-');
        if ($path === false) {
            $this->fail('Unable to allocate a temporary test file.');
        }
        $target = $path.$suffix;
        if (! rename($path, $target)) {
            $this->fail('Unable to prepare a temporary test file.');
        }
        $this->temporaryFiles[] = $target;

        return $target;
    }
}
