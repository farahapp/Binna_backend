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
        Schema::connection('mysql')->table('tenants', function (Blueprint $table) {
            $table->string('legal_type', 32)->nullable()->after('name');
            $table->string('activity_type', 32)->nullable()->after('legal_type');
            $table->string('phone', 32)->nullable()->after('activity_type');
            $table->string('email')->nullable()->after('phone');
            $table->string('responsible_name')->nullable()->after('email');
            $table->string('responsible_qid', 32)->nullable()->after('responsible_name');
            $table->date('qid_expires_at')->nullable()->after('responsible_qid');

            $table->index(['legal_type', 'activity_type']);
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('tenants', function (Blueprint $table) {
            $table->dropIndex(['legal_type', 'activity_type']);
            $table->dropColumn([
                'legal_type',
                'activity_type',
                'phone',
                'email',
                'responsible_name',
                'responsible_qid',
                'qid_expires_at',
            ]);
        });
    }
};
