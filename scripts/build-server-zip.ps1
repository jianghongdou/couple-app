# 打包服务端部署包（Node 版 / PHP 版），文件名自动带时间戳，并做「文件清单」完整性校验。
#
# 用法：
#   powershell -ExecutionPolicy Bypass -File scripts\build-server-zip.ps1                  # 两个包都打（取工作区当前代码）
#   powershell -ExecutionPolicy Bypass -File scripts\build-server-zip.ps1 -Which php       # 只打 PHP 版
#   powershell -ExecutionPolicy Bypass -File scripts\build-server-zip.ps1 -FromGit        # 从 git 已提交版本导出（可复现）
#   powershell -ExecutionPolicy Bypass -File scripts\build-server-zip.ps1 -OutDir D:\      # 指定输出目录
#
# 命名规则：<包名>-<年月日>-<时分>.zip   例如 couple-php-20261013-2235.zip
#
# 为什么要有这个脚本：
#   1) 文件名带时间戳，方便区分每次交付的版本，也便于回滚。
#   2) PowerShell 5.1 的 Compress-Archive 写出的 zip 条目用反斜杠，Linux 解压会得到怪文件名
#      —— 这里用 bsdtar（tar.exe -a）或 git archive，条目名都是正斜杠。
#   3) PowerShell 的 -Exclude / -ne 等比较在 Windows 上**不区分大小写**：
#      曾经 `-Exclude 'config.php'` 把 `src/Config.php` 一起排掉，打出来的包缺文件。
#      —— 这里改为「先全量复制，再按大小写敏感的精确路径删除」，并在最后逐项比对清单。
[CmdletBinding()]
param(
    [ValidateSet('all', 'php', 'node')][string]$Which = 'all',
    [string]$OutDir = [Environment]::GetFolderPath('Desktop'),
    [switch]$FromGit
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$tar = Join-Path $env:WINDIR 'system32\tar.exe'
if (-not $FromGit -and -not (Test-Path $tar)) { throw "找不到 bsdtar：$tar" }

# 时间戳：年月日-时分
$stamp = Get-Date -Format 'yyyyMMdd-HHmm'

$gitCandidates = @((Get-Command git -ErrorAction SilentlyContinue).Source,
    (Join-Path $env:APPDATA 'dsh-tauri\dependencies\git\cmd\git.exe'),
    'C:\Program Files\Git\cmd\git.exe')
$git = $gitCandidates | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1
if ($FromGit -and -not $git) { throw '使用 -FromGit 时需要 git' }

$packages = @{
    php  = @{
        Source           = Join-Path $root 'server-php'
        GitPath          = 'server-php'
        Inner            = 'couple-php'
        BaseName         = 'couple-php'
        ExcludeRootFiles = @('config.php', 'test-report.txt')
        ExcludeRootDirs  = @('data', 'data-test', 'data-test-install', 'data-test-probe')
        KeepDirs         = @('data')
        Label            = 'PHP 版服务端'
    }
    node = @{
        Source           = Join-Path $root 'server'
        GitPath          = 'server'
        Inner            = 'server'
        BaseName         = 'couple-server-update'
        ExcludeRootFiles = @('.env', 'test-report.txt', 'test-run-output.txt', 'test-server.log')
        ExcludeRootDirs  = @('node_modules', 'data', 'data-test', '.npm-cache', 'logs')
        KeepDirs         = @()
        Label            = 'Node 版服务端更新包'
    }
}

function Build-One {
    param([hashtable]$Spec)

    $outFile = Join-Path $OutDir ("{0}-{1}.zip" -f $Spec.BaseName, $stamp)
    Write-Host ''
    Write-Host "=== 打包 $($Spec.Label)（$(if ($FromGit) { 'git 已提交版本' } else { '工作区当前代码' })）===" -ForegroundColor Cyan

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zipFiles = @()
    $srcFiles = @()

    if ($FromGit) {
        if (Test-Path $outFile) { Remove-Item -LiteralPath $outFile -Force }
        & $git archive --format=zip -o $outFile --prefix="$($Spec.Inner)/" "HEAD:$($Spec.GitPath)" 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path $outFile)) { Write-Host '❌ git archive 失败' -ForegroundColor Red; return $false }

        $zip = [System.IO.Compression.ZipFile]::OpenRead($outFile)
        $zipFiles = @($zip.Entries | Where-Object { $_.FullName -notmatch '/$' } | ForEach-Object { $_.FullName -replace "^$([regex]::Escape($Spec.Inner))/", '' })
        $zip.Dispose()

        $tracked = @(& $git -C $root ls-files $Spec.GitPath)
        $srcFiles = @($tracked | ForEach-Object {
                $rel = $_ -replace "^$([regex]::Escape($Spec.GitPath))/", ''
                $top = ($rel -split '/')[0]
                if ($Spec.ExcludeRootDirs -ccontains $top) { return }
                if ($rel -notmatch '/' -and ($Spec.ExcludeRootFiles -ccontains $rel)) { return }
                $rel
            })
    }
    else {
        if (-not (Test-Path $Spec.Source)) { Write-Host "❌ 源目录不存在：$($Spec.Source)" -ForegroundColor Red; return $false }
        $stage = Join-Path $env:TEMP ("pkgstage-" + [guid]::NewGuid().ToString('N').Substring(0, 8))
        $target = Join-Path $stage $Spec.Inner
        New-Item -ItemType Directory -Path $target -Force | Out-Null
        Copy-Item (Join-Path $Spec.Source '*') -Destination $target -Recurse -Force
        foreach ($name in $Spec.ExcludeRootFiles) {
            $p = Join-Path $target $name
            if ((Test-Path $p) -and ((Split-Path $p -Leaf) -ceq $name)) { Remove-Item -LiteralPath $p -Recurse -Force }
        }
        foreach ($name in $Spec.ExcludeRootDirs) {
            $p = Join-Path $target $name
            if (Test-Path $p) { Remove-Item -LiteralPath $p -Recurse -Force }
        }
        foreach ($keep in $Spec.KeepDirs) {
            $p = Join-Path $target $keep
            New-Item -ItemType Directory -Path $p -Force | Out-Null
            Set-Content (Join-Path $p '.gitkeep') '' -NoNewline
        }
        if (Test-Path $outFile) { Remove-Item -LiteralPath $outFile -Force }
        & $tar -a -c -f $outFile -C $stage $Spec.Inner
        if ($LASTEXITCODE -ne 0) { Write-Host '❌ tar 打包失败' -ForegroundColor Red; Remove-Item -Recurse -Force $stage; return $false }

        $zip = [System.IO.Compression.ZipFile]::OpenRead($outFile)
        $zipFiles = @($zip.Entries | Where-Object { $_.FullName -notmatch '/$' } | ForEach-Object { $_.FullName -replace "^$([regex]::Escape($Spec.Inner))/", '' })
        $zip.Dispose()

        $srcFiles = @(Get-ChildItem -Recurse -File $Spec.Source | ForEach-Object {
                $rel = $_.FullName.Substring($Spec.Source.Length + 1).Replace('\', '/')
                $top = ($rel -split '/')[0]
                if ($Spec.ExcludeRootDirs -ccontains $top) { return }
                if ($rel -notmatch '/' -and ($Spec.ExcludeRootFiles -ccontains $rel)) { return }
                $rel
            })
        Remove-Item -Recurse -Force $stage
    }

    $zip2 = [System.IO.Compression.ZipFile]::OpenRead($outFile)
    $backslash = @($zip2.Entries | Where-Object { $_.FullName -match '\\' }).Count
    $zip2.Dispose()

    $missing = @($srcFiles | Where-Object { $t = $_; -not ($zipFiles | Where-Object { $_.Equals($t, [StringComparison]::Ordinal) }) })
    $sizeKB = [math]::Round((Get-Item -LiteralPath $outFile).Length / 1KB, 1)

    Write-Host ("   输出      : {0}" -f (Split-Path $outFile -Leaf))
    Write-Host ("   大小      : {0} KB，{1} 个文件" -f $sizeKB, $zipFiles.Count)
    Write-Host ("   源文件    : {0} 个" -f $srcFiles.Count)
    Write-Host ("   反斜杠条目: {0} {1}" -f $backslash, $(if ($backslash -eq 0) { '✅' } else { '❌（Linux 解压会出错）' }))

    $ok = $true
    if ($missing.Count -gt 0) {
        Write-Host '   ❌ 包内缺失以下文件：' -ForegroundColor Red
        $missing | ForEach-Object { Write-Host "        $_" -ForegroundColor Red }
        $ok = $false
    }
    else {
        Write-Host '   ✅ 文件清单完整（逐项比对通过）' -ForegroundColor Green
    }
    return $ok
}

$allOk = $true
if ($Which -eq 'all' -or $Which -eq 'php') { $allOk = (Build-One $packages.php) -and $allOk }
if ($Which -eq 'all' -or $Which -eq 'node') { $allOk = (Build-One $packages.node) -and $allOk }

Write-Host ''
if ($allOk) { Write-Host ("✅ 打包完成（时间戳 {0}）" -f $stamp) -ForegroundColor Green }
else { Write-Host '❌ 有包未通过校验，请勿上传' -ForegroundColor Red; exit 1 }
