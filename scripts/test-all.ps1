# Ingress - Run All Test Suites
# Executes backend, PostgreSQL concurrency and recovery checks, mobile session tests, and mobile typecheck.

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$rootDir = Split-Path -Parent $scriptDir
$apiDir = Join-Path $rootDir "api"
$mobileDir = Join-Path $rootDir "mobile"

Write-Host "===============================================" -ForegroundColor DarkRed
Write-Host "       INGRESS TEST SUITE VERIFICATION         " -ForegroundColor White
Write-Host "===============================================" -ForegroundColor DarkRed

$results = [ordered]@{}

# 1. Backend PHPUnit Suite
Write-Host "`n[1/5] Running Laravel Feature & Integration Tests..." -ForegroundColor Cyan
Set-Location $apiDir
php artisan test
if ($LASTEXITCODE -eq 0) {
    $results["Laravel Backend Suite (SQLite in-memory)"] = "PASSED"
} else {
    $results["Laravel Backend Suite (SQLite in-memory)"] = "FAILED"
}

# 2. Real PostgreSQL Concurrency Verifier
Write-Host "`n[2/5] Running Real PostgreSQL 18 Concurrency Verifier..." -ForegroundColor Cyan
& "$scriptDir\start-db.ps1"
Set-Location $apiDir
php scripts/verify_pgsql_concurrency.php
if ($LASTEXITCODE -eq 0) {
    $results["PostgreSQL 18 Concurrency Harness (Port 5433)"] = "PASSED"
} else {
    $results["PostgreSQL 18 Concurrency Harness (Port 5433)"] = "FAILED"
}

# 3. PostgreSQL Load and Recovery Verification
Write-Host "`n[3/5] Running PostgreSQL Load and Recovery Verification..." -ForegroundColor Cyan
Set-Location $apiDir
php scripts/verify_load_recovery.php
if ($LASTEXITCODE -eq 0) {
    $results["PostgreSQL Load and Recovery (1,000 attendees, 4 gates)"] = "PASSED"
} else {
    $results["PostgreSQL Load and Recovery (1,000 attendees, 4 gates)"] = "FAILED"
}

# 4. Mobile Session Tests
Write-Host "`n[4/5] Running Mobile Session & Storage Verification..." -ForegroundColor Cyan
Set-Location $mobileDir
node tests/session.test.mjs
if ($LASTEXITCODE -eq 0) {
    $results["Mobile Session & Security Logic (Node.js)"] = "PASSED"
} else {
    $results["Mobile Session & Security Logic (Node.js)"] = "FAILED"
}

# 5. Mobile TypeScript Typecheck
Write-Host "`n[5/5] Verifying Mobile TypeScript Compilation..." -ForegroundColor Cyan
Set-Location $mobileDir
npx tsc --noEmit
if ($LASTEXITCODE -eq 0) {
    $results["Mobile TypeScript Compilation (Expo SDK 58)"] = "PASSED"
} else {
    $results["Mobile TypeScript Compilation (Expo SDK 58)"] = "FAILED"
}

# Summary
Set-Location $rootDir
Write-Host "`n===============================================" -ForegroundColor DarkRed
Write-Host "              TEST RESULTS SUMMARY             " -ForegroundColor White
Write-Host "===============================================" -ForegroundColor DarkRed

$allPassed = $true
foreach ($name in $results.Keys) {
    $status = $results[$name]
    if ($status -eq "PASSED") {
        Write-Host "  [PASS] $name" -ForegroundColor Green
    } else {
        Write-Host "  [FAIL] $name" -ForegroundColor Red
        $allPassed = $false
    }
}
Write-Host "===============================================" -ForegroundColor DarkRed

if ($allPassed) {
    Write-Host "`nALL TEST SUITES PASSED CLEANLY!`n" -ForegroundColor Green
    exit 0
} else {
    Write-Host "`nSOME TESTS FAILED. Inspect output above.`n" -ForegroundColor Red
    exit 1
}
