param(
    [Parameter(Mandatory=$true)][string]$QaRoot,
    [Parameter(Mandatory=$true)][string]$OutputRoot
)
$ErrorActionPreference = 'Stop'
$qa = (Resolve-Path -LiteralPath $QaRoot).Path
$out = [IO.Path]::GetFullPath($OutputRoot)
if (-not (Test-Path -LiteralPath $out)) { New-Item -ItemType Directory -Path $out | Out-Null }
$run = Join-Path $out ('xmldb-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $run | Out-Null
$data = Join-Path $run 'db'
$php = Join-Path $qa 'php/php.exe'
$bin = Join-Path $qa 'mariadb-10.11.11-winx64/bin'
$probe = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
$probe.Start()
$port = $probe.LocalEndpoint.Port
$probe.Stop()
$secret = [guid]::NewGuid().ToString('N')
$dbName = 'issue44_' + [guid]::NewGuid().ToString('N')
$oldEnvironment = @{}
$ownedProcess = $null
try {
    & (Join-Path $bin 'mariadb-install-db.exe') "--datadir=$data" "--password=$secret" "--port=$port" --silent *> (Join-Path $run 'init.log')
    if ($LASTEXITCODE -ne 0) { throw 'Disposable MariaDB initialization failed; inspect task-local init.log.' }
    $ini = Join-Path $run 'scratch.ini'
    $dataSlash = $data.Replace('\','/')
    $baseSlash = (Split-Path $bin -Parent).Replace('\','/')
    $logSlash = (Join-Path $run 'mariadb.log').Replace('\','/')
    @"
[mysqld]
basedir=$baseSlash
datadir=$dataSlash
bind-address=127.0.0.1
port=$port
skip-name-resolve
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
innodb-buffer-pool-size=64M
log-error=$logSlash
"@ | Set-Content -LiteralPath $ini -Encoding utf8
    $ownedProcess = Start-Process -FilePath (Join-Path $bin 'mariadbd.exe') -ArgumentList "--defaults-file=`"$ini`"" -WindowStyle Hidden -PassThru
    $ownedStart = $ownedProcess.StartTime
    $ready = $false
    for ($attempt=0; $attempt -lt 100; $attempt++) {
        if ($ownedProcess.HasExited) { throw 'Disposable MariaDB exited before readiness.' }
        $client = [Net.Sockets.TcpClient]::new()
        try { $client.Connect('127.0.0.1',$port); $ready=$true } catch {} finally { $client.Dispose() }
        if ($ready) { break }
        Start-Sleep -Milliseconds 200
    }
    if (-not $ready) { throw 'Disposable MariaDB did not become ready.' }
    $values = @{
        BMS44_MOODLE = (Join-Path $qa 'moodle')
        BMS44_PORT = [string]$port
        BMS44_PASSWORD = $secret
        BMS44_DATABASE = $dbName
        BMS44_DATADIR = $data
    }
    foreach ($key in $values.Keys) {
        $oldEnvironment[$key] = [Environment]::GetEnvironmentVariable($key,'Process')
        [Environment]::SetEnvironmentVariable($key,$values[$key],'Process')
    }
    & $php (Join-Path $PSScriptRoot 'schema_parity_real_xmldb.php') --allow-disposable-issue44 2>&1 | Tee-Object -FilePath (Join-Path $run 'result.log')
    $resultCode = $LASTEXITCODE
    if ($resultCode -ne 0) { throw "Real XMLDB harness failed (exit $resultCode). Evidence: $run" }
    Write-Output "Evidence: $run"
} finally {
    foreach ($key in $oldEnvironment.Keys) {
        [Environment]::SetEnvironmentVariable($key,$oldEnvironment[$key],'Process')
    }
    if ($null -ne $ownedProcess) {
        $current = Get-Process -Id $ownedProcess.Id -ErrorAction SilentlyContinue
        if ($null -ne $current -and $current.StartTime -eq $ownedStart) {
            Stop-Process -Id $ownedProcess.Id -Force
            $ownedProcess.WaitForExit(10000) | Out-Null
        }
    }
}
