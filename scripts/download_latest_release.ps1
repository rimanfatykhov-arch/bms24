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

$auth = & gh auth status 2>&1
if ($LASTEXITCODE -ne 0) {
    throw "GitHub CLI is not authenticated. Run: gh auth login"
}

$downloadRoot = Join-Path $repoRoot "downloads"
$tempRoot = Join-Path $downloadRoot "_artifact_tmp"

New-Item -ItemType Directory -Force -Path $downloadRoot | Out-Null
if (Test-Path $tempRoot) {
    Remove-Item -Recurse -Force $tempRoot
}
New-Item -ItemType Directory -Force -Path $tempRoot | Out-Null

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

$selectedRun = $null
$artifactName = $null

$repoInfoJson = & gh repo view --json nameWithOwner
if ($LASTEXITCODE -ne 0) {
    throw "Unable to determine current GitHub repository."
}
$repoNameWithOwner = ($repoInfoJson | ConvertFrom-Json).nameWithOwner

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
$finalInstall = Join-Path $downloadRoot $install.Name
Copy-Item -Force $install.FullName $finalInstall

$sourceFiles = @(Get-ChildItem -Path $tempRoot -Recurse -File -Filter "SOURCE_local_kbacompany_*.zip" |
    Sort-Object LastWriteTime -Descending)
if ($sourceFiles.Count -gt 0) {
    Copy-Item -Force $sourceFiles[0].FullName (Join-Path $downloadRoot $sourceFiles[0].Name)
}

foreach ($name in @("RELEASE_NOTES.md", "CHANGED_FILES.md", "SHA256SUMS.txt")) {
    $file = Get-ChildItem -Path $tempRoot -Recurse -File -Filter $name | Select-Object -First 1
    if ($file) {
        Copy-Item -Force $file.FullName (Join-Path $downloadRoot $name)
    }
}

Remove-Item -Recurse -Force $tempRoot

Write-Host ""
Write-Host "READY TO INSTALL:" -ForegroundColor Green
Write-Host $finalInstall -ForegroundColor Green
Write-Host ""
Write-Host "Downloaded from successful '$Workflow' run $($selectedRun.databaseId)." -ForegroundColor DarkGray
