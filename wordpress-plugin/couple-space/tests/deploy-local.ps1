# Deploy the couple-space plugin from this repo into the local WordPress test site,
# then activate it. Used before running tests/ai-e2e.php and tests/e2e.php.
#
#   pwsh -File tests/deploy-local.ps1
#
# ASCII only on purpose: Windows PowerShell 5.1 reads .ps1 as ANSI unless it has a BOM.

param(
    [string]$Repo = (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)),
    [string]$Wp   = 'C:\Users\Tage-HongDou\AppData\Local\wp-test\wordpress',
    [string]$Php  = 'C:\Users\Tage-HongDou\AppData\Local\php83\php.exe'
)

$ErrorActionPreference = 'Stop'

$dest = Join-Path $Wp 'wp-content\plugins\couple-space'
Write-Host "==> copy plugin to $dest"
if (-not (Test-Path $dest)) { New-Item -ItemType Directory -Path $dest -Force | Out-Null }

$exclude = @('\.mock-requests\.log$', '\.admin-call\.json$', '\.wp-eval-')
Get-ChildItem -Recurse -File $Repo | Where-Object {
    $rel = $_.FullName.Substring($Repo.Length + 1)
    $skip = $false
    foreach ($e in $exclude) { if ($rel -match $e) { $skip = $true } }
    -not $skip
} | ForEach-Object {
    $rel  = $_.FullName.Substring($Repo.Length + 1)
    $tout = Join-Path $dest $rel
    $tdir = Split-Path $tout -Parent
    if (-not (Test-Path $tdir)) { New-Item -ItemType Directory -Path $tdir -Force | Out-Null }
    Copy-Item $_.FullName $tout -Force
}

foreach ($f in @('.mock-requests.log', '.admin-call.json')) {
    $p = Join-Path $dest "tests\$f"
    if (Test-Path $p) { Remove-Item $p -Force }
}

Write-Host '==> activate plugin'
& $Php -r "require '$Wp/wp-load.php'; require_once ABSPATH.'wp-admin/includes/plugin.php'; var_export(activate_plugin('couple-space/couple-space.php'));"
Write-Host ''
Write-Host '==> check the new files'
foreach ($f in @('includes\AiUpstream.php', 'includes\AiAdmin.php', 'includes\Routes\AiChatRoutes.php', 'admin\views\ai-providers.php', 'admin\views\ai-chat.php', 'tests\ai-e2e.php', 'tests\mock-provider-router.php')) {
    $p = Join-Path $dest $f
    Write-Host ("{0} -> {1}" -f $f, (Test-Path $p))
}
