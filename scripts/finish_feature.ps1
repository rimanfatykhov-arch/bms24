$ErrorActionPreference="Stop"
$repo=(Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $repo
& "$PSScriptRoot\preflight.ps1"
if ($LASTEXITCODE -ne 0) { throw "Preflight failed." }
Write-Host "`n=== DIFF SUMMARY ===" -ForegroundColor Cyan
git diff --stat
Write-Host "`n=== CHANGED PATHS ===" -ForegroundColor Cyan
git diff --name-status
Write-Host "`nReview before staging/commit." -ForegroundColor Yellow
