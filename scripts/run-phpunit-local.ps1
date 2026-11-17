# Local PHPUnit alternate gate: isolated MySQL on 3306 / binaa2_phpunit
# Does NOT use Production. Does NOT print secrets.
# Usage: powershell -File scripts/run-phpunit-local.ps1 [-- filter args...]
param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$PhpUnitArgs
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$envFile = Join-Path $root '.env.testing.local'
if (-not (Test-Path $envFile)) {
    Write-Error 'Missing .env.testing.local — create isolated credentials first.'
}

Get-Content $envFile | ForEach-Object {
    $line = $_.Trim()
    if ($line -eq '' -or $line.StartsWith('#')) { return }
    $i = $line.IndexOf('=')
    if ($i -lt 1) { return }
    $k = $line.Substring(0, $i)
    $v = $line.Substring($i + 1)
    Set-Item -Path "Env:$k" -Value $v
}

$php = 'C:\laragon\bin\php\php-8.4.8\php.exe'
if (-not (Test-Path $php)) { $php = 'php' }

Write-Output 'HARNESS=phpunit.local.xml DB=binaa2_phpunit PORT=3306 ISOLATED_MYSQL=true'
& $php vendor/bin/phpunit -c phpunit.local.xml @PhpUnitArgs
exit $LASTEXITCODE
