# Ingress — Start Laravel Web & REST API Server
# Binds to 0.0.0.0:8000 so both local browser and physical mobile phones on LAN can connect.

$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$rootDir = Split-Path -Parent $scriptDir
$apiDir = Join-Path $rootDir "api"

Write-Host "=== Starting Ingress Event Server ===" -ForegroundColor DarkRed

# 1. Ensure database is running
& "$scriptDir\start-db.ps1"

# 2. Display LAN connectivity
& "$scriptDir\find-ip.ps1"

Write-Host "Organizer Web Portal: " -NoNewline
Write-Host "http://localhost:8000/login" -ForegroundColor Green
Write-Host "REST API Base URL:    " -NoNewline
Write-Host "http://localhost:8000/api/v1`n" -ForegroundColor Green

Write-Host "Starting Laravel server (Press Ctrl+C to stop)..." -ForegroundColor Cyan
Set-Location $apiDir
php artisan serve --host=0.0.0.0 --port=8000
