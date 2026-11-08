<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$arguments = array_slice($argv, 1);
$source = null;
$asOfValue = (new \DateTimeImmutable('today'))->format('Y-m-d');

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--as-of=')) {
        $asOfValue = substr($argument, strlen('--as-of='));
    } elseif ($source === null && ! str_starts_with($argument, '--')) {
        $source = $argument;
    } else {
        fwrite(STDERR, "Usage: php bin/tenant-identifier-inventory.php <local-source.sql|sqlite|db> [--as-of=YYYY-MM-DD]\n");
        exit(2);
    }
}

$asOf = \DateTimeImmutable::createFromFormat('!Y-m-d', $asOfValue);
if ($source === null || $asOf === false || $asOf->format('Y-m-d') !== $asOfValue) {
    fwrite(STDERR, "A local source file and valid --as-of=YYYY-MM-DD date are required.\n");
    exit(2);
}

try {
    $report = (new \App\Support\TenantIdentifierInventoryReader())->read($source, $asOf);
    fwrite(STDOUT, json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL);
} catch (\RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
