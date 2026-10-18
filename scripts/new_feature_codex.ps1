param(
    [Parameter(Mandatory=$true)][string]$Name,
    [Parameter(Mandatory=$true)][string]$Description
)

$ErrorActionPreference = "Stop"
$repo = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $repo

$startFeature = Join-Path $PSScriptRoot "start_feature.ps1"
if (-not (Test-Path $startFeature)) {
    throw "start_feature.ps1 not found."
}

& $startFeature -Name $Name

$branch = git branch --show-current
$promptDir = Join-Path $repo ".codex"
New-Item -ItemType Directory -Force -Path $promptDir | Out-Null
$promptFile = Join-Path $promptDir "CURRENT_FEATURE_PROMPT.md"

$prompt = @"
# BMS24 Codex Architect Task

Current branch:
`$branch`

Use `docs/tasks/ARCHITECT_TASK_TEMPLATE.md`.

## Task

$Description

## Mandatory project rules

Read before analysis:
- `AGENTS.md`
- relevant files in `docs/`
- relevant existing code

Important:
- Do not modify any files.
- Preserve Company isolation.
- Preserve RBAC/capabilities.
- Do not create duplicate sources of truth.
- Do not move BMS business logic into `theme/kba`.
- Reuse canonical BMS services/routes/entities.
- Do not introduce DB/schema/ACL/privacy changes unless the task truly requires them.
- Maintain current Moodle/PHP compatibility.

Return:
1. Current flow and root limitation.
2. Canonical source of truth.
3. Exact routes/services/templates involved.
4. Company-isolation impact.
5. RBAC/capability impact.
6. DB/schema/ACL/privacy impact.
7. Exact files expected to change.
8. Smallest coherent implementation plan.
9. Automated tests required.
10. Live acceptance required.

Do not implement yet.
"@

Set-Content -Path $promptFile -Value $prompt -Encoding UTF8

Write-Host ""
Write-Host "Feature branch ready: $branch" -ForegroundColor Green
Write-Host "Codex Architect prompt: $promptFile" -ForegroundColor Green
Write-Host ""

$code = Get-Command code -ErrorAction SilentlyContinue
if ($code) {
    & code --reuse-window $promptFile
} else {
    Write-Host "VS Code CLI 'code' is not in PATH. Open this file manually:" -ForegroundColor Yellow
    Write-Host $promptFile
}
