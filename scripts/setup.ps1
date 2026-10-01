# Ingress — One-Time Project Setup
# Prepares PostgreSQL, Laravel API dependencies, database migrations/seeders, and Expo mobile dependencies.

$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$rootDir = Split-Path -Parent $scriptDir
$apiDir = Join-Path $rootDir "api"
$mobileDir = Join-Path $rootDir "mobile"

Write-Host "===============================================" -ForegroundColor DarkRed
Write-Host "       INGRESS PROJECT SETUP WIZARD            " -ForegroundColor White
Write-Host "===============================================" -ForegroundColor DarkRed

# 1. Start Database
Write-Host "`n[1/5] Ensuring PostgreSQL database is running..." -ForegroundColor Cyan
& "$scriptDir\start-db.ps1"

# 2. Setup Laravel API
Write-Host "`n[2/5] Configuring Laravel backend..." -ForegroundColor Cyan
Set-Location $apiDir

if (-not (Test-Path ".env")) {
    Write-Host "Creating api/.env from .env.example..." -ForegroundColor Gray
    Copy-Item ".env.example" ".env"
}

# Ensure demo mode is enabled for development
$envContent = Get-Content ".env" -Raw
if ($envContent -notmatch "INGRESS_DEMO_MODE") {
    Add-Content -Path ".env" -Value "`nINGRESS_DEMO_MODE=true`n"
}

Write-Host "Installing Composer dependencies..." -ForegroundColor Gray
composer install --prefer-dist --no-interaction

Write-Host "Generating application key..." -ForegroundColor Gray
php artisan key:generate --force

# 3. Database Migration & Seeders
Write-Host "`n[3/5] Migrating database and seeding demonstration data..." -ForegroundColor Cyan
php artisan migrate:fresh --seed --force

# 4. Setup Expo Mobile Application
Write-Host "`n[4/5] Configuring Expo mobile application..." -ForegroundColor Cyan
Set-Location $mobileDir

if (-not (Test-Path ".env")) {
    if (Test-Path ".env.example") {
        Write-Host "Creating mobile/.env from .env.example..." -ForegroundColor Gray
        Copy-Item ".env.example" ".env"
    }
}

Write-Host "Installing npm dependencies..." -ForegroundColor Gray
npm install

Write-Host "Verifying TypeScript compilation..." -ForegroundColor Gray
npx tsc --noEmit
if ($LASTEXITCODE -eq 0) {
    Write-Host "[OK] Mobile TypeScript compilation passed cleanly." -ForegroundColor Green
}

# 5. Network Discovery
Set-Location $rootDir
Write-Host "`n[5/5] Checking local network configuration..." -ForegroundColor Cyan
& "$scriptDir\find-ip.ps1"

Write-Host "===============================================" -ForegroundColor DarkRed
Write-Host "        INGRESS SETUP COMPLETE!                " -ForegroundColor Green
Write-Host "===============================================" -ForegroundColor DarkRed
Write-Host "`nTo start the application, run:" -ForegroundColor White
Write-Host "  powershell -File scripts\start-all.ps1`n" -ForegroundColor Yellow
