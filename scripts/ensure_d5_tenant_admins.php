<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Foundation\Tenant;
use App\IAM\Role;
use App\IAM\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (($app['config']->get('app.env')) === 'production') {
    fwrite(STDERR, "REFUSED: production\n");
    exit(1);
}

$map = [
    'core-contractor' => 'contractor@core-fixture.test',
    'core-owner' => 'owner@core-fixture.test',
    'core-consultant' => 'consultant@core-fixture.test',
];

foreach ($map as $slug => $email) {
    $tenant = Tenant::on('mysql')->where('slug', $slug)->first();
    if ($tenant === null || empty($tenant->schema_name)) {
        echo "MISS={$slug}".PHP_EOL;
        continue;
    }
    config(['database.connections.tenant' => array_merge(
        config('database.connections.mysql'),
        ['database' => $tenant->schema_name]
    )]);
    DB::purge('tenant');
    DB::reconnect('tenant');

    if (! Schema::connection('tenant')->hasTable('roles') || ! Schema::connection('tenant')->hasTable('users')) {
        echo "IAM_MISSING={$slug}".PHP_EOL;
        continue;
    }

    $role = Role::on('tenant')->firstOrCreate(
        ['name' => 'tenant_admin'],
        ['guard_name' => 'api']
    );
    $user = User::on('tenant')->where('email', $email)->first();
    if ($user === null) {
        echo "NO_USER={$email}".PHP_EOL;
        continue;
    }
    if (! $user->hasRole('tenant_admin')) {
        $user->assignRole($role);
        echo "ADMIN_ASSIGNED={$email}".PHP_EOL;
    } else {
        echo "ADMIN_OK={$email}".PHP_EOL;
    }
}
