# Ingress — Host LAN IP Discovery Helper
# Discovers the active Wi-Fi IPv4 address on this machine for phone connection.

$ErrorActionPreference = "SilentlyContinue"

Write-Host "`n=== Ingress LAN Network Discovery ===" -ForegroundColor DarkRed

# Find active Wi-Fi and Ethernet adapters with IPv4 addresses
$adapters = Get-NetIPAddress -AddressFamily IPv4 | Where-Object {
    $_.IPAddress -notlike "127.*" -and
    $_.IPAddress -notlike "169.254.*" -and
    $_.InterfaceAlias -notlike "*vEthernet*" -and
    $_.InterfaceAlias -notlike "*Tailscale*" -and
    $_.InterfaceAlias -notlike "*Loopback*"
}

$wifi = $adapters | Where-Object { $_.InterfaceAlias -like "*Wi-Fi*" -or $_.InterfaceAlias -like "*Wireless*" } | Select-Object -First 1

if (-not $wifi) {
    # Fallback to first non-virtual physical adapter
    $wifi = $adapters | Select-Object -First 1
}

if ($wifi) {
    $ip = $wifi.IPAddress
    Write-Host "Active Adapter: " -NoNewline
    Write-Host "$($wifi.InterfaceAlias)" -ForegroundColor Cyan
    Write-Host "Host LAN IP:    " -NoNewline
    Write-Host "$ip" -ForegroundColor Green
    Write-Host "`nMobile App Base URL to enter in phone settings:" -ForegroundColor Yellow
    Write-Host "http://$($ip):8000/api/v1`n" -ForegroundColor White -BackgroundColor DarkRed
} else {
    Write-Host "Could not automatically determine Wi-Fi IP address." -ForegroundColor Yellow
    Write-Host "Run 'ipconfig' manually to find your IPv4 address.`n" -ForegroundColor Gray
}
