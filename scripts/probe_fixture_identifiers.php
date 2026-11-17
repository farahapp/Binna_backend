<?php

declare(strict_types=1);

/**
 * Local-only: list fixture tenant identifiers for SPA login (no passwords).
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Foundation\Tenant;
use App\Foundation\TenantIdentifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$slugs = ['core-owner', 'core-consultant', 'core-contractor'];

foreach ($slugs as $slug) {
    $t = Tenant::on('mysql')->where('slug', $slug)->first();
    if ($t === null) {
        echo "MISSING={$slug}".PHP_EOL;
        continue;
    }
    echo "TENANT={$slug} status={$t->status} activity={$t->activity_type} legal={$t->legal_type} schema={$t->schema_name}".PHP_EOL;

    if (Schema::connection('mysql')->hasTable('tenant_identifiers')) {
        $rows = DB::connection('mysql')->table('tenant_identifiers')->where('tenant_id', $t->id)->get();
        echo '  IDENTIFIERS='.$rows->count().PHP_EOL;
        foreach ($rows as $r) {
            echo '  ID='.json_encode($r, JSON_UNESCAPED_UNICODE).PHP_EOL;
        }
    } else {
        echo '  NO_tenant_identifiers_table'.PHP_EOL;
    }
}

echo 'MODEL='.(class_exists(TenantIdentifier::class) ? 'yes' : 'no').PHP_EOL;
