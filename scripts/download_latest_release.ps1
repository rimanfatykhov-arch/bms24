param(
    [string]$Workflow = "BMS24 CI",
    [string]$Branch = "main"
)

$ErrorActionPreference = "Stop"

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $repoRoot

$gh = Get-Command gh -ErrorAction SilentlyContinue
if (-not $gh) {
    throw "GitHub CLI (gh) is not installed or not in PATH. Install GitHub CLI and run 'gh auth login' once."
}

& gh auth status *> $null
if ($LASTEXITCODE -ne 0) {
    throw "GitHub CLI is not authenticated. Run: gh auth login"
}

Write-Host ""
Write-Host "BMS24: locating latest successful release artifact..." -ForegroundColor Cyan

$runJson = & gh run list --workflow $Workflow --branch $Branch --status success --limit 20 --json databaseId,headSha,createdAt,displayTitle
if ($LASTEXITCODE -ne 0) {
    throw "Unable to query GitHub Actions runs."
}

$runs = $runJson | ConvertFrom-Json
if (-not $runs -or $runs.Count -eq 0) {
    throw "No successful '$Workflow' runs found on branch '$Branch'."
}

$repoInfoJson = & gh repo view --json nameWithOwner
if ($LASTEXITCODE -ne 0) {
    throw "Unable to determine current GitHub repository."
}
$repoNameWithOwner = ($repoInfoJson | ConvertFrom-Json).nameWithOwner

$selectedRun = $null
$artifactName = $null

foreach ($run in $runs) {
    $artifactsJson = & gh api "repos/$repoNameWithOwner/actions/runs/$($run.databaseId)/artifacts"
    if ($LASTEXITCODE -ne 0) {
        continue
    }

    $artifactResponse = $artifactsJson | ConvertFrom-Json
    $artifacts = @($artifactResponse.artifacts | Where-Object {
        -not $_.expired -and $_.name -like "bms24-release-*"
    })

    if ($artifacts.Count -gt 0) {
        $selectedRun = $run
        $artifactName = $artifacts[0].name
        break
    }
}

if (-not $selectedRun -or -not $artifactName) {
    throw "Successful BMS24 CI runs were found, but none contains a non-expired bms24-release-* artifact."
}

$releaseRoot = Join-Path $repoRoot "release"
$tempRoot = Join-Path $releaseRoot "_artifact_tmp"

New-Item -ItemType Directory -Force -Path $releaseRoot | Out-Null
if (Test-Path $tempRoot) {
    Remove-Item -Recurse -Force $tempRoot
}
New-Item -ItemType Directory -Force -Path $tempRoot | Out-Null

Write-Host "Run:      $($selectedRun.databaseId)" -ForegroundColor DarkGray
Write-Host "Artifact: $artifactName" -ForegroundColor DarkGray

& gh run download $selectedRun.databaseId --name $artifactName --dir $tempRoot
if ($LASTEXITCODE -ne 0) {
    throw "Artifact download failed."
}

$installFiles = @(Get-ChildItem -Path $tempRoot -Recurse -File -Filter "INSTALL_local_kbacompany_*.zip" |
    Sort-Object LastWriteTime -Descending)

if ($installFiles.Count -eq 0) {
    throw "Artifact downloaded, but INSTALL_local_kbacompany_*.zip was not found."
}

$install = $installFiles[0]

if ($install.Name -notmatch '^INSTALL_local_kbacompany_(.+)_build(\d+)\.zip$') {
    throw "Cannot parse release/build from INSTALL filename: $($install.Name)"
}

$releaseName = $matches[1]
$build = $matches[2]
$releaseFolderName = $releaseName + "_build" + $build
$finalDir = Join-Path $releaseRoot $releaseFolderName

if (Test-Path $finalDir) {
    Remove-Item -Recurse -Force $finalDir
}
New-Item -ItemType Directory -Force -Path $finalDir | Out-Null

Get-ChildItem -Path $tempRoot -Recurse -File | ForEach-Object {
    $target = Join-Path $finalDir $_.Name
    Copy-Item -Force $_.FullName $target
}

Remove-Item -Recurse -Force $tempRoot

$finalInstall = Join-Path $finalDir $install.Name

Write-Host ""
Write-Host "RELEASE SAVED:" -ForegroundColor Green
Write-Host $finalDir -ForegroundColor Green
Write-Host ""
Write-Host "READY TO INSTALL:" -ForegroundColor Green
Write-Host $finalInstall -ForegroundColor Green
Write-Host ""
Write-Host "Downloaded from successful '$Workflow' run $($selectedRun.databaseId)." -ForegroundColor DarkGray
