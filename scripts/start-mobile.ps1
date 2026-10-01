# Ingress — Start Expo Mobile Application Server
# Launches the React Native development bundler.

$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$rootDir = Split-Path -Parent $scriptDir
$mobileDir = Join-Path $rootDir "mobile"

Write-Host "=== Starting Ingress Mobile (Expo) ===" -ForegroundColor DarkRed
Write-Host "Students and Gate Staff can scan the QR code using Expo Go on Android/iOS.`n" -ForegroundColor Gray

& "$scriptDir\find-ip.ps1"

Set-Location $mobileDir

if (-not (Test-Path "node_modules")) {
    Write-Host "node_modules not found. Installing packages..." -ForegroundColor Yellow
    npm install
}

Write-Host "Starting Expo Development Server (Press Ctrl+C to stop)...`n" -ForegroundColor Cyan
npx expo start
