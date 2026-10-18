param([Parameter(Mandatory=$true)][string]$Name)
$ErrorActionPreference="Stop"
$repo=(Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $repo
if (git status --porcelain) { git status --short; throw "Working tree is not clean." }
$slug=($Name.ToLower() -replace '[^a-z0-9\-]+','-' -replace '\-+','-').Trim('-')
git checkout main
git checkout -b "feature/$slug"
Write-Host "Created feature/$slug" -ForegroundColor Green
