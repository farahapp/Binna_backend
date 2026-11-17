<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->table('tenant_identifiers', function (Blueprint $table): void {
            $table->foreignId('registration_request_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('tenant_registration_requests')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable()->change();
            $table->index(['registration_request_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('tenant_identifiers', function (Blueprint $table): void {
            $table->dropIndex(['registration_request_id', 'type']);
            $table->dropConstrainedForeignId('registration_request_id');
            $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
        });
    }
};
