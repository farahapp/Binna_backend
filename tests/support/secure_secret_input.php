<?php

declare(strict_types=1);

/**
 * Read a secret from an interactive terminal without echoing characters.
 * The value is returned in memory only; callers must clear it promptly.
 */
function readSecret(string $prompt): string
{
    if (getenv('BINAA2_SECRET_INPUT_TEST') === '1') {
        $value = fgets(STDIN);
        if ($value === false) {
            throw new RuntimeException('Secret input was not available.');
        }
        return rtrim($value, "\r\n");
    }

    if (PHP_OS_FAMILY === 'Windows') {
        fwrite(STDOUT, $prompt);
        $command = 'powershell.exe -NoProfile -NonInteractive -Command '
            . '"$s=Read-Host -AsSecureString; '
            . '$b=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($s); '
            . 'try {[Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($b))} '
            . 'finally {[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b)}"';
        $handle = popen($command, 'r');
        if ($handle === false) {
            fwrite(STDOUT, PHP_EOL);
            throw new RuntimeException('Secure secret input could not be started.');
        }
        $value = stream_get_contents($handle);
        $status = pclose($handle);
        fwrite(STDOUT, PHP_EOL);
        if ($status !== 0 || $value === false) {
            throw new RuntimeException('Secure secret input failed.');
        }
        return rtrim($value, "\r\n");
    }

    if (! function_exists('shell_exec')) {
        throw new RuntimeException('Secure secret input is unavailable on this terminal.');
    }

    fwrite(STDOUT, $prompt);
    $state = shell_exec('stty -g 2>/dev/null');
    if (! is_string($state) || trim($state) === '') {
        fwrite(STDOUT, PHP_EOL);
        throw new RuntimeException('Secure secret input requires an interactive terminal.');
    }

    try {
        shell_exec('stty -echo 2>/dev/null');
        $value = fgets(STDIN);
    } finally {
        shell_exec('stty ' . escapeshellarg(trim($state)) . ' 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }

    if ($value === false) {
        throw new RuntimeException('Secret input was not available.');
    }

    return rtrim($value, "\r\n");
}

function clearSecret(?string &$secret): void
{
    if (is_string($secret)) {
        $secret = str_repeat("\0", strlen($secret));
    }
    $secret = null;
}
