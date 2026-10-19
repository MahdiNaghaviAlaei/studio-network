param(
    [ValidateRange(1024, 65535)]
    [int]$Port = 8000,
    [switch]$SkipAdmin
)

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
docker info --format '{{.OSType}}' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Start Docker Desktop in Linux containers mode first.' }

if (Test-Path -LiteralPath 'studio-network.tar') {
    docker load --input studio-network.tar
    if ($LASTEXITCODE -ne 0) { throw 'Docker image could not be loaded.' }
}
$taskImage = docker image ls -q studio-network:demo
if (-not $taskImage) { throw 'Load studio-network.tar first, or build studio-network:demo from the source.' }

$taskExisting = docker container ls -a --filter 'name=^/studio-network$' --format '{{.Names}}'
if ($taskExisting -eq 'studio-network') {
    $taskContainer = (docker container inspect studio-network | ConvertFrom-Json)[0]
    if ($taskContainer.Config.Image -ne 'studio-network:demo') { throw 'A different application already uses the container name studio-network.' }
    $Port = [int]$taskContainer.HostConfig.PortBindings.'80/tcp'[0].HostPort
    docker start studio-network | Out-Null
} else {
    docker run -d --pull=never --name studio-network --restart unless-stopped -p "127.0.0.1:${Port}:80" -v studio-network-data:/data studio-network:demo | Out-Null
}
if ($LASTEXITCODE -ne 0) { throw 'Container could not be started. Check that the port is free.' }

Write-Host 'Waiting for the application...'
$taskHealthy = $false
for ($taskAttempt = 0; $taskAttempt -lt 60; $taskAttempt++) {
    $taskStatus = docker inspect --format '{{.State.Health.Status}}' studio-network
    if ($taskStatus -eq 'healthy') { $taskHealthy = $true; break }
    $taskRunning = docker inspect --format '{{.State.Running}}' studio-network
    if ($taskRunning -ne 'true') { break }
    Start-Sleep -Seconds 2
}
if (-not $taskHealthy) {
    docker logs --tail 40 studio-network
    throw 'Application did not become healthy. See container logs above.'
}

$taskUserCount = docker exec studio-network php docker/user-count.php
if ($LASTEXITCODE -ne 0) { throw 'Could not check whether an admin account exists.' }
if (-not $SkipAdmin -and [int]$taskUserCount -eq 0) {
    docker exec -it --user www-data studio-network php artisan make:filament-user
    if ($LASTEXITCODE -ne 0) { throw 'Create an account with: docker exec -it --user www-data studio-network php artisan make:filament-user' }
}
Write-Host "Ready: http://localhost:$Port/admin"
