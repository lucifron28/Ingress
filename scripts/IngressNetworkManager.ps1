[CmdletBinding()]
param(
    [ValidateSet('Laptop Hotspot', 'Phone Hotspot', 'Auto Detect')]
    [string] $Mode,
    [switch] $Status,
    [switch] $DryRun,
    [switch] $Apply,
    [string] $LanDemoHost
)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'IngressNetworkManager.psm1') -Force

try {
    $managerParameters = @{ Status = $Status; DryRun = $DryRun; Apply = $Apply; LanDemoHost = $LanDemoHost }
    if ($PSBoundParameters.ContainsKey('Mode')) { $managerParameters.Mode = $Mode }
    $null = Invoke-IngressNetworkManager @managerParameters
}
catch {
    Write-Error $_.Exception.Message
    exit 1
}
