[CmdletBinding()]
param(
    [ValidateSet('Laptop Hotspot', 'Phone Hotspot', 'Auto Detect')]
    [string] $Mode,
    [switch] $Status,
    [switch] $DryRun,
    [switch] $Apply,
    [string] $LanDemoHost,
    [switch] $Json,
    [switch] $Logs,
    [switch] $SafeStop,
    [switch] $ConfirmApply,
    [switch] $ConfirmStop
)

$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'IngressNetworkManager.psm1') -Force

try {
    $managerParameters = @{
        Status = $Status; DryRun = $DryRun; Apply = $Apply; LanDemoHost = $LanDemoHost
        Json = $Json; Logs = $Logs; SafeStop = $SafeStop; ConfirmApply = $ConfirmApply; ConfirmStop = $ConfirmStop
    }
    if ($PSBoundParameters.ContainsKey('Mode')) { $managerParameters.Mode = $Mode }
    if ($Json) {
        $result = Invoke-IngressNetworkManager @managerParameters 6>$null
        $result | ConvertTo-Json -Depth 12 -Compress
    }
    else { $null = Invoke-IngressNetworkManager @managerParameters }
}
catch {
    $safeMessage = Protect-IngressSensitiveText -Text ([string] $_.Exception.Message)
    Write-Error $safeMessage
    exit 1
}
