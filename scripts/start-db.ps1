# Ingress - Start PostgreSQL Database
# Starts the local PostgreSQL 18 cluster on port 5433.

$ErrorActionPreference = "Stop"

Write-Host "=== Ingress Database Launcher ===" -ForegroundColor DarkRed

$port = 5433
$dbName = "ingress"
$user = "postgres"

# Locate PostgreSQL binaries
$pgBin = $null
if (Get-Command pg_ctl -ErrorAction SilentlyContinue) {
    $pgBin = Split-Path (Get-Command pg_ctl).Source
} elseif (Test-Path "C:\Program Files\PostgreSQL\18\bin\pg_ctl.exe") {
    $pgBin = "C:\Program Files\PostgreSQL\18\bin"
}

if (-not $pgBin) {
    Write-Error "PostgreSQL 18 binaries not found in PATH or C:\Program Files\PostgreSQL\18\bin."
    exit 1
}

# Locate ingress database data directory
$homeDir = [System.Environment]::GetFolderPath('UserProfile')
$dataDir = if ($env:INGRESS_DB_DIR) { $env:INGRESS_DB_DIR } else { "$homeDir\ingress_db" }

if (-not (Test-Path "$dataDir\PG_VERSION")) {
    Write-Host "Database directory not found at '$dataDir'." -ForegroundColor Yellow
    Write-Host "Initializing new PostgreSQL cluster..." -ForegroundColor Cyan
    & "$pgBin\initdb.exe" -D "$dataDir" -U $user -A trust
    Add-Content -Path "$dataDir\postgresql.conf" -Value "`nport = $port`n"
}

$pgReady = "$pgBin\pg_isready.exe"
$pgCtl = "$pgBin\pg_ctl.exe"
$psql = "$pgBin\psql.exe"

# Check if port 5433 is already active
$isReady = $false
try {
    $check = & $pgReady -h 127.0.0.1 -p $port 2>&1
    if ($LASTEXITCODE -eq 0) {
        $isReady = $true
    }
} catch {}

if ($isReady) {
    Write-Host "[OK] PostgreSQL 18 is already running and accepting connections on port $port." -ForegroundColor Green
} else {
    Write-Host "Starting PostgreSQL server on port $port..." -ForegroundColor Cyan
    $logFile = "$dataDir\server.log"
    & $pgCtl -D "$dataDir" -l "$logFile" start
    
    # Wait up to 10 seconds for server to accept connections
    $connected = $false
    for ($i = 0; $i -lt 10; $i++) {
        Start-Sleep -Seconds 1
        & $pgReady -h 127.0.0.1 -p $port 2>$null
        if ($LASTEXITCODE -eq 0) {
            $connected = $true
            break
        }
    }
    
    if ($connected) {
        Write-Host "[OK] PostgreSQL 18 started successfully on port $port." -ForegroundColor Green
    } else {
        Write-Error "PostgreSQL server failed to start. Check log file at: $logFile"
        exit 1
    }
}
try {
    & $psql -h 127.0.0.1 -p $port -U $user -d $dbName -c "SELECT 1;" >$null 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Host "Creating '$dbName' database..." -ForegroundColor Cyan
        & $psql -h 127.0.0.1 -p $port -U $user -d postgres -c "CREATE DATABASE $dbName;" >$null 2>&1
    }
    Write-Host "[OK] Database '$dbName' is ready for connections.`n" -ForegroundColor Green
} catch {
    Write-Warning "Could not verify database: $_"
}
