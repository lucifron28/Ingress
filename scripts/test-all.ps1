# Ingress - Run All Test Suites
# Executes backend, PostgreSQL concurrency and recovery checks, mobile security checks, lint, and typecheck.

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$rootDir = Split-Path -Parent $scriptDir
$apiDir = Join-Path $rootDir "api"
$mobileDir = Join-Path $rootDir "mobile"

Write-Host "===============================================" -ForegroundColor DarkRed
Write-Host "       INGRESS TEST SUITE VERIFICATION         " -ForegroundColor White
Write-Host "===============================================" -ForegroundColor DarkRed

$results = [ordered]@{}

# 1. Backend PHPUnit Suite
Write-Host "`n[1/8] Running Laravel Feature & Integration Tests..." -ForegroundColor Cyan
Set-Location $apiDir
php artisan test
if ($LASTEXITCODE -eq 0) {
    $results["Laravel Backend Suite (SQLite in-memory)"] = "PASSED"
} else {
    $results["Laravel Backend Suite (SQLite in-memory)"] = "FAILED"
}

# 2. Real PostgreSQL Concurrency Verifier
Write-Host "`n[2/8] Running Real PostgreSQL 18 Concurrency Verifier..." -ForegroundColor Cyan
& "$scriptDir\start-db.ps1"
Set-Location $apiDir
php scripts/verify_pgsql_concurrency.php
if ($LASTEXITCODE -eq 0) {
    $results["PostgreSQL 18 Concurrency Harness (Port 5433)"] = "PASSED"
} else {
    $results["PostgreSQL 18 Concurrency Harness (Port 5433)"] = "FAILED"
}

# 3. PostgreSQL Load and Recovery Verification
Write-Host "`n[3/8] Running PostgreSQL Load and Recovery Verification..." -ForegroundColor Cyan
Set-Location $apiDir
php scripts/verify_load_recovery.php
if ($LASTEXITCODE -eq 0) {
    $results["PostgreSQL Load and Recovery (1,000 attendees, 4 gates)"] = "PASSED"
} else {
    $results["PostgreSQL Load and Recovery (1,000 attendees, 4 gates)"] = "FAILED"
}

# 4. Mobile Session Tests
Write-Host "`n[4/8] Running Mobile Node Session Security Tests..." -ForegroundColor Cyan
Set-Location $mobileDir
node tests/session.test.mjs
if ($LASTEXITCODE -eq 0) {
    $results["Mobile Session & Security Logic (Node.js)"] = "PASSED"
} else {
    $results["Mobile Session & Security Logic (Node.js)"] = "FAILED"
}

# 5. Scanner-only Route Tests
Write-Host "`n[5/8] Running Mobile Scanner-Only Tests..." -ForegroundColor Cyan
Set-Location $mobileDir
node --test tests/scanner-only.test.mjs
if ($LASTEXITCODE -eq 0) {
    $results["Mobile Scanner-Only Route Tests (Node.js)"] = "PASSED"
} else {
    $results["Mobile Scanner-Only Route Tests (Node.js)"] = "FAILED"
}

# 6. Mobile Build Configuration Tests
Write-Host "`n[6/8] Running Mobile Build Security Tests..." -ForegroundColor Cyan
Set-Location $mobileDir
node --test tests/build-config.test.mjs
if ($LASTEXITCODE -eq 0) {
    $results["Mobile Build and TLS Configuration Tests (Node.js)"] = "PASSED"
} else {
    $results["Mobile Build and TLS Configuration Tests (Node.js)"] = "FAILED"
}

# 7. Mobile Lint
Write-Host "`n[7/8] Linting the Mobile App..." -ForegroundColor Cyan
Set-Location $mobileDir
npx expo lint
if ($LASTEXITCODE -eq 0) {
    $results["Mobile ESLint (Expo)"] = "PASSED"
} else {
    $results["Mobile ESLint (Expo)"] = "FAILED"
}

# 8. Mobile TypeScript Typecheck
Write-Host "`n[8/8] Verifying Mobile TypeScript Compilation..." -ForegroundColor Cyan
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
