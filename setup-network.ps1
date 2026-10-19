param(
    [switch]$Demo,
    [switch]$CreateAdmin
)

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
foreach ($tool in @('php', 'composer', 'npm.cmd')) {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) {
        throw "$tool is not in PATH. Install PHP 8.2+, Composer 2 and Node.js 22, then reopen PowerShell."
    }
}
php scripts/prepare-environment.php
if ($LASTEXITCODE -ne 0) { throw 'Environment preparation failed.' }
composer install --prefer-dist --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Composer install failed.' }
npm.cmd ci --no-audit --no-fund
if ($LASTEXITCODE -ne 0) { throw 'npm ci failed.' }
npm.cmd run build:network
if ($LASTEXITCODE -ne 0) { throw 'Network editor build failed.' }
php artisan config:clear
if ($LASTEXITCODE -ne 0) { throw 'Configuration cache could not be cleared.' }
php artisan migrate --force --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Database migration failed.' }
if ($Demo) {
    php artisan db:seed --class=NetworkDemoSeeder --force --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Demo data installation failed.' }
}
if ($CreateAdmin) {
    php artisan make:filament-user
    if ($LASTEXITCODE -ne 0) { throw 'Admin account creation failed.' }
}
Write-Host 'Ready. Run .\start.ps1'
Write-Host 'Login with the account you created. To create one later: php artisan make:filament-user'
