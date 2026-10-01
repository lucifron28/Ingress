# Ingress — Stop PostgreSQL Database
# Gracefully stops the local PostgreSQL 18 cluster on port 5433.

$ErrorActionPreference = "SilentlyContinue"

Write-Host "=== Ingress Database Shutdown ===" -ForegroundColor DarkRed

# Locate PostgreSQL binaries
$pgBin = $null
if (Get-Command pg_ctl -ErrorAction SilentlyContinue) {
    $pgBin = Split-Path (Get-Command pg_ctl).Source
} elseif (Test-Path "C:\Program Files\PostgreSQL\18\bin\pg_ctl.exe") {
    $pgBin = "C:\Program Files\PostgreSQL\18\bin"
}

if (-not $pgBin) {
    Write-Error "PostgreSQL 18 binaries not found."
    exit 1
}

$homeDir = [System.Environment]::GetFolderPath('UserProfile')
$dataDir = if ($env:INGRESS_DB_DIR) { $env:INGRESS_DB_DIR } else { "$homeDir\ingress_db" }

if (-not (Test-Path "$dataDir")) {
    Write-Host "No database directory found at '$dataDir'." -ForegroundColor Yellow
    exit 0
}

Write-Host "Stopping PostgreSQL database..." -ForegroundColor Cyan
& "$pgBin\pg_ctl.exe" -D "$dataDir" stop -m fast

if ($LASTEXITCODE -eq 0) {
    Write-Host "[OK] PostgreSQL database stopped successfully." -ForegroundColor Green
} else {
    Write-Host "Database was already stopped or could not be halted." -ForegroundColor Yellow
}
