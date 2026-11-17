<?php

declare(strict_types=1);

/**
 * Local-only: verify fixture SPA resolve+login with known local password (exit 0/1).
 * Does not print the password.
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$password = 'CoreFix#LocalTest99Aa1';
$cases = [
    ['cr' => 'CORE-OWNER-CR-001', 'email' => 'owner@core-fixture.test'],
    ['cr' => 'CORE-CONSULTANT-CR-001', 'email' => 'consultant@core-fixture.test'],
    ['cr' => 'CORE-CONTRACTOR-CR-001', 'email' => 'contractor@core-fixture.test'],
];

$allOk = true;
foreach ($cases as $c) {
    $resolve = Illuminate\Http\Request::create('/api/auth/tenant/resolve', 'POST', [
        'identifier_type' => 'cr',
        'identifier' => $c['cr'],
    ]);
    $resolve->headers->set('Accept', 'application/json');
    $resolveResp = $kernel->handle($resolve);
    $resolveJson = json_decode($resolveResp->getContent(), true);
    $token = $resolveJson['resolution_token'] ?? null;
    $kernel->terminate($resolve, $resolveResp);

    if (! $token) {
        echo 'RESOLVE_FAIL='.$c['email'].' status='.$resolveResp->getStatusCode().PHP_EOL;
        $allOk = false;
        continue;
    }

    $login = Illuminate\Http\Request::create('/api/auth/tenant/spa/login', 'POST', [
        'email' => $c['email'],
        'password' => $password,
        'resolution_token' => $token,
    ]);
    $login->headers->set('Accept', 'application/json');
    $loginResp = $kernel->handle($login);
    $ok = $loginResp->getStatusCode() === 200;
    $body = json_decode($loginResp->getContent(), true);
    $safe = is_array($body)
        ? array_intersect_key($body, array_flip(['message', 'errors', 'error', 'exception']))
        : ['raw_len' => strlen($loginResp->getContent())];
    echo ($ok ? 'LOGIN_OK=' : 'LOGIN_FAIL=').$c['email'].' status='.$loginResp->getStatusCode().' body='.json_encode($safe, JSON_UNESCAPED_UNICODE).PHP_EOL;
    if (! $ok) {
        $allOk = false;
    }
    $kernel->terminate($login, $loginResp);
}

exit($allOk ? 0 : 1);
