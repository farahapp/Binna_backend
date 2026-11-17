<?php

/**
 * probe_provisioning_local.php — read-only+reversible local probe.
 *
 * Verifies that the PROVISIONING_DB_* credentials from .env.testing.local can
 * reach the LOCAL MySQL on the configured host/port and hold the schema-creation
 * privilege needed by TenantProvisioner. Creates and immediately drops one
 * disposable probe schema (binaa2_tenant_probe_tmp). Local only — never
 * Production. Prints no secrets.
 *
 * Usage: php scripts/probe_provisioning_local.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env.testing.local';
if (! is_file($envFile)) {
    fwrite(STDERR, "MISSING .env.testing.local\n");
    exit(2);
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    $i = strpos($line, '=');
    if ($i < 1) {
        continue;
    }
    $env[substr($line, 0, $i)] = substr($line, $i + 1);
}

$host = $env['PROVISIONING_DB_HOST'] ?? '127.0.0.1';
$port = $env['PROVISIONING_DB_PORT'] ?? '3306';
$user = $env['PROVISIONING_DB_USERNAME'] ?? '';
$pass = $env['PROVISIONING_DB_PASSWORD'] ?? '';
$db   = $env['PROVISIONING_DB_DATABASE'] ?? '';

echo "PROBE host={$host} port={$port} database={$db} username_set=" . ($user !== '' ? 'yes' : 'no') . "\n";

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'CONNECT-FAIL ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(3);
}

$who = $pdo->query('SELECT CURRENT_USER() AS u, @@hostname AS h, @@port AS p')->fetch(PDO::FETCH_ASSOC);
echo "CURRENT_USER={$who['u']} hostname={$who['h']} server_port={$who['p']}\n";

$grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
echo "GRANT_COUNT=" . count($grants) . "\n";
foreach ($grants as $g) {
    echo "GRANT: {$g}\n";
}

try {
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `binaa2_tenant_probe_tmp`');
    $pdo->exec('DROP DATABASE IF EXISTS `binaa2_tenant_probe_tmp`');
    echo "SCHEMA_CREATE_PRIVILEGE=OK (created+dropped binaa2_tenant_probe_tmp)\n";
} catch (Throwable $e) {
    echo 'SCHEMA_CREATE_PRIVILEGE=FAIL ' . $e->getMessage() . "\n";
}

echo "PROBE=OK\n";

// ---- Inspect the phpunit harness database (phpunit_local credentials) ----
$phpHost = $env['DB_HOST'] ?? '127.0.0.1';
$phpPort = $env['DB_PORT'] ?? '3306';
$phpDb   = $env['DB_DATABASE'] ?? 'binaa2_phpunit';
$phpUser = $env['DB_USERNAME'] ?? '';
$phpPass = $env['DB_PASSWORD'] ?? '';

try {
    $hp = new PDO("mysql:host={$phpHost};port={$phpPort};dbname={$phpDb};charset=utf8mb4", $phpUser, $phpPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    echo "HARNESS_DB={$phpDb} CONNECT=OK\n";
    $idx = $hp->query('SHOW INDEX FROM tenants')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($idx as $row) {
        echo 'TENANTS_INDEX ' . $row['Key_name'] . ' col=' . $row['Column_name'] . ' unique=' . ($row['Non_unique'] == 0 ? 'yes' : 'no') . "\n";
    }
    $tables = $hp->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo 'TABLE_COUNT=' . count($tables) . "\n";
    $interesting = array_values(array_filter($tables, fn ($t) => preg_match('/^(network_|users|roles|permissions|model_has|role_has|personal_access|audit_|notifications|tenant_migrations|tenant_suppliers|tenants$)/', $t)));
    echo 'RELEVANT_TABLES=' . implode(',', $interesting) . "\n";
    $migrations = $hp->query('SELECT COUNT(*) c FROM migrations')->fetch(PDO::FETCH_ASSOC);
    echo 'MIGRATIONS_ROWS=' . $migrations['c'] . "\n";
    $tenantRows = $hp->query('SELECT id, slug, schema_name, status FROM tenants')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tenantRows as $r) {
        echo "TENANT_ROW slug={$r['slug']} schema={$r['schema_name']} status={$r['status']}\n";
    }
} catch (Throwable $e) {
    echo 'HARNESS_DB_FAIL ' . $e->getMessage() . "\n";
}
