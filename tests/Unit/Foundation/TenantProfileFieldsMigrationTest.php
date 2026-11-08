<?php

declare(strict_types=1);

namespace Tests\Unit\Foundation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantProfileFieldsMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        Schema::connection('mysql')->create('tenants', function ($table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('schema_name')->nullable();
            $table->string('status');
            $table->timestamps();
        });
        DB::connection('mysql')->table('tenants')->insert([
            'slug' => 'legacy-tenant',
            'name' => 'Legacy Tenant',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        parent::tearDown();
    }

    #[Test]
    public function migration_keeps_existing_and_unspecified_tenant_legal_type_unknown(): void
    {
        $migration = require database_path(
            'migrations/shared/2026_11_05_000001_add_profile_fields_to_tenants_table.php',
        );
        $migration->up();

        $column = collect(Schema::connection('mysql')->getColumns('tenants'))
            ->firstWhere('name', 'legal_type');

        $this->assertNotNull($column);
        $this->assertTrue($column['nullable']);
        $this->assertNull($column['default']);
        $this->assertNull(DB::connection('mysql')->table('tenants')->where('slug', 'legacy-tenant')->value('legal_type'));

        DB::connection('mysql')->table('tenants')->insert([
            'slug' => 'unspecified-tenant',
            'name' => 'Unspecified Tenant',
            'status' => 'active',
        ]);
        $this->assertNull(DB::connection('mysql')->table('tenants')->where('slug', 'unspecified-tenant')->value('legal_type'));
    }

    #[Test]
    public function corrective_migration_removes_legacy_default_without_rewriting_existing_values(): void
    {
        Schema::connection('mysql')->table('tenants', function ($table): void {
            $table->string('legal_type', 32)->default('organization');
        });
        DB::connection('mysql')->table('tenants')->where('slug', 'legacy-tenant')->update([
            'legal_type' => 'organization',
        ]);

        $migration = require database_path(
            'migrations/shared/2026_11_08_000003_make_tenant_legal_type_nullable.php',
        );
        $migration->up();

        $column = collect(Schema::connection('mysql')->getColumns('tenants'))
            ->firstWhere('name', 'legal_type');

        $this->assertNotNull($column);
        $this->assertTrue($column['nullable']);
        $this->assertNull($column['default']);
        $this->assertSame(
            'organization',
            DB::connection('mysql')->table('tenants')->where('slug', 'legacy-tenant')->value('legal_type'),
        );

        DB::connection('mysql')->table('tenants')->insert([
            'slug' => 'unspecified-tenant',
            'name' => 'Unspecified Tenant',
            'status' => 'active',
        ]);
        $this->assertNull(DB::connection('mysql')->table('tenants')->where('slug', 'unspecified-tenant')->value('legal_type'));

        try {
            $migration->down();
            $this->fail('Rollback must not assign an assumed legal type to unclassified tenants.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('remain unclassified', $exception->getMessage());
        }
    }
}
