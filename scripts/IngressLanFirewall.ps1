[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][ValidateRange(1, 65535)][int] $InterfaceIndex,
    [Parameter(Mandatory = $true)][string] $Address,
    [Parameter(Mandatory = $true)][ValidateRange(1, 65535)][int] $Port
)

$ErrorActionPreference = 'Stop'
$group = 'Ingress Node Manager'
$displayName = 'Ingress Event Node LAN Demo'

function Test-PrivateIPv4([string] $Value) {
    $ip = $null
    if (-not [System.Net.IPAddress]::TryParse($Value, [ref] $ip) -or $ip.AddressFamily -ne [System.Net.Sockets.AddressFamily]::InterNetwork) { return $false }
    $parts = $Value.Split('.') | ForEach-Object { [int] $_ }
    return $parts[0] -eq 10 -or ($parts[0] -eq 172 -and $parts[1] -ge 16 -and $parts[1] -le 31) -or ($parts[0] -eq 192 -and $parts[1] -eq 168)
}

if (-not (Test-PrivateIPv4 $Address)) { throw 'The firewall address must be a private IPv4 address.' }
if (-not ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Administrator permission is required to add the scoped LAN firewall rule.'
}

$adapter = Get-NetAdapter -InterfaceIndex $InterfaceIndex -ErrorAction Stop
if ($adapter.Status -ne 'Up') { throw 'The selected LAN adapter is no longer connected.' }
$ipEntry = Get-NetIPAddress -InterfaceIndex $InterfaceIndex -AddressFamily IPv4 -ErrorAction Stop |
    Where-Object { $_.IPAddress -eq $Address -and $_.AddressState -eq 'Preferred' } | Select-Object -First 1
if ($null -eq $ipEntry) { throw 'The selected private address is no longer assigned to this adapter.' }
$profile = Get-NetConnectionProfile -InterfaceIndex $InterfaceIndex -ErrorAction Stop | Select-Object -First 1
if ($null -eq $profile -or $profile.NetworkCategory -notin @('DomainAuthenticated', 'Private', 'Public')) {
    throw 'The active Windows firewall profile could not be determined.'
}
$dockerProcess = Get-Process -Name 'com.docker.backend' -ErrorAction Stop | Where-Object { $_.Path } | Select-Object -First 1
if ($null -eq $dockerProcess) { throw 'Docker Desktop networking is not running.' }

$modulePath = Join-Path $PSScriptRoot 'IngressNetworkManager.psm1'
Import-Module $modulePath -Force
$remoteCidr = (Get-IngressIPv4Range -Value "$Address/$($ipEntry.PrefixLength)").Cidr
$ownedRules = @(Get-NetFirewallRule -Group $group -DisplayName $displayName -ErrorAction SilentlyContinue)
$foreignNameCollision = @(Get-NetFirewallRule -DisplayName $displayName -ErrorAction SilentlyContinue | Where-Object { $_.Group -ne $group })
if ($foreignNameCollision.Count -gt 0) { throw 'A firewall rule with the Ingress LAN name is owned by another policy; it was not changed.' }

$firewallProfile = if ($profile.NetworkCategory -eq 'DomainAuthenticated') { 'Domain' } else { [string] $profile.NetworkCategory }
$newRule = New-NetFirewallRule -DisplayName $displayName -Group $group -Direction Inbound -Action Allow `
    -Protocol TCP -LocalPort $Port -LocalAddress $Address -RemoteAddress $remoteCidr `
    -InterfaceAlias $adapter.Name -Program $dockerProcess.Path -Profile $firewallProfile `
    -EdgeTraversalPolicy Block -Enabled True -ErrorAction Stop
foreach ($oldRule in $ownedRules) {
    if ($oldRule.Name -ne $newRule.Name) { Remove-NetFirewallRule -Name $oldRule.Name -ErrorAction Stop }
}
Write-Output 'The selected adapter now allows the Event Node port only from its LAN subnet.'
