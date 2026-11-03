<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SharedMigrationLoadOrderTest extends TestCase
{
    public function test_shared_migrations_load_dependencies_in_order(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/database/migrations/shared/*.php') ?: [];
        sort($files, SORT_STRING);

        $names = array_map('basename', $files);

        $tenants = array_search('2026_09_23_000001_create_tenants_table.php', $names, true);
        $settings = array_search('2026_09_23_000002_create_tenant_settings_table.php', $names, true);
        $tracking = array_search('2026_09_23_000003_create_tenant_migrations_table.php', $names, true);

        self::assertNotFalse($tenants);
        self::assertNotFalse($settings);
        self::assertNotFalse($tracking);
        self::assertLessThan($settings, $tenants);
        self::assertLessThan($tracking, $settings);
    }

    public function test_provider_registers_sorted_shared_migration_files(): void
    {
        $provider = file_get_contents(dirname(__DIR__, 2) . '/app/Providers/AppServiceProvider.php');

        self::assertIsString($provider);
        self::assertStringContainsString("glob(database_path('migrations/shared/*.php'))", $provider);
        self::assertStringContainsString('sort($sharedMigrations, SORT_STRING);', $provider);
        self::assertStringContainsString('$this->loadMigrationsFrom($sharedMigrations);', $provider);
    }
}
