$ErrorActionPreference = "Stop"
$repo = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $repo
$failed = $false

function Exists($n) { return $null -ne (Get-Command $n -ErrorAction SilentlyContinue) }
function Pass($m) { Write-Host "[PASS] $m" -ForegroundColor Green }
function Fail($m) { Write-Host "[FAIL] $m" -ForegroundColor Red; $script:failed = $true }
function Section($m) { Write-Host ""; Write-Host "=== $m ===" -ForegroundColor Cyan }

Write-Host "BMS24 PREFLIGHT" -ForegroundColor White

Section "Toolchain"
if (Exists "git") { Pass (git --version) } else { Fail "git missing from PATH" }

if (Exists "php") {
    Pass ("PHP " + (php -r "echo PHP_VERSION;"))
} elseif (Test-Path "C:\php\php.exe") {
    $env:Path += ";C:\php"
    Write-Host "[WARN] Temporary C:\php PATH fallback applied" -ForegroundColor Yellow
    Pass ("PHP " + (php -r "echo PHP_VERSION;"))
} else { Fail "PHP missing" }

if (Exists "python") {
    try { Pass (python --version 2>&1) } catch { Fail "Python failed" }
} else { Fail "Python missing from PATH" }

Section "Project QA"
if (Exists "python") {
    python scripts/qa_local.py
    if ($LASTEXITCODE -ne 0) { Fail "qa_local.py failed" } else { Pass "qa_local.py" }
}

Section "Git diff check"
if (Exists "git") {
    git diff --check
    if ($LASTEXITCODE -ne 0) { Fail "git diff --check" } else { Pass "git diff --check" }
}

Section "Working tree"
if (Exists "git") { git status --short; git diff --stat }

Write-Host ""
if ($failed) {
    Write-Host "BMS24 PREFLIGHT: FAILED / INCOMPLETE" -ForegroundColor Red
    exit 1
}
Write-Host "BMS24 PREFLIGHT: PASS" -ForegroundColor Green
Write-Host "Live Moodle DB/browser/cron/security acceptance is still required."
