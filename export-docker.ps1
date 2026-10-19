$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
docker info --format '{{.OSType}}' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Start Docker Desktop in Linux containers mode first.' }
docker build --platform linux/amd64 -t studio-network:demo .
if ($LASTEXITCODE -ne 0) { throw 'Docker build failed.' }
$taskOutput = Join-Path $PSScriptRoot 'artifacts/studio-network-docker'
New-Item -ItemType Directory -Path $taskOutput -Force | Out-Null
docker save --output (Join-Path $taskOutput 'studio-network.tar') studio-network:demo
if ($LASTEXITCODE -ne 0) { throw 'Docker export failed.' }
Copy-Item -LiteralPath docker/run.ps1 -Destination (Join-Path $taskOutput 'run.ps1')
Copy-Item -LiteralPath docker/README.md -Destination (Join-Path $taskOutput 'README.md')
(Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $taskOutput 'studio-network.tar')).Hash | Set-Content -LiteralPath (Join-Path $taskOutput 'studio-network.tar.sha256') -Encoding ASCII
Write-Host "Offline package ready: $taskOutput"
