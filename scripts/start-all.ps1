# Ingress - Master Launcher (Runs Everything)
# Starts PostgreSQL, launches Laravel Web Server and Expo Mobile Bundler in separate windows, and opens the organizer portal.

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$rootDir = Split-Path -Parent $scriptDir

Write-Host "==========================================================" -ForegroundColor DarkRed
Write-Host "         INGRESS - FULL SYSTEM LAUNCHER                   " -ForegroundColor White
Write-Host "   Manuel S. Enverga University Foundation (MSEUF)        " -ForegroundColor Yellow
Write-Host "==========================================================" -ForegroundColor DarkRed

# 1. Start PostgreSQL 18
Write-Host "`n[1/4] Starting PostgreSQL database (Port 5433)..." -ForegroundColor Cyan
& "$scriptDir\start-db.ps1"

# 2. Network Discovery
Write-Host "`n[2/4] Detecting campus LAN network configuration..." -ForegroundColor Cyan
& "$scriptDir\find-ip.ps1"

# 3. Launch Laravel Backend Server in a new window
Write-Host "[3/4] Launching Laravel Web & REST API Server window..." -ForegroundColor Cyan
Start-Process powershell -ArgumentList "-NoExit", "-ExecutionPolicy", "Bypass", "-File", "`"$scriptDir\start-web.ps1`""

# 4. Launch Expo Mobile Bundler in a new window
Write-Host "[4/4] Launching Expo Mobile Bundler window..." -ForegroundColor Cyan
Start-Process powershell -ArgumentList "-NoExit", "-ExecutionPolicy", "Bypass", "-File", "`"$scriptDir\start-mobile.ps1`""

Write-Host "`nWaiting for web server to initialize..." -ForegroundColor Gray
Start-Sleep -Seconds 3

# 5. Open browser to Organizer Login
Write-Host "Opening Organizer Portal in default browser..." -ForegroundColor Cyan
Start-Process "http://localhost:8000/login"

Write-Host "`n==========================================================" -ForegroundColor DarkRed
Write-Host "                  ALL SERVICES ACTIVE!                    " -ForegroundColor Green
Write-Host "==========================================================" -ForegroundColor DarkRed
Write-Host "Web Portal:  http://localhost:8000/login" -ForegroundColor White
Write-Host "API Base:    http://localhost:8000/api/v1" -ForegroundColor White

Write-Host "`n--- Demonstration Credentials (Password: password) ---" -ForegroundColor Yellow
Write-Host "  Organizer 1:   organizer@mseuf.edu.ph" -ForegroundColor White
Write-Host "  Organizer 2:   organizer2@mseuf.edu.ph" -ForegroundColor White
Write-Host "  Staff Entr 1:  staff.gate_a@mseuf.edu.ph" -ForegroundColor White
Write-Host "  Staff Entr 2:  staff.gate_b@mseuf.edu.ph" -ForegroundColor White
Write-Host "  Staff Entr 3:  staff.gate_c@mseuf.edu.ph" -ForegroundColor White
Write-Host "  Student 1:     a23-35524@student.mseuf.edu.ph (Ron Vincent Cada)" -ForegroundColor White
Write-Host "  Student 2:     student2@mseuf.edu.ph" -ForegroundColor White
Write-Host "  Administrator: admin@mseuf.edu.ph" -ForegroundColor White

Write-Host "`nTo shut down everything:" -ForegroundColor Gray
Write-Host "  1. Close the Laravel and Expo terminal windows." -ForegroundColor Gray
Write-Host "  2. Run: powershell -File scripts\stop-db.ps1`n" -ForegroundColor Gray
