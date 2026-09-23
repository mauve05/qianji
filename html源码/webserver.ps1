# ============================================================
#  潜记单机版 - 本地 Web 服务（双击「启动网站.bat」运行本脚本）
#  零依赖：使用 Windows 自带 PowerShell + HttpListener，无需安装 PHP/Python/Node
#  仅监听 localhost（不对外网开放，无防火墙弹窗）；关闭本窗口即停止服务
# ============================================================
param([switch]$NoOpen)
$ErrorActionPreference = 'Continue'

# 网站根目录 = 本脚本所在目录
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = [System.IO.Path]::GetFullPath($root)
if (-not $root.EndsWith('\')) { $root += '\' }

# MIME 类型表（覆盖本系统用到的全部静态资源类型）
$mime = @{
    '.html' = 'text/html; charset=utf-8';  '.htm'  = 'text/html; charset=utf-8'
    '.css'  = 'text/css; charset=utf-8';   '.js'   = 'application/javascript; charset=utf-8'
    '.mjs'  = 'application/javascript; charset=utf-8'
    '.json' = 'application/json; charset=utf-8'
    '.map'  = 'application/json'
    '.png'  = 'image/png';   '.jpg' = 'image/jpeg';  '.jpeg' = 'image/jpeg'
    '.gif'  = 'image/gif';   '.svg' = 'image/svg+xml'
    '.ico'  = 'image/x-icon'; '.webp' = 'image/webp'
    '.woff' = 'font/woff';   '.woff2' = 'font/woff2'
    '.ttf'  = 'font/ttf';    '.eot' = 'application/vnd.ms-fontobject'
    '.mp3'  = 'audio/mpeg';  '.wav' = 'audio/wav';   '.ogg' = 'audio/ogg'
    '.m4a'  = 'audio/mp4';   '.mp4' = 'video/mp4';   '.webm' = 'video/webm'
    '.pdf'  = 'application/pdf'
    '.txt'  = 'text/plain; charset=utf-8'
    '.csv'  = 'text/csv; charset=utf-8'
    '.wasm' = 'application/wasm'
    '.xml'  = 'application/xml'
}

# 端口：8765 起依次尝试 10 个
$listener = $null; $port = $null
foreach ($p in 8765..8774) {
    try {
        $l = New-Object System.Net.HttpListener
        $l.Prefixes.Add("http://localhost:$p/")
        $l.Start()
        $listener = $l; $port = $p
        break
    } catch {
        try { if ($l) { $l.Close() } } catch {}
    }
}
if (-not $listener) {
    Write-Host ''
    Write-Host '[错误] 无法启动本地服务（8765-8774 端口均被占用，或系统拒绝本机监听）。' -ForegroundColor Red
    Write-Host '解决：关闭占用端口的程序后重试，或右键本程序「以管理员身份运行」。' -ForegroundColor Red
    Read-Host '按回车键退出'
    exit 1
}

Write-Host ''
Write-Host '==============================================' -ForegroundColor Green
Write-Host '   潜记 · 本地网站服务已启动' -ForegroundColor Green
Write-Host ("   网址：http://localhost:$port/index.html") -ForegroundColor Yellow
Write-Host ("   目录：" + $root)
Write-Host '   使用中请勿关闭本窗口（关闭即停止网站）' -ForegroundColor Gray
Write-Host '==============================================' -ForegroundColor Green
Write-Host ''

if (-not $NoOpen) {
    try { Start-Process ("http://localhost:$port/index.html") } catch {}
}

function Send-Bytes($ctx, [int]$code, [byte[]]$bytes, $ctype) {
    $ctx.Response.StatusCode = $code
    if ($ctype) { $ctx.Response.ContentType = $ctype }
    try { $ctx.Response.AddHeader('Cache-Control', 'no-cache') } catch {}
    $ctx.Response.ContentLength64 = $bytes.Length
    $ctx.Response.OutputStream.Write($bytes, 0, $bytes.Length)
    $ctx.Response.OutputStream.Close()
}

while ($listener.IsListening) {
    $ctx = $null
    try { $ctx = $listener.GetContext() } catch { break }
    try {
        $path = [Uri]::UnescapeDataString($ctx.Request.Url.AbsolutePath)
        if ($path -eq '/') { $path = '/index.html' }
        $rel = $path.TrimStart('/') -replace '/', '\'
        $full = [System.IO.Path]::GetFullPath((Join-Path $root $rel))

        # 安全：仅允许访问网站目录内的文件（防 ../ 穿越和盘符绝对路径）
        if (-not $full.StartsWith($root, [System.StringComparison]::OrdinalIgnoreCase)) {
            Send-Bytes $ctx 403 ([Text.Encoding]::UTF8.GetBytes('403 Forbidden')) 'text/plain; charset=utf-8'
            Write-Host ("  403 " + $path) -ForegroundColor Red
            continue
        }
        if (Test-Path $full -PathType Container) { $full = Join-Path $full 'index.html' }

        if (Test-Path $full -PathType Leaf) {
            $ext = [System.IO.Path]::GetExtension($full).ToLower()
            $ctype = $null
            if ($mime.ContainsKey($ext)) { $ctype = $mime[$ext] }
            Send-Bytes $ctx 200 ([System.IO.File]::ReadAllBytes($full)) $ctype
            Write-Host ("  200 " + $path)
        } else {
            Send-Bytes $ctx 404 ([Text.Encoding]::UTF8.GetBytes('404 Not Found: ' + $path)) 'text/plain; charset=utf-8'
            Write-Host ("  404 " + $path) -ForegroundColor Red
        }
    } catch {
        try { $ctx.Response.StatusCode = 500; $ctx.Response.Close() } catch {}
        Write-Host ("  500 " + $_.Exception.Message) -ForegroundColor Red
    }
}
