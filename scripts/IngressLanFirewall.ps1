[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][ValidateRange(1, 65535)][int] $InterfaceIndex,
    [Parameter(Mandatory = $true)][string] $Address,
    [Parameter(Mandatory = $true)][ValidateRange(1, 65535)][int] $Port,
    [string] $ResultPath
)

$ErrorActionPreference = 'Stop'
trap {
    if (-not [string]::IsNullOrWhiteSpace($ResultPath)) {
        try {
            [pscustomobject] @{ Success = $false; Message = $_.Exception.Message } |
                ConvertTo-Json -Compress | Set-Content -LiteralPath $ResultPath -Encoding utf8
        }
        catch { }
    }
    exit 1
}

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

$adapter = Get-NetAdapter -InterfaceIndex $InterfaceIndex -IncludeHidden -ErrorAction Stop
if ($adapter.Status -ne 'Up') { throw 'The selected LAN adapter is no longer connected.' }
$ipEntry = Get-NetIPAddress -InterfaceIndex $InterfaceIndex -AddressFamily IPv4 -ErrorAction Stop |
    Where-Object { $_.IPAddress -eq $Address -and $_.AddressState -eq 'Preferred' } | Select-Object -First 1
if ($null -eq $ipEntry) { throw 'The selected private address is no longer assigned to this adapter.' }
$profile = Get-NetConnectionProfile -ErrorAction Stop | Where-Object InterfaceIndex -eq $InterfaceIndex | Select-Object -First 1
$category = if ($null -eq $profile) { 'Unknown' } else { [string] $profile.NetworkCategory }
$networkModule = Import-Module (Join-Path $PSScriptRoot 'IngressNetworkManager.psm1') -Force -PassThru
$mode = Get-IngressAdapterMode -Adapter $adapter
$unclassified = $category -eq 'Unknown' -and $mode -eq 'Laptop Hotspot'
if (-not $unclassified -and $category -notin @('DomainAuthenticated', 'Private', 'Public')) {
    throw 'The active Windows firewall profile could not be determined.'
}
$dockerProcess = Get-Process -Name 'com.docker.backend' -ErrorAction Stop | Where-Object { $_.Path } | Select-Object -First 1
if ($null -eq $dockerProcess) { throw 'Docker Desktop networking is not running.' }

$remoteCidr = (Get-IngressIPv4Range -Value "$Address/$($ipEntry.PrefixLength)").Cidr
$matchingRules = @(Get-NetFirewallRule -ErrorAction Stop | Where-Object { $_.DisplayName -eq $displayName })
$ownedRules = @($matchingRules | Where-Object { $_.Group -eq $group })
$foreignNameCollision = @($matchingRules | Where-Object { $_.Group -ne $group })
if ($foreignNameCollision.Count -gt 0) { throw 'A firewall rule with the Ingress LAN name is owned by another policy; it was not changed.' }

$firewallProfile = if ($unclassified) { 'Any' } elseif ($category -eq 'DomainAuthenticated') { 'Domain' } else { $category }
$ruleArguments = @{
    DisplayName = $displayName
    Group = $group
    Direction = 'Inbound'
    Action = 'Allow'
    Protocol = 'TCP'
    LocalPort = $Port
    LocalAddress = $Address
    RemoteAddress = $remoteCidr
    InterfaceAlias = $adapter.Name
    Program = $dockerProcess.Path
    Profile = $firewallProfile
    EdgeTraversalPolicy = 'Block'
    Enabled = 'True'
    ErrorAction = 'Stop'
}
$candidate = [pscustomobject] @{
    Mode = $mode
    Name = [string] $adapter.Name
    Address = $Address
    PrefixLength = [int] $ipEntry.PrefixLength
    NetworkCategory = $category
}
$firewallProfileState = & $networkModule { param($category, $hotspot) Get-IngressFirewallProfileState -NetworkCategory $category -AllowUnclassifiedHotspot:$hotspot } $category $unclassified
if (-not $firewallProfileState.Known -or -not $firewallProfileState.Enabled) {
    throw 'The active Windows Firewall profile must be enabled before configuring the LAN rule.'
}
$newRule = New-NetFirewallRule @ruleArguments
$verified = $false
try {
    $rules = @(& $networkModule { param($name) Get-IngressScopedFirewallRules -DisplayName $name } $displayName |
        Where-Object { $_.InstanceName -eq [string] $newRule.Name })
    $verification = Test-IngressFirewallPolicy -Rules $rules -Candidate $candidate -Port $Port `
        -RuleName $displayName
    if (-not $verification.Allowed) {
        throw 'Windows did not retain the requested app, adapter, host address, port, firewall profile, and LAN subnet scope.'
    }
    $verified = $true
}
finally {
    if (-not $verified) { Remove-NetFirewallRule -Name $newRule.Name -ErrorAction SilentlyContinue }
}
foreach ($oldRule in $ownedRules) {
    if ($oldRule.Name -ne $newRule.Name) { Remove-NetFirewallRule -Name $oldRule.Name -ErrorAction Stop }
}

$message = 'The selected adapter now allows the Event Node port only from its LAN subnet.'
if (-not [string]::IsNullOrWhiteSpace($ResultPath)) {
    [pscustomobject] @{ Success = $true; Message = $message } |
        ConvertTo-Json -Compress | Set-Content -LiteralPath $ResultPath -Encoding utf8
}
Write-Output $message
