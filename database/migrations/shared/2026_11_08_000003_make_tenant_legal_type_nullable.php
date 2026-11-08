<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        if (! Schema::connection('mysql')->hasColumn('tenants', 'legal_type')) {
            throw new RuntimeException(
                'The tenants.legal_type column is missing; apply the tenant profile fields migration first.',
            );
        }

        Schema::connection('mysql')->table('tenants', function (Blueprint $table): void {
            $table->string('legal_type', 32)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::connection('mysql')->hasColumn('tenants', 'legal_type')) {
            return;
        }

        if (DB::connection('mysql')->table('tenants')->whereNull('legal_type')->exists()) {
            throw new RuntimeException(
                'Cannot restore a required legal_type default while tenants remain unclassified; review and migrate them explicitly first.',
            );
        }

        Schema::connection('mysql')->table('tenants', function (Blueprint $table): void {
            $table->string('legal_type', 32)->nullable(false)->default('organization')->change();
        });
    }
};
