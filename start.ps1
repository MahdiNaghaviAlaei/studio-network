param(
    [ValidateRange(1024, 65535)]
    [int]$Port = 8000
)

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
if (-not (Test-Path -LiteralPath 'vendor/autoload.php') -or -not (Test-Path -LiteralPath '.env')) {
    throw 'Run .\setup-network.ps1 -Demo -CreateAdmin first.'
}
if (-not (Test-Path -LiteralPath 'public\network-editor\network-editor.js')) {
    throw 'Run npm.cmd ci and npm.cmd run build:network first.'
}
if (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue) {
    Write-Host "Port $Port already has a listening process. Open it only if it is this project, or run .\start.ps1 -Port 8001"
    exit 0
}
Write-Host "Native Filament network editor: http://127.0.0.1:$Port/admin/networks"
php artisan serve --host=127.0.0.1 --port=$Port
if ($LASTEXITCODE -ne 0) { throw 'The PHP development server stopped with an error.' }
