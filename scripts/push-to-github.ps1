# 一键把本项目推送到你的 GitHub 仓库（Windows / macOS 通用）
#
# 用法：
#   powershell -ExecutionPolicy Bypass -File scripts\push-to-github.ps1 -RepoUrl "https://github.com/你的用户名/couple-app.git"
#
# 可选参数：
#   -Branch main          分支名
#   -Message "提交说明"    提交信息
#   -Name "你的名字"       git 提交者名字（本机未配置过时才需要）
#   -Email "you@mail.com"  git 提交者邮箱
#
# 说明：如果系统 PATH 里没有 git，脚本会自动尝试使用 DeepSeek Harness 内置的 git。

param(
    [Parameter(Mandatory = $true)][string]$RepoUrl,
    [string]$Branch = "main",
    [string]$Message = "情侣空间 App 提交",
    [string]$Name = "",
    [string]$Email = ""
)

$ErrorActionPreference = "Stop"

function Write-Step($text) {
    Write-Host ""
    Write-Host "==> $text" -ForegroundColor Cyan
}

function Invoke-Git {
    param([string[]]$Arguments)
    & $script:GitExe @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "git $($Arguments -join ' ') 执行失败（退出码 $LASTEXITCODE）"
    }
}

# 0) 找到可用的 git
$gitCandidates = @()
$command = Get-Command git -ErrorAction SilentlyContinue
if ($command) { $gitCandidates += $command.Source }
$gitCandidates += (Join-Path $env:APPDATA "dsh-tauri\dependencies\git\cmd\git.exe")
$gitCandidates += "C:\Program Files\Git\cmd\git.exe"
$gitCandidates += "C:\Program Files (x86)\Git\cmd\git.exe"
$gitCandidates += "/usr/bin/git"
$gitCandidates += "/usr/local/bin/git"

$script:GitExe = $null
foreach ($candidate in $gitCandidates) {
    if ($candidate -and (Test-Path $candidate)) {
        $script:GitExe = $candidate
        break
    }
}
if (-not $script:GitExe) {
    throw "没有找到 git。请先安装 Git for Windows：https://git-scm.com/downloads ，装完后重开 PowerShell 再运行本脚本。"
}
Write-Host "使用 git：$script:GitExe"

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root
Write-Host "项目目录：$root"

# 1) 初始化仓库
if (-not (Test-Path (Join-Path $root ".git"))) {
    Write-Step "初始化 git 仓库"
    Invoke-Git @("init")
} else {
    Write-Step "已存在 git 仓库，直接复用"
}

# 中文文件名显示正常 + 不自动改换行符（换行符由 .gitattributes 控制）
Invoke-Git @("config", "core.quotepath", "false")
Invoke-Git @("config", "core.autocrlf", "false")

# 提交者身份（本机没配过才需要）
$currentEmail = & $script:GitExe config --get user.email 2>$null
if ([string]::IsNullOrWhiteSpace($currentEmail)) {
    if ([string]::IsNullOrWhiteSpace($Email)) {
        throw "本机还没有配置 git 提交者。请加上参数：-Name `"你的名字`" -Email `"你的邮箱`""
    }
    Invoke-Git @("config", "user.name", $Name)
    Invoke-Git @("config", "user.email", $Email)
}

# 2) 提交
Write-Step "添加文件"
Invoke-Git @("add", "-A")

$status = & $script:GitExe status --porcelain
if ([string]::IsNullOrWhiteSpace($status)) {
    Write-Host "没有需要提交的改动" -ForegroundColor Yellow
} else {
    Write-Step "提交"
    Invoke-Git @("commit", "-m", $Message)
}

# 3) 分支
Write-Step "切到分支 $Branch"
Invoke-Git @("branch", "-M", $Branch)

# 4) 远程
Write-Step "配置远程仓库"
$existing = & $script:GitExe remote
if ($existing -contains "origin") {
    Invoke-Git @("remote", "set-url", "origin", $RepoUrl)
} else {
    Invoke-Git @("remote", "add", "origin", $RepoUrl)
}
Write-Host "origin -> $RepoUrl"

# 5) 推送
Write-Step "推送到 GitHub（第一次会弹出登录窗口，用浏览器授权即可）"
& $script:GitExe push -u origin $Branch
if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "推送失败。常见原因：" -ForegroundColor Yellow
    Write-Host "  1) 远程仓库已有内容，先执行：git pull --rebase origin $Branch 再重新 push"
    Write-Host "  2) 网络问题：开代理或稍后重试"
    Write-Host "  3) 认证失败：删掉 Windows 凭据管理器里的 git:https://github.com 后重试"
    exit 1
}

Write-Host ""
Write-Host "✅ 推送完成！" -ForegroundColor Green
Write-Host "下一步：打开 https://github.com 你的仓库 → Actions → 构建 iOS IPA → Run workflow"
Write-Host "（构建完成后在页面底部 Artifacts 里下载 IPA 压缩包）"
