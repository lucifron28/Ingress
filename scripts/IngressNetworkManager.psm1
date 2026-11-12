Set-StrictMode -Version 2.0

function Get-IngressPropertyValue {
    param(
        [Parameter(Mandatory = $true)] [object] $InputObject,
        [Parameter(Mandatory = $true)] [string] $Name,
        [object] $Default = $null
    )

    $property = $InputObject.PSObject.Properties[$Name]
    if ($null -eq $property) {
        return $Default
    }

    return $property.Value
}

function Test-IngressPrivateIPv4 {
    param([Parameter(Mandatory = $true)][string] $Address)

    $parsed = $null
    if (([System.Net.IPAddress]::TryParse($Address, [ref] $parsed) -eq $false) -or $parsed.AddressFamily -ne [System.Net.Sockets.AddressFamily]::InterNetwork) {
        return $false
    }

    $octets = $Address.Split('.') | ForEach-Object { [int] $_ }
    if ($octets.Count -ne 4 -or $octets[0] -eq 0 -or $octets[0] -eq 127 -or $octets[0] -ge 224) {
        return $false
    }

    return $octets[0] -eq 10 -or
        ($octets[0] -eq 172 -and $octets[1] -ge 16 -and $octets[1] -le 31) -or
        ($octets[0] -eq 192 -and $octets[1] -eq 168)
}

function Get-IngressAdapterMode {
    param([Parameter(Mandatory = $true)][object] $Adapter)

    $name = [string] (Get-IngressPropertyValue -InputObject $Adapter -Name 'Name' -Default '')
    $description = [string] (Get-IngressPropertyValue -InputObject $Adapter -Name 'InterfaceDescription' -Default '')
    $identity = "$name $description"

    if ($description -match '(?i)Wi-Fi Direct Virtual Adapter|Hosted Network') {
        return 'Laptop Hotspot'
    }

    if ($identity -match '(?i)Radmin|VPN|WSL|Hyper-V|Bluetooth|WAN Miniport|Teredo|Loopback|VirtualBox|VMware|Docker') {
        return $null
    }

    $isVirtual = Get-IngressPropertyValue -InputObject $Adapter -Name 'Virtual' -Default $true
    $physicalMedium = Get-IngressPropertyValue -InputObject $Adapter -Name 'NdisPhysicalMedium' -Default $null
    $mediaType = [string] (Get-IngressPropertyValue -InputObject $Adapter -Name 'MediaType' -Default '')
    $isWirelessLan = ([string] $physicalMedium -eq '9') -or $mediaType -match '(?i)802.11|wireless'

    if (-not [bool] $isVirtual -and $isWirelessLan) {
        return 'Phone Hotspot'
    }

    if (-not [bool] $isVirtual) { return 'LAN' }
    return $null
}

function Get-IngressNetworkSnapshot {
    [CmdletBinding()]
    param()

    $adapters = @(Get-NetAdapter -IncludeHidden -ErrorAction Stop)
    $addresses = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction Stop)
    $profiles = @()
    try {
        $profiles = @(Get-NetConnectionProfile -ErrorAction Stop)
    }
    catch {
        $profiles = @()
    }

    foreach ($adapter in $adapters) {
        $interfaceIndex = [int] (Get-IngressPropertyValue -InputObject $adapter -Name 'ifIndex' -Default -1)
        $profile = $profiles | Where-Object { [int] $_.InterfaceIndex -eq $interfaceIndex } | Select-Object -First 1
        $networkCategory = if ($null -ne $profile) { [string] $profile.NetworkCategory } else { 'Unknown' }
        $ipv4Entries = @($addresses | Where-Object { [int] $_.InterfaceIndex -eq $interfaceIndex } | ForEach-Object {
            [pscustomobject] @{
                Address = [string] $_.IPAddress
                PrefixLength = [int] $_.PrefixLength
                AddressState = [string] $_.AddressState
            }
        })

        [pscustomobject] @{
            Name = [string] $adapter.Name
            InterfaceDescription = [string] $adapter.InterfaceDescription
            Status = [string] $adapter.Status
            InterfaceIndex = $interfaceIndex
            Virtual = [bool] (Get-IngressPropertyValue -InputObject $adapter -Name 'Virtual' -Default $true)
            NdisPhysicalMedium = Get-IngressPropertyValue -InputObject $adapter -Name 'NdisPhysicalMedium' -Default $null
            MediaType = [string] (Get-IngressPropertyValue -InputObject $adapter -Name 'MediaType' -Default '')
            NetworkCategory = $networkCategory
            IPv4Addresses = $ipv4Entries
            CandidateMode = Get-IngressAdapterMode -Adapter $adapter
        }
    }
}

function Resolve-IngressNetworkAdapter {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][object[]] $Adapters,
        [ValidateSet('Laptop Hotspot', 'Phone Hotspot', 'Auto Detect', 'LAN')][string] $Mode = 'Auto Detect',
        [int] $InterfaceIndex = -1,
        [string] $SelectedAddress
    )

    $candidates = @()
    foreach ($adapter in $Adapters) {
        $candidateMode = [string] (Get-IngressPropertyValue -InputObject $adapter -Name 'CandidateMode' -Default '')
        if ([string]::IsNullOrWhiteSpace($candidateMode)) { continue }
        if ($InterfaceIndex -ge 0 -and [int] (Get-IngressPropertyValue -InputObject $adapter -Name 'InterfaceIndex' -Default -1) -ne $InterfaceIndex) {
            continue
        }
        if ($Mode -ne 'Auto Detect' -and $candidateMode -ne $Mode) {
            continue
        }
        if ([string] (Get-IngressPropertyValue -InputObject $adapter -Name 'Status' -Default '') -ne 'Up') {
            continue
        }

        foreach ($address in @(Get-IngressPropertyValue -InputObject $adapter -Name 'IPv4Addresses' -Default @())) {
            $ip = [string] (Get-IngressPropertyValue -InputObject $address -Name 'Address' -Default '')
            $prefix = [int] (Get-IngressPropertyValue -InputObject $address -Name 'PrefixLength' -Default 0)
            $addressState = [string] (Get-IngressPropertyValue -InputObject $address -Name 'AddressState' -Default '')
            if (-not [string]::IsNullOrWhiteSpace($SelectedAddress) -and $SelectedAddress -cne $ip) { continue }
            if ($prefix -lt 1 -or $prefix -gt 32 -or $addressState -ne 'Preferred' -or -not (Test-IngressPrivateIPv4 -Address $ip)) {
                continue
            }

            $candidates += [pscustomobject] @{
                Mode = $candidateMode
                Name = [string] (Get-IngressPropertyValue -InputObject $adapter -Name 'Name' -Default '')
                InterfaceDescription = [string] (Get-IngressPropertyValue -InputObject $adapter -Name 'InterfaceDescription' -Default '')
                InterfaceIndex = [int] (Get-IngressPropertyValue -InputObject $adapter -Name 'InterfaceIndex' -Default -1)
                Address = $ip
                PrefixLength = $prefix
                NetworkCategory = [string] (Get-IngressPropertyValue -InputObject $adapter -Name 'NetworkCategory' -Default 'Unknown')
            }
        }
    }

    if ($candidates.Count -eq 0) {
        throw "No active private IPv4 adapter matches the selected network. Connect the computer to a LAN or hotspot and refresh."
    }
    if ($candidates.Count -gt 1) {
        $names = ($candidates | ForEach-Object { "$($_.Name) [$($_.Address)]" }) -join ', '
        throw "Network selection is ambiguous: $names. Choose the adapter and address to use."
    }

    $selected = $candidates[0]
    if ($InterfaceIndex -lt 0 -and $Mode -eq 'Auto Detect' -and $selected.Mode -eq 'Phone Hotspot') {
        $selected | Add-Member -NotePropertyName DetectionNote -NotePropertyValue 'One physical Wi-Fi candidate is active; confirm the phone can join this network.'
    }

    return $selected
}

function ConvertTo-IngressIPv4Number {
    param([Parameter(Mandatory = $true)][string] $Address)

    $parsed = $null
    if (([System.Net.IPAddress]::TryParse($Address, [ref] $parsed) -eq $false) -or $parsed.AddressFamily -ne [System.Net.Sockets.AddressFamily]::InterNetwork) {
        throw "Invalid IPv4 address: $Address"
    }

    $octets = $Address.Split('.') | ForEach-Object { [long] $_ }
    return ($octets[0] * 16777216) + ($octets[1] * 65536) + ($octets[2] * 256) + $octets[3]
}

function ConvertTo-IngressIPv4Address {
    param([Parameter(Mandatory = $true)][long] $Number)

    $a = [math]::Floor($Number / 16777216)
    $b = [math]::Floor(($Number % 16777216) / 65536)
    $c = [math]::Floor(($Number % 65536) / 256)
    $d = $Number % 256
    return "$a.$b.$c.$d"
}

function Get-IngressIPv4Range {
    param([Parameter(Mandatory = $true)][string] $Value)

    $parts = $Value.Trim().Split('/')
    if ($parts.Count -gt 2 -or [string]::IsNullOrWhiteSpace($parts[0])) {
        throw "Invalid IPv4 scope: $Value"
    }

    $prefix = 32
    if ($parts.Count -eq 2) {
        if ($parts[1] -match '\A[0-9]{1,2}\z') {
            $prefix = [int] $parts[1]
        }
        else {
            $maskOctets = $parts[1].Split('.')
            if ($maskOctets.Count -ne 4) {
                throw "Invalid IPv4 mask: $Value"
            }
            $zeroSeen = $false
            $prefix = 0
            foreach ($octetText in $maskOctets) {
                $octet = [int] $octetText
                if ($octet -lt 0 -or $octet -gt 255) {
                    throw "Invalid IPv4 mask: $Value"
                }
                for ($bit = 7; $bit -ge 0; $bit--) {
                    $isOne = (($octet -band (1 -shl $bit)) -ne 0)
                    if ($isOne -and $zeroSeen) {
                        throw "Non-contiguous IPv4 mask: $Value"
                    }
                    if ($isOne) { $prefix++ } else { $zeroSeen = $true }
                }
            }
        }
    }
    if ($prefix -lt 0 -or $prefix -gt 32) {
        throw "Invalid IPv4 prefix: $Value"
    }

    $number = ConvertTo-IngressIPv4Number -Address $parts[0]
    $hostSize = [long] [math]::Pow(2, 32 - $prefix)
    $mask = [long] [math]::Floor([math]::Pow(2, 32) - $hostSize)
    $network = $number -band $mask

    return [pscustomobject] @{
        Start = $network
        End = ($network + $hostSize - 1)
        PrefixLength = $prefix
        Network = (ConvertTo-IngressIPv4Address -Number $network)
        Cidr = "$(ConvertTo-IngressIPv4Address -Number $network)/$prefix"
    }
}

function Test-IngressRemoteAddressScope {
    param(
        [Parameter(Mandatory = $true)][string[]] $RemoteAddresses,
        [Parameter(Mandatory = $true)][string] $TargetAddress,
        [Parameter(Mandatory = $true)][int] $TargetPrefix
    )

    if ($RemoteAddresses.Count -eq 0) {
        return $false
    }

    $target = Get-IngressIPv4Range -Value "$TargetAddress/$TargetPrefix"
    foreach ($remoteAddress in $RemoteAddresses) {
        if ($remoteAddress -match '(?i)\A(?:Any|LocalSubnet|Internet|Intranet|DefaultGateway)\z') {
            return $false
        }
        try {
            $remote = Get-IngressIPv4Range -Value $remoteAddress
        }
        catch {
            return $false
        }
        if ($remote.PrefixLength -lt $target.PrefixLength -or $remote.Start -lt $target.Start -or $remote.End -gt $target.End) {
            return $false
        }
    }

    return $true
}

function Test-IngressFirewallPolicy {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][AllowEmptyCollection()][object[]] $Rules,
        [Parameter(Mandatory = $true)][object] $Candidate,
        [Parameter(Mandatory = $true)][int] $Port,
        [string] $RuleName = 'Ingress Event Node HTTPS',
        [bool] $FirewallProfileEnabled = $true
    )

    if (-not $FirewallProfileEnabled) {
        return [pscustomobject] @{
            Allowed = $false
            RuleName = $null
            RequiredRemoteScope = (Get-IngressIPv4Range -Value "$($Candidate.Address)/$($Candidate.PrefixLength)").Cidr
        }
    }

    foreach ($rule in $Rules) {
        if ([string] (Get-IngressPropertyValue -InputObject $rule -Name 'Name' -Default '') -ne $RuleName -or
            -not [bool] (Get-IngressPropertyValue -InputObject $rule -Name 'Enabled' -Default $false) -or
            [string] (Get-IngressPropertyValue -InputObject $rule -Name 'Direction' -Default '') -ne 'Inbound' -or
            [string] (Get-IngressPropertyValue -InputObject $rule -Name 'Action' -Default '') -ne 'Allow' -or
            [string] (Get-IngressPropertyValue -InputObject $rule -Name 'EdgeTraversalPolicy' -Default '') -ne 'Block') {
            continue
        }

        $profiles = @((Get-IngressPropertyValue -InputObject $rule -Name 'Profiles' -Default @()) | ForEach-Object { [string] $_ })
        $candidateProfile = [string] (Get-IngressPropertyValue -InputObject $Candidate -Name 'NetworkCategory' -Default 'Unknown')
        if ($profiles -notcontains 'Any' -and $profiles -notcontains $candidateProfile) {
            continue
        }

        $protocol = [string] (Get-IngressPropertyValue -InputObject $rule -Name 'Protocol' -Default '')
        if ($protocol -notin @('TCP', '6')) {
            continue
        }

        $localPorts = @((Get-IngressPropertyValue -InputObject $rule -Name 'LocalPorts' -Default @()) | ForEach-Object { [string] $_ })
        if ($localPorts.Count -ne 1 -or $localPorts[0] -ne [string] $Port) {
            continue
        }

        $localAddresses = @((Get-IngressPropertyValue -InputObject $rule -Name 'LocalAddresses' -Default @()) | ForEach-Object { [string] $_ })
        if ($localAddresses.Count -ne 1 -or $localAddresses[0] -ne [string] $Candidate.Address) {
            continue
        }

        $programs = @((Get-IngressPropertyValue -InputObject $rule -Name 'ApplicationPrograms' -Default @()) | ForEach-Object { [string] $_ })
        $packages = @((Get-IngressPropertyValue -InputObject $rule -Name 'ApplicationPackages' -Default @()) | ForEach-Object { [string] $_ })
        $services = @((Get-IngressPropertyValue -InputObject $rule -Name 'Services' -Default @()) | ForEach-Object { [string] $_ })
        $interfaces = @((Get-IngressPropertyValue -InputObject $rule -Name 'InterfaceAliases' -Default @()) | ForEach-Object { [string] $_ })
        $interfaceTypes = @((Get-IngressPropertyValue -InputObject $rule -Name 'InterfaceTypes' -Default @()) | ForEach-Object { [string] $_ })
        $programAllowed = $programs.Count -eq 1 -and (
            $programs[0] -eq 'Any' -or [System.IO.Path]::GetFileName($programs[0]) -ieq 'com.docker.backend.exe'
        )
        $packageAllowed = $packages.Count -eq 0 -or ($packages.Count -eq 1 -and ($packages[0] -eq 'Any' -or [string]::IsNullOrWhiteSpace($packages[0])))
        $serviceAllowed = $services.Count -eq 1 -and $services[0] -eq 'Any'
        $interfaceAllowed = $interfaces.Count -eq 1 -and $interfaces[0] -eq [string] $Candidate.Name
        $expectedInterfaceType = if ([string] $Candidate.Mode -in @('Laptop Hotspot', 'Phone Hotspot')) { 'Wireless' } else { '' }
        $interfaceTypeAllowed = $interfaceTypes.Count -eq 1 -and ($interfaceTypes[0] -eq 'Any' -or ($expectedInterfaceType -and $interfaceTypes[0] -eq $expectedInterfaceType))
        if (-not $programAllowed -or -not $packageAllowed -or -not $serviceAllowed -or -not $interfaceAllowed -or -not $interfaceTypeAllowed) {
            continue
        }

        $remoteAddresses = @((Get-IngressPropertyValue -InputObject $rule -Name 'RemoteAddresses' -Default @()) | ForEach-Object { [string] $_ })
        if (Test-IngressRemoteAddressScope -RemoteAddresses $remoteAddresses -TargetAddress $Candidate.Address -TargetPrefix ([int] $Candidate.PrefixLength)) {
            return [pscustomobject] @{
                Allowed = $true
                RuleName = [string] (Get-IngressPropertyValue -InputObject $rule -Name 'Name' -Default 'Scoped allow rule')
                RequiredRemoteScope = (Get-IngressIPv4Range -Value "$($Candidate.Address)/$($Candidate.PrefixLength)").Cidr
            }
        }
    }

    return [pscustomobject] @{
        Allowed = $false
        RuleName = $null
        RequiredRemoteScope = (Get-IngressIPv4Range -Value "$($Candidate.Address)/$($Candidate.PrefixLength)").Cidr
    }
}

function Update-IngressBindContent {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][AllowEmptyString()][string] $Content,
        [Parameter(Mandatory = $true)][string] $Address
    )

    if (-not (Test-IngressPrivateIPv4 -Address $Address)) {
        throw 'The selected binding address must be a private IPv4 address.'
    }

    $pattern = '(?m)^INGRESS_NODE_BIND_ADDRESS=[^\r\n]*'
    $matches = [regex]::Matches($Content, $pattern)
    if ($matches.Count -ne 1) {
        throw 'node-compose.env must contain exactly one INGRESS_NODE_BIND_ADDRESS assignment.'
    }

    return [regex]::Replace($Content, $pattern, "INGRESS_NODE_BIND_ADDRESS=$Address", 1)
}

function Test-IngressApplyGate {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][object] $Facts)

    $blockers = New-Object 'System.Collections.Generic.List[string]'
    $requirements = @(
        @{ Property = 'DockerReady'; Message = 'Docker Desktop or the WSL2 Docker integration is unavailable.' },
        @{ Property = 'ComposeReady'; Message = 'Docker Compose v2 could not be validated.' },
        @{ Property = 'DatabaseHealthy'; Message = 'The existing PostgreSQL container is not healthy.' },
        @{ Property = 'MigrationsComplete'; Message = 'The existing Node migration service is not confirmed successful.' },
        @{ Property = 'SessionStateKnown'; Message = 'Admission state could not be determined; no restart was attempted.' },
        @{ Property = 'CertificateMatch'; Message = 'The Node HTTPS certificate does not cover the selected IP address.' },
        @{ Property = 'ProfileMatch'; Message = 'No valid saved profile matches the selected hotspot address; the Node URL cannot be updated safely.' },
        @{ Property = 'FirewallStateKnown'; Message = 'The scoped Windows firewall policy could not be read.' },
        @{ Property = 'FirewallAllowed'; Message = 'No inbound HTTPS rule matches the selected interface and its staff subnet.' },
        @{ Property = 'ComposeValid'; Message = 'The existing Compose files failed configuration validation.' },
        @{ Property = 'OperatorPortIsolated'; Message = 'The operator port is not mapped to Windows loopback only.' },
        @{ Property = 'NodeStateKnown'; Message = 'The existing Event Node container state is unknown.' },
        @{ Property = 'NodeStateSafe'; Message = 'The Event Node is not healthy or stopped cleanly; no restart was attempted.' }
    )

    foreach ($requirement in $requirements) {
        if (-not [bool] (Get-IngressPropertyValue -InputObject $Facts -Name $requirement.Property -Default $false)) {
            $blockers.Add($requirement.Message)
        }
    }

    if ([bool] (Get-IngressPropertyValue -InputObject $Facts -Name 'ActiveAdmissionSession' -Default $true)) {
        $blockers.Add('An event on this Node can currently accept admissions; the Node was left running.')
    }

    $warnings = New-Object 'System.Collections.Generic.List[string]'
    if (-not [bool] (Get-IngressPropertyValue -InputObject $Facts -Name 'LanDemoHostMatch' -Default $false)) {
        $warnings.Add('The Android lanDemo hostname is missing or does not match this address. Set its build-time host and rebuild the isolated APK.')
    }
    if (-not [bool] (Get-IngressPropertyValue -InputObject $Facts -Name 'LanDemoTrustVerified' -Default $false)) {
        $warnings.Add('The desktop manager cannot verify which CA and hostname are installed in the Android APK; confirm its lanDemo build before scanning.')
    }

    return [pscustomobject] @{
        CanApply = ($blockers.Count -eq 0)
        Blockers = @($blockers.ToArray())
        Warnings = @($warnings.ToArray())
    }
}

function Test-IngressLanDemoApplyGate {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][object] $Facts)

    $requirements = @(
        @{ Property = 'DockerReady'; Message = 'Docker Desktop or WSL2 is unavailable.' },
        @{ Property = 'ComposeReady'; Message = 'Docker Compose v2 could not be validated.' },
        @{ Property = 'DatabaseHealthy'; Message = 'The existing PostgreSQL container is not healthy.' },
        @{ Property = 'MigrationsComplete'; Message = 'The existing Node migration service is not confirmed successful.' },
        @{ Property = 'SessionStateKnown'; Message = 'Admission state could not be determined; no restart was attempted.' },
        @{ Property = 'FirewallStateKnown'; Message = 'The active Windows Firewall profile could not be read.' },
        @{ Property = 'FirewallAllowed'; Message = 'The LAN demo firewall rule does not allow this adapter and subnet.' },
        @{ Property = 'ComposeValid'; Message = 'The LAN demo Compose configuration is invalid.' },
        @{ Property = 'OperatorPortIsolated'; Message = 'The operator console is not bound to Windows loopback only.' },
        @{ Property = 'NodeStateKnown'; Message = 'The existing Event Node state is unknown.' },
        @{ Property = 'NodeStateSafe'; Message = 'The Event Node is unhealthy or not stopped cleanly; no restart was attempted.' }
    )
    $blockers = [System.Collections.Generic.List[string]]::new()
    foreach ($requirement in $requirements) {
        if (-not [bool] (Get-IngressPropertyValue -InputObject $Facts -Name $requirement.Property -Default $false)) {
            $blockers.Add($requirement.Message)
        }
    }
    if ([bool] (Get-IngressPropertyValue -InputObject $Facts -Name 'ActiveAdmissionSession' -Default $true)) {
        $blockers.Add('An event can currently accept admissions; the Node was left running.')
    }
    return [pscustomobject] @{ CanApply = ($blockers.Count -eq 0); Blockers = @($blockers.ToArray()); Warnings = @() }
}

function Test-IngressSafeStopGate {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][object] $Facts)

    $blockers = New-Object 'System.Collections.Generic.List[string]'
    $requirements = @(
        @{ Property = 'NodeStateKnown'; Message = 'The Event Node state is unknown; it was left running.' },
        @{ Property = 'NodeHealthy'; Message = 'The Event Node is not healthy; it was left running for inspection.' },
        @{ Property = 'DatabaseHealthy'; Message = 'The PostgreSQL database is not healthy; the Node was left running.' },
        @{ Property = 'AdmissionStateKnown'; Message = 'Admission state is unknown; the Node was left running.' },
        @{ Property = 'PendingSyncStateKnown'; Message = 'Pending synchronization state is unknown; the Node was left running.' },
        @{ Property = 'AuthorityStateKnown'; Message = 'Event authority state is unknown; the Node was left running.' },
        @{ Property = 'OperatorPortIsolated'; Message = 'The operator port is not verified loopback-only; the Node was left running.' }
    )

    foreach ($requirement in $requirements) {
        if (-not [bool] (Get-IngressPropertyValue -InputObject $Facts -Name $requirement.Property -Default $false)) {
            $blockers.Add($requirement.Message)
        }
    }

    if ([bool] (Get-IngressPropertyValue -InputObject $Facts -Name 'AdmissionsPossible' -Default $true)) {
        $blockers.Add('An event may still accept admissions; the Node was left running.')
    }

    $pending = Get-IngressPropertyValue -InputObject $Facts -Name 'PendingSyncRecords' -Default $null
    if ($null -eq $pending) {
        if ([bool] (Get-IngressPropertyValue -InputObject $Facts -Name 'PendingSyncStateKnown' -Default $false)) {
            $blockers.Add('Pending synchronization count is missing; the Node was left running.')
        }
    }
    elseif ([int] $pending -ne 0) {
        $blockers.Add("$([int] $pending) pending records remain unsynchronized; the Node was left running.")
    }

    if ([bool] (Get-IngressPropertyValue -InputObject $Facts -Name 'AuthorityUncertain' -Default $true)) {
        $blockers.Add('At least one event authority is uncertain; the Node was left running.')
    }

    return [pscustomobject] @{ CanStop = ($blockers.Count -eq 0); Blockers = @($blockers.ToArray()) }
}

function New-IngressNodeStopArguments {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string] $Project,
        [Parameter(Mandatory = $true)][string] $WorkingDirectory,
        [Parameter(Mandatory = $true)][string] $EnvironmentFile,
        [Parameter(Mandatory = $true)][string[]] $ComposeFiles
    )

    $arguments = New-Object 'System.Collections.Generic.List[string]'
    foreach ($argument in @('--project-name', $Project, '--project-directory', $WorkingDirectory, '--env-file', $EnvironmentFile)) {
        $arguments.Add($argument)
    }
    foreach ($file in $ComposeFiles) {
        $arguments.Add('-f')
        $arguments.Add($file)
    }
    foreach ($argument in @('stop', '--timeout', '30', 'ingress-node')) {
        $arguments.Add($argument)
    }

    return @($arguments.ToArray())
}

function New-IngressComposeUpArguments {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string] $Project,
        [Parameter(Mandatory = $true)][string] $WorkingDirectory,
        [Parameter(Mandatory = $true)][string] $EnvironmentFile,
        [Parameter(Mandatory = $true)][string[]] $ComposeFiles,
        [Parameter(Mandatory = $true)][bool] $ForceRecreate,
        [switch] $Build
    )

    $arguments = New-Object 'System.Collections.Generic.List[string]'
    foreach ($argument in @('--project-name', $Project, '--project-directory', $WorkingDirectory, '--env-file', $EnvironmentFile)) {
        $arguments.Add($argument)
    }
    foreach ($file in $ComposeFiles) {
        $arguments.Add('-f')
        $arguments.Add($file)
    }
    $arguments.Add('up')
    $arguments.Add('-d')
    if ($Build) { $arguments.Add('--build') }
    $arguments.Add('--no-deps')
    if ($ForceRecreate) {
        $arguments.Add('--force-recreate')
    }
    $arguments.Add('ingress-node')

    return @($arguments.ToArray())
}

function Invoke-IngressPlannedChange {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][object] $Gate,
        [Parameter(Mandatory = $true)][bool] $BindChanged,
        [Parameter(Mandatory = $true)][bool] $NodeRunning,
        [Parameter(Mandatory = $true)][string] $TargetAddress,
        [Parameter(Mandatory = $true)][string] $TargetAppUrl,
        [string] $PreviousAddress,
        [string] $PreviousAppUrl,
        [Parameter(Mandatory = $true)][scriptblock] $WriteBinding,
        [Parameter(Mandatory = $true)][scriptblock] $ApplyNode
    )

    if (-not $Gate.CanApply) {
        return [pscustomobject] @{ Outcome = 'Blocked'; Changed = $false }
    }
    if (-not $BindChanged -and $NodeRunning) {
        return [pscustomobject] @{ Outcome = 'NoChange'; Changed = $false }
    }

    if ($BindChanged) {
        & $WriteBinding $TargetAddress $TargetAppUrl
    }
    try {
        & $ApplyNode $BindChanged
    }
    catch {
        if ($BindChanged -and -not [string]::IsNullOrWhiteSpace($PreviousAddress) -and -not [string]::IsNullOrWhiteSpace($PreviousAppUrl)) {
            $previousSettingsRestored = $true
            try { & $WriteBinding $PreviousAddress $PreviousAppUrl }
            catch { $previousSettingsRestored = $false }
            if ($previousSettingsRestored) {
                throw 'Node start or recreation failed. The previous bind address and HTTPS URL were restored; no second restart was attempted.'
            }
            throw 'Node start or recreation failed, and the previous network settings could not be restored safely. The Node was not restarted again; inspect the env file before retrying.'
        }
        throw 'Node start failed. No network settings were changed; refresh status before retrying.'
    }

    return [pscustomobject] @{ Outcome = $(if ($BindChanged) { 'RecreatedNode' } else { 'StartedNode' }); Changed = $true }
}

function Invoke-IngressProcess {
    param([string] $Executable, [string[]] $Arguments, [scriptblock] $CommandRunner, [switch] $SuppressStderr)
    if ($null -ne $CommandRunner) {
        $result = & $CommandRunner $Executable $Arguments
        if ($null -eq $result) { return [pscustomobject] @{ ExitCode = 1; Output = '' } }
        return [pscustomobject] @{
            ExitCode = [int] (Get-IngressPropertyValue $result 'ExitCode' 1)
            Output = [string] (Get-IngressPropertyValue $result 'Output' '')
        }
    }
    try {
        if ($SuppressStderr) { $output = & $Executable @Arguments 2>$null }
        else { $output = & $Executable @Arguments 2>&1 }
        return [pscustomobject] @{
            ExitCode = [int] $LASTEXITCODE
            Output = (@($output | ForEach-Object { [string] $_ }) -join [Environment]::NewLine)
        }
    }
    catch { return [pscustomobject] @{ ExitCode = 1; Output = '' } }
}

function Invoke-IngressWslCommand {
    param([string] $Distro, [string[]] $Arguments, [scriptblock] $CommandRunner, [switch] $SuppressStderr)
    $wslPath = if ($env:WINDIR) { Join-Path $env:WINDIR 'System32\wsl.exe' } else { 'wsl.exe' }
    return Invoke-IngressProcess -Executable $wslPath -Arguments (@('-d', $Distro, '--exec') + $Arguments) -CommandRunner $CommandRunner -SuppressStderr:$SuppressStderr
}

function Get-IngressRunningWslDistros {
    param([scriptblock] $CommandRunner)
    $wslPath = if ($env:WINDIR) { Join-Path $env:WINDIR 'System32\wsl.exe' } else { 'wsl.exe' }
    $result = Invoke-IngressProcess -Executable $wslPath -Arguments @('--list', '--running', '--quiet') -CommandRunner $CommandRunner
    if ($result.ExitCode -ne 0) { throw 'Windows Subsystem for Linux could not be queried.' }
    return @($result.Output -split '\r?\n' | ForEach-Object { ($_ -replace [char] 0, '').Trim() } | Where-Object { $_ -and $_ -notmatch '(?i)^There are no running distributions' })
}

function Get-IngressComposeContainerIds {
    param([string] $Distro, [string] $Project, [string] $Service, [scriptblock] $CommandRunner)
    $result = Invoke-IngressWslCommand -Distro $Distro -Arguments @(
        'docker', 'ps', '-a', '-q', '--filter', "label=com.docker.compose.project=$Project",
        '--filter', "label=com.docker.compose.service=$Service"
    ) -CommandRunner $CommandRunner
    if ($result.ExitCode -ne 0) { throw 'Docker container metadata could not be read.' }
    return @($result.Output -split '\r?\n' | ForEach-Object { $_.Trim() } | Where-Object { $_ })
}

function Get-IngressDockerLabel {
    param([string] $Distro, [string] $ContainerId, [string] $Label, [scriptblock] $CommandRunner)
    $format = '{{json .Config.Labels}}'
    $result = Invoke-IngressWslCommand -Distro $Distro -Arguments @('docker', 'inspect', '--format', $format, $ContainerId) -CommandRunner $CommandRunner
    if ($result.ExitCode -ne 0) { throw "Docker Compose labels could not be read from WSL distro '$Distro'." }
    try {
        $labels = $result.Output | ConvertFrom-Json
        $property = $labels.PSObject.Properties[$Label]
        if ($null -eq $property) { return '' }
        return [string] $property.Value
    }
    catch { throw 'Docker Compose labels could not be parsed.' }
}

function Get-IngressComposeContext {
    param([scriptblock] $CommandRunner)
    $contexts = New-Object 'System.Collections.Generic.List[object]'
    foreach ($distro in Get-IngressRunningWslDistros -CommandRunner $CommandRunner) {
        if ($distro -match '(?i)^docker-desktop(?:-data)?$') { continue }
        $dockerInfo = Invoke-IngressWslCommand -Distro $distro -Arguments @('docker', 'info', '--format', '{{.ServerVersion}}') -CommandRunner $CommandRunner -SuppressStderr
        if ($dockerInfo.ExitCode -ne 0 -or [string]::IsNullOrWhiteSpace($dockerInfo.Output)) { continue }
        $kernel = Invoke-IngressWslCommand -Distro $distro -Arguments @('uname', '-r') -CommandRunner $CommandRunner -SuppressStderr
        $composeVersion = Invoke-IngressWslCommand -Distro $distro -Arguments @('docker', 'compose', 'version', '--short') -CommandRunner $CommandRunner -SuppressStderr
        if ($kernel.ExitCode -ne 0 -or $kernel.Output -notmatch '(?i)WSL2' -or $composeVersion.ExitCode -ne 0 -or $composeVersion.Output -notmatch '^v?[2-9][0-9]*\.') { continue }

        $project = 'ingress-node'
        $containerIds = @()
        foreach ($service in @('ingress-node', 'ingress-db', 'ingress-scheduler')) {
            $containerIds += Get-IngressComposeContainerIds -Distro $distro -Project $project -Service $service -CommandRunner $CommandRunner
        }
        $containerIds = @($containerIds | Select-Object -Unique)
        if ($containerIds.Count -eq 0) { continue }

        $metadataId = $containerIds[0]
        $metadataDistro = Get-IngressDockerLabel -Distro $distro -ContainerId $metadataId -Label 'desktop.docker.io/wsl-distro' -CommandRunner $CommandRunner
        if ($metadataDistro -and $metadataDistro -ne $distro) { continue }

        $workingDirectory = Get-IngressDockerLabel -Distro $distro -ContainerId $metadataId -Label 'com.docker.compose.project.working_dir' -CommandRunner $CommandRunner
        $environmentFile = Get-IngressDockerLabel -Distro $distro -ContainerId $metadataId -Label 'com.docker.compose.project.environment_file' -CommandRunner $CommandRunner
        $configFilesLabel = Get-IngressDockerLabel -Distro $distro -ContainerId $metadataId -Label 'com.docker.compose.project.config_files' -CommandRunner $CommandRunner
        $actualProject = Get-IngressDockerLabel -Distro $distro -ContainerId $metadataId -Label 'com.docker.compose.project' -CommandRunner $CommandRunner
        if ($actualProject -ne $project -or [string]::IsNullOrWhiteSpace($workingDirectory) -or [System.IO.Path]::GetFileName($environmentFile) -ne 'node-compose.env' -or [string]::IsNullOrWhiteSpace($configFilesLabel)) { continue }

        $composeFiles = @($configFilesLabel.Split(',') | ForEach-Object { $_.Trim() } | Where-Object { $_ })
        $workingPrefix = $workingDirectory.TrimEnd('/') + '/'
        if ($composeFiles.Count -eq 0 -or @($composeFiles | Where-Object { -not $_.StartsWith($workingPrefix, [StringComparison]::Ordinal) }).Count -gt 0 -or -not $environmentFile.StartsWith($workingPrefix, [StringComparison]::Ordinal)) { continue }

        $nodeIds = @(Get-IngressComposeContainerIds -Distro $distro -Project $project -Service 'ingress-node' -CommandRunner $CommandRunner)
        $dbIds = @(Get-IngressComposeContainerIds -Distro $distro -Project $project -Service 'ingress-db' -CommandRunner $CommandRunner)
        $migrateIds = @(Get-IngressComposeContainerIds -Distro $distro -Project $project -Service 'ingress-migrate' -CommandRunner $CommandRunner)
        $schedulerIds = @(Get-IngressComposeContainerIds -Distro $distro -Project $project -Service 'ingress-scheduler' -CommandRunner $CommandRunner)
        if ($nodeIds.Count -gt 1 -or $dbIds.Count -ne 1 -or $migrateIds.Count -gt 1 -or $schedulerIds.Count -gt 1) { throw 'The Ingress Compose project has ambiguous service containers.' }

        $contexts.Add([pscustomobject] @{
            Distro = $distro; Project = $project; WorkingDirectory = $workingDirectory; EnvironmentFile = $environmentFile
            ComposeFiles = $composeFiles
            NodeContainerId = if ($nodeIds.Count -eq 1) { $nodeIds[0] } else { '' }
            DatabaseContainerId = $dbIds[0]
            MigrationContainerId = if ($migrateIds.Count -eq 1) { $migrateIds[0] } else { '' }
            SchedulerContainerId = if ($schedulerIds.Count -eq 1) { $schedulerIds[0] } else { '' }
            DockerVersion = $dockerInfo.Output.Trim(); ComposeVersion = $composeVersion.Output.Trim(); Kernel = $kernel.Output.Trim()
        })
    }
    if ($contexts.Count -eq 0) { throw 'No WSL2 Docker Compose project for the Ingress Event Node was found. Keep its Compose containers available so the project can be identified safely.' }
    if ($contexts.Count -gt 1) { throw 'More than one WSL2 Ingress Docker project was found. The manager will not guess which database or env file to use.' }
    return $contexts[0]
}

function Add-IngressLanDemoComposeFile {
    param([object] $Context, [scriptblock] $CommandRunner)

    $versionMatch = [regex]::Match([string] $Context.ComposeVersion, '\d+\.\d+\.\d+')
    if (-not $versionMatch.Success -or [version] $versionMatch.Value -lt [version] '2.24.4') {
        throw 'The LAN demo needs Docker Compose 2.24.4 or newer to replace the HTTPS port bindings safely. Update Docker Desktop, then refresh the manager.'
    }

    $overlay = $Context.WorkingDirectory.TrimEnd('/') + '/compose.node.lan-demo.yaml'
    $check = @'
set -eu
path="$1"
[[ -f "$path" && ! -L "$path" ]]
'@
    $check = $check.Replace([Environment]::NewLine, [string] [char] 10)
    $result = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @(
        'bash', '--noprofile', '--norc', '-c', $check, '--', $overlay
    ) -CommandRunner $CommandRunner -SuppressStderr
    if ($result.ExitCode -ne 0) { throw 'The LAN demo Compose overlay is missing from the existing Event Node project.' }

    $alreadyUsingOverlay = @($Context.ComposeFiles | Where-Object { $_ -eq $overlay }).Count -gt 0
    $files = @($Context.ComposeFiles | Where-Object { $_ -ne $overlay }) + $overlay
    $Context.ComposeFiles = $files
    $Context | Add-Member -Force -NotePropertyName LanDemoComposeFile -NotePropertyValue $overlay
    $Context | Add-Member -Force -NotePropertyName LanDemoBuildRequired -NotePropertyValue (-not $alreadyUsingOverlay)
    return $Context
}

function Read-IngressNodeEnvironment {
    param([object] $Context, [scriptblock] $CommandRunner)
    $pattern = '^(APP_URL|INGRESS_NODE_BIND_ADDRESS|INGRESS_NODE_HTTPS_PORT|INGRESS_NODE_HTTP_PORT|INGRESS_OPERATOR_PORT)='
    $result = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @('grep', '-E', $pattern, $Context.EnvironmentFile) -CommandRunner $CommandRunner -SuppressStderr
    if ($result.ExitCode -ne 0) { throw 'The allowed Node network settings could not be read from node-compose.env.' }

    $settings = @{}
    foreach ($line in @($result.Output -split '\r?\n' | Where-Object { $_ })) {
        $parts = $line.Split('=', 2)
        if ($parts.Count -ne 2 -or $settings.ContainsKey($parts[0])) { throw 'node-compose.env contains duplicate or malformed network settings.' }
        $settings[$parts[0]] = $parts[1].Trim().Trim('"')
    }
    if (-not $settings.ContainsKey('INGRESS_NODE_BIND_ADDRESS')) { throw 'node-compose.env is missing INGRESS_NODE_BIND_ADDRESS.' }
    if (-not $settings.ContainsKey('APP_URL')) { throw 'node-compose.env is missing APP_URL.' }
    return [pscustomobject] $settings
}
function Get-IngressContainerState {
    param([object] $Context, [string] $ContainerId, [scriptblock] $CommandRunner)
    if ([string]::IsNullOrWhiteSpace($ContainerId)) {
        return [pscustomobject] @{ Known = $true; Status = 'missing'; Health = 'none'; ExitCode = -1 }
    }
    $format = '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}|{{.State.ExitCode}}'
    $result = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @('docker', 'inspect', '--format', $format, $ContainerId) -CommandRunner $CommandRunner
    if ($result.ExitCode -ne 0) { return [pscustomobject] @{ Known = $false; Status = 'unknown'; Health = 'unknown'; ExitCode = -1 } }
    $parts = $result.Output.Trim().Split('|')
    if ($parts.Count -ne 3) { return [pscustomobject] @{ Known = $false; Status = 'unknown'; Health = 'unknown'; ExitCode = -1 } }
    return [pscustomobject] @{ Known = $true; Status = $parts[0]; Health = $parts[1]; ExitCode = [int] $parts[2] }
}

function Test-IngressNodeStateSafe {
    param([object] $State)
    if (-not [bool] (Get-IngressPropertyValue -InputObject $State -Name 'Known' -Default $false)) { return $false }
    $status = [string] (Get-IngressPropertyValue -InputObject $State -Name 'Status' -Default '')
    if ($status -eq 'running') {
        return [string] (Get-IngressPropertyValue -InputObject $State -Name 'Health' -Default '') -eq 'healthy'
    }
    return $status -in @('exited', 'created', 'dead')
}

function Get-IngressActualPortStatus {
    param([object] $Context, [string] $ContainerId, [scriptblock] $CommandRunner, [string] $Address, [int] $HttpsPort, [int] $OperatorPort, [switch] $LanDemo, [int] $HttpPort = 8000)
    if ([string]::IsNullOrWhiteSpace($ContainerId)) {
        return [pscustomobject] @{ Known = $false; NodeBinding = ''; OperatorBinding = ''; NodeMatches = $false; OperatorIsolated = $false }
    }
    $containerPort = if ($LanDemo) { '8080/tcp' } else { '8443/tcp' }
    $publishedPort = if ($LanDemo) { $HttpPort } else { $HttpsPort }
    $node = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @('docker', 'port', $ContainerId, $containerPort) -CommandRunner $CommandRunner -SuppressStderr
    $operator = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @('docker', 'port', $ContainerId, '8444/tcp') -CommandRunner $CommandRunner -SuppressStderr
    if (($node.ExitCode -ne 0 -and -not $LanDemo) -or $operator.ExitCode -ne 0) {
        return [pscustomobject] @{ Known = $false; NodeBinding = ''; OperatorBinding = ''; NodeMatches = $false; OperatorIsolated = $false }
    }
    $nodeBindings = [string[]] @()
    if ($node.ExitCode -eq 0) {
        $nodeBindings = [string[]] @($node.Output -split '\r?\n' | ForEach-Object { $_.Trim() } | Where-Object { $_ })
    }
    $operatorBindings = [string[]] @($operator.Output -split '\r?\n' | ForEach-Object { $_.Trim() } | Where-Object { $_ })
    $operatorIsolated = $operatorBindings.Count -eq 1 -and $operatorBindings[0] -eq ('127.0.0.1:' + $OperatorPort)
    $nodeMatches = $nodeBindings.Count -eq 1 -and $nodeBindings[0] -eq ($Address + ':' + $publishedPort)
    return [pscustomobject] @{
        Known = (($nodeBindings.Count -eq 1 -or ($LanDemo -and $nodeBindings.Count -eq 0)) -and $operatorBindings.Count -eq 1)
        NodeBinding = if ($nodeBindings.Count -eq 1) { $nodeBindings[0] } else { '' }
        OperatorBinding = if ($operatorBindings.Count -eq 1) { $operatorBindings[0] } else { '' }
        NodeMatches = $nodeMatches
        OperatorIsolated = $operatorIsolated
    }
}

function Get-IngressComposeSummary {
    param([object] $Context, [string] $BindAddress, [string] $AppUrl, [scriptblock] $CommandRunner, [switch] $LanDemo)
    $arguments = @('env', "INGRESS_NODE_BIND_ADDRESS=$BindAddress")
    if (-not [string]::IsNullOrWhiteSpace($AppUrl)) { $arguments += "APP_URL=$AppUrl" }
    $arguments += @('docker', 'compose', '--project-name', $Context.Project,
        '--project-directory', $Context.WorkingDirectory, '--env-file', $Context.EnvironmentFile)
    foreach ($file in $Context.ComposeFiles) { $arguments += @('-f', $file) }
    $arguments += @('config', '--format', 'json')
    $result = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments $arguments -CommandRunner $CommandRunner -SuppressStderr
    if ($result.ExitCode -ne 0) {
        return [pscustomobject] @{
            Valid = $false; OperatorPortIsolated = $false; BindMatches = $false
            CertificatePath = ''; DatabaseUser = ''; DatabaseName = ''; AppUrl = ''
        }
    }

    try {
        $configuration = $result.Output | ConvertFrom-Json
        $nodeService = $configuration.services.'ingress-node'
        $nodePorts = @($nodeService.ports)
        $databaseService = $configuration.services.'ingress-db'
        $nodeTargetPort = if ($LanDemo) { 8080 } else { 8443 }
        $nodeMappings = @($nodePorts | Where-Object { [int] $_.target -eq $nodeTargetPort -and [string] $_.protocol -eq 'tcp' })
        $operatorMappings = @($nodePorts | Where-Object { [int] $_.target -eq 8444 -and [string] $_.protocol -eq 'tcp' })
        $operatorPortIsolated = $operatorMappings.Count -eq 1 -and [string] $operatorMappings[0].host_ip -eq '127.0.0.1' -and [int] $operatorMappings[0].published -gt 0
        $bindMatches = $nodeMappings.Count -eq 1 -and [string] $nodeMappings[0].host_ip -eq $BindAddress -and [int] $nodeMappings[0].published -gt 0
        return [pscustomobject] @{
            Valid = ($null -ne $nodeService -and $null -ne $databaseService)
            OperatorPortIsolated = $operatorPortIsolated
            BindMatches = $bindMatches
            CertificatePath = if ($LanDemo) { '' } else { [string] $configuration.secrets.node_tls_certificate.file }
            DatabaseUser = [string] $databaseService.environment.POSTGRES_USER
            DatabaseName = [string] $databaseService.environment.POSTGRES_DB
            AppUrl = [string] $nodeService.environment.APP_URL
        }
    }
    catch {
        return [pscustomobject] @{
            Valid = $false; OperatorPortIsolated = $false; BindMatches = $false
            CertificatePath = ''; DatabaseUser = ''; DatabaseName = ''; AppUrl = ''
        }
    }
    finally { Remove-Variable configuration, result -ErrorAction SilentlyContinue }
}

function Test-IngressCertificateHost {
    param([string] $SubjectAlternativeNames, [string] $HostName)
    $normalizedHost = $HostName.Trim().Trim('[', ']').ToLowerInvariant()
    $parsed = $null
    if ([System.Net.IPAddress]::TryParse($normalizedHost, [ref] $parsed) -and $parsed.AddressFamily -eq [System.Net.Sockets.AddressFamily]::InterNetwork) {
        foreach ($entry in @($SubjectAlternativeNames -split ',\s*|\r?\n' | ForEach-Object { $_.Trim() })) {
            if ($entry -ieq "IP Address:$normalizedHost") { return $true }
        }
        return $false
    }
    foreach ($entry in @($SubjectAlternativeNames -split ',\s*|\r?\n' | ForEach-Object { $_.Trim() })) {
        if ($entry -notmatch '(?i)^DNS:') { continue }
        $dnsName = $entry.Substring(4).ToLowerInvariant()
        if ($dnsName -eq $normalizedHost) { return $true }
        if ($dnsName.StartsWith('*.')) {
            $suffix = $dnsName.Substring(2)
            if ($normalizedHost.EndsWith('.' + $suffix, [StringComparison]::OrdinalIgnoreCase)) {
                $label = $normalizedHost.Substring(0, $normalizedHost.Length - $suffix.Length - 1)
                if ($label -and $label.IndexOf('.') -lt 0) { return $true }
            }
        }
    }
    return $false
}

function Test-IngressUrlResolvesToAddress {
    param([string] $Url, [string] $Address)
    try {
        $uri = [uri] $Url
        if ($uri.Scheme -ne 'https' -or -not $uri.IsAbsoluteUri -or $uri.UserInfo) { return $false }
        $ip = $null
        if ([System.Net.IPAddress]::TryParse($uri.Host, [ref] $ip)) { return $uri.Host -eq $Address }
        $resolved = @([System.Net.Dns]::GetHostAddresses($uri.DnsSafeHost) | Where-Object { $_.AddressFamily -eq [System.Net.Sockets.AddressFamily]::InterNetwork } | ForEach-Object { $_.ToString() })
        return $resolved -contains $Address
    }
    catch { return $false }
}

function Get-IngressScopedFirewallRules {
    param([string] $DisplayName = 'Ingress Event Node HTTPS')
    $rules = @(Get-NetFirewallRule -DisplayName $DisplayName -ErrorAction Stop | Where-Object { [string] $_.Direction -eq 'Inbound' })
    foreach ($rule in $rules) {
        $addressFilters = @($rule | Get-NetFirewallAddressFilter -ErrorAction Stop)
        $portFilters = @($rule | Get-NetFirewallPortFilter -ErrorAction Stop)
        $applicationFilters = @($rule | Get-NetFirewallApplicationFilter -ErrorAction Stop)
        $serviceFilters = @($rule | Get-NetFirewallServiceFilter -ErrorAction Stop)
        $interfaceFilters = @($rule | Get-NetFirewallInterfaceFilter -ErrorAction Stop)
        $localAddresses = @($addressFilters | ForEach-Object { @($_.LocalAddress) })
        $remoteAddresses = @($addressFilters | ForEach-Object { @($_.RemoteAddress) })
        $localPorts = @($portFilters | ForEach-Object { @($_.LocalPort) })
        $protocols = @($portFilters | ForEach-Object { $_.Protocol })
        $profiles = @(([string] $rule.Profile) -split ',\s*' | ForEach-Object { $_.Trim() })
        $interfaceTypes = @($interfaceFilters | ForEach-Object {
            $types = @(Get-IngressPropertyValue -InputObject $_ -Name 'InterfaceType' -Default @() | Where-Object { -not [string]::IsNullOrWhiteSpace([string] $_) })
            if ($types.Count -eq 0) { 'Any' } else { $types }
        })
        [pscustomobject] @{
            Name = [string] $rule.DisplayName
            Enabled = ([string] $rule.Enabled -eq 'True')
            Direction = [string] $rule.Direction
            Action = [string] $rule.Action
            Profiles = $profiles
            EdgeTraversalPolicy = [string] (Get-IngressPropertyValue -InputObject $rule -Name 'EdgeTraversalPolicy' -Default '')
            Protocol = if ($protocols.Count -eq 1) { [string] $protocols[0] } else { 'Mixed' }
            LocalPorts = $localPorts
            LocalAddresses = $localAddresses
            RemoteAddresses = $remoteAddresses
            ApplicationPrograms = @($applicationFilters | ForEach-Object { [string] (Get-IngressPropertyValue -InputObject $_ -Name 'Program' -Default '') })
            ApplicationPackages = @($applicationFilters | ForEach-Object { [string] (Get-IngressPropertyValue -InputObject $_ -Name 'Package' -Default '') })
            Services = @($serviceFilters | ForEach-Object { [string] (Get-IngressPropertyValue -InputObject $_ -Name 'Service' -Default '') })
            InterfaceAliases = @($interfaceFilters | ForEach-Object { @(Get-IngressPropertyValue -InputObject $_ -Name 'InterfaceAlias' -Default @()) })
            InterfaceTypes = $interfaceTypes
        }
    }
}

function Get-IngressFirewallProfileState {
    param([string] $NetworkCategory)
    if ($NetworkCategory -notin @('DomainAuthenticated', 'Private', 'Public')) {
        return [pscustomobject] @{ Known = $false; Enabled = $false }
    }
    try {
        $profiles = @(Get-NetFirewallProfile -ErrorAction Stop)
        $profileName = if ($NetworkCategory -eq 'DomainAuthenticated') { 'Domain' } else { $NetworkCategory }
        $profile = $profiles | Where-Object { [string] $_.Name -eq $profileName } | Select-Object -First 1
        if ($null -eq $profile) { return [pscustomobject] @{ Known = $false; Enabled = $false } }
        return [pscustomobject] @{ Known = $true; Enabled = ([string] $profile.Enabled -eq 'True') }
    }
    catch { return [pscustomobject] @{ Known = $false; Enabled = $false } }
}

function Read-IngressAdmissionState {
    param([object] $Context, [object] $ComposeSummary, [scriptblock] $CommandRunner)
    if ([string]::IsNullOrWhiteSpace($ComposeSummary.DatabaseUser) -or [string]::IsNullOrWhiteSpace($ComposeSummary.DatabaseName)) {
        return [pscustomobject] @{ Known = $false; Active = $true; Count = $null }
    }
    $sql = "SELECT count(*) FROM node_event_authorities a JOIN events e ON e.id=a.event_id WHERE e.status NOT IN ('cancelled','completed') AND e.ends_at >= now();"
    $result = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @('docker', 'exec', $Context.DatabaseContainerId,
        'psql', '-U', $ComposeSummary.DatabaseUser, '-d', $ComposeSummary.DatabaseName, '-Atc', $sql) -CommandRunner $CommandRunner -SuppressStderr
    if ($result.ExitCode -ne 0 -or $result.Output.Trim() -notmatch '\A[0-9]+\z') {
        return [pscustomobject] @{ Known = $false; Active = $true; Count = $null }
    }
    $count = [int] $result.Output.Trim()
    return [pscustomobject] @{ Known = $true; Active = ($count -gt 0); Count = $count }
}

function Read-IngressCertificateNames {
    param([object] $Context, [string] $CertificatePath, [scriptblock] $CommandRunner)
    if ([string]::IsNullOrWhiteSpace($CertificatePath)) {
        return [pscustomobject] @{ Known = $false; Valid = $false; Names = ''; ExpiresAtUtc = $null; DaysRemaining = $null }
    }
    $san = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @('openssl', 'x509', '-in', $CertificatePath, '-noout', '-ext', 'subjectAltName') -CommandRunner $CommandRunner -SuppressStderr
    $validity = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @('openssl', 'x509', '-in', $CertificatePath, '-checkend', '0', '-noout') -CommandRunner $CommandRunner -SuppressStderr
    $expiry = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @('openssl', 'x509', '-in', $CertificatePath, '-noout', '-enddate') -CommandRunner $CommandRunner -SuppressStderr
    if ($san.ExitCode -ne 0 -or $validity.ExitCode -ne 0 -or $expiry.ExitCode -ne 0 -or [string]::IsNullOrWhiteSpace($san.Output)) {
        return [pscustomobject] @{ Known = $false; Valid = $false; Names = ''; ExpiresAtUtc = $null; DaysRemaining = $null }
    }
    $expiresAtUtc = $null
    $daysRemaining = $null
    if ($expiry.Output -match '(?im)^notAfter=([A-Z]{3})\s+([0-9]{1,2})\s+([0-9]{2}:[0-9]{2}:[0-9]{2})\s+([0-9]{4})\s+GMT\s*$') {
        try {
            $normalizedExpiry = "$($Matches[1]) $([int] $Matches[2]) $($Matches[4]) $($Matches[3]) +00:00"
            $parsed = [DateTimeOffset]::ParseExact($normalizedExpiry, 'MMM d yyyy HH:mm:ss zzz', [Globalization.CultureInfo]::InvariantCulture, [Globalization.DateTimeStyles]::AssumeUniversal)
            $expiresAtUtc = $parsed.ToUniversalTime()
            $daysRemaining = [int] [math]::Floor(($expiresAtUtc - [DateTimeOffset]::UtcNow).TotalDays)
        }
        catch { return [pscustomobject] @{ Known = $false; Valid = $false; Names = ''; ExpiresAtUtc = $null; DaysRemaining = $null } }
    }
    else { return [pscustomobject] @{ Known = $false; Valid = $false; Names = ''; ExpiresAtUtc = $null; DaysRemaining = $null } }

    return [pscustomobject] @{ Known = $true; Valid = ($validity.ExitCode -eq 0); Names = $san.Output; ExpiresAtUtc = $expiresAtUtc; DaysRemaining = $daysRemaining }
}

function Get-IngressSavedNetworkProfiles {
    param([string] $Distro, [scriptblock] $CommandRunner)

    $bash = @'
set -eu
printf '{"Known":true,"Profiles":['
first=1
emit_profile() {
    mode="$1"
    filename="$2"
    profile_path="$HOME/.config/ingress/network-profiles/$filename"
    address=""
    app_url=""
    seen_address=0
    seen_url=0
    invalid=0
    size=0
    if [[ -f "$profile_path" && ! -L "$profile_path" ]]; then
        size="$(stat -c %s -- "$profile_path" 2>/dev/null || true)"
        if [[ ! "$size" =~ ^[0-9]+$ ]] || (( size > 32768 )); then
            invalid=1
        else
            while IFS= read -r line || [[ -n "$line" ]]; do
                IFS='=' read -r key value <<< "$line"
                case "$key" in
                    INGRESS_NODE_BIND_ADDRESS)
                        if (( seen_address )); then invalid=1; else address="$value"; seen_address=1; fi
                        ;;
                    APP_URL)
                        if (( seen_url )); then invalid=1; else app_url="$value"; seen_url=1; fi
                        ;;
                esac
            done < "$profile_path"
        fi
    fi
    configured=false
    if (( ! invalid )) && [[ "$address" =~ ^(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})$ ]]; then
        IFS='.' read -r one two three four <<< "$address"
        if (( 10#$one <= 255 && 10#$two <= 255 && 10#$three <= 255 && 10#$four <= 255 )); then
            if (( one == 10 || (one == 172 && two >= 16 && two <= 31) || (one == 192 && two == 168) )); then
                if [[ "$app_url" == "https://$address" || "$app_url" == "https://$address/api/v1" ]]; then
                    configured=true
                fi
            fi
        fi
    fi
    if [[ "$configured" != true ]]; then address=""; app_url=""; fi
    if (( ! first )); then printf ','; fi
    first=0
    printf '{"Name":"%s","Mode":"%s","Address":"%s","AppUrl":"%s","IsConfigured":%s}' "$mode" "$mode" "$address" "$app_url" "$configured"
}
emit_profile "Phone Hotspot" "phone-hotspot.env"
emit_profile "Laptop Hotspot" "laptop-hotspot.env"
printf ']}\n'
'@
    $bash = $bash.Replace([Environment]::NewLine, [string] [char] 10)
    $result = Invoke-IngressWslCommand -Distro $Distro -Arguments @('bash', '--noprofile', '--norc', '-c', $bash) -CommandRunner $CommandRunner -SuppressStderr
    if ($result.ExitCode -ne 0) {
        return [pscustomobject] @{ Known = $false; Profiles = @() }
    }

    try {
        $document = $result.Output | ConvertFrom-Json
        return [pscustomobject] @{ Known = [bool] $document.Known; Profiles = @($document.Profiles) }
    }
    catch { return [pscustomobject] @{ Known = $false; Profiles = @() } }
}

function Read-IngressNodeOperationsSummary {
    param([object] $Context, [object] $ComposeSummary, [object] $NodeState, [scriptblock] $CommandRunner)

    if (-not $NodeState.Known -or $NodeState.Status -ne 'running' -or
        [string]::IsNullOrWhiteSpace($Context.NodeContainerId) -or
        [string]::IsNullOrWhiteSpace($ComposeSummary.DatabaseUser) -or
        [string]::IsNullOrWhiteSpace($ComposeSummary.DatabaseName)) {
        return [pscustomobject] @{ Known = $false; AuthorityUncertain = $true; PendingSyncRecords = $null }
    }

    $php = @'
$root = getcwd();
foreach (['APP_KEY' => '/run/secrets/app_key', 'DB_PASSWORD' => '/run/secrets/db_password', 'INGRESS_NODE_SYNC_KEY' => '/run/secrets/node_sync_key'] as $name => $path) {
    $value = @file_get_contents($path);
    if (! is_string($value) || trim($value) === '') { exit(68); }
    $value = trim($value);
    putenv($name . '=' . $value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
    unset($value);
}
require $root . '/vendor/autoload.php';
$application = require $root . '/bootstrap/app.php';
$kernel = $application->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
if ($kernel->call('ingress:node-start', ['--no-interaction' => true]) !== 0) { exit(69); }
$status = $application->make(App\Support\NodeOperatorOperationsStatus::class)->dashboard();
echo json_encode(['authorityUncertain' => (bool) $status['authorityUncertain']], JSON_THROW_ON_ERROR);
'@
    $authorityResult = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @(
        'docker', 'exec', '-w', '/app', $Context.NodeContainerId, 'php', '-r', $php
    ) -CommandRunner $CommandRunner -SuppressStderr
    if ($authorityResult.ExitCode -ne 0) {
        return [pscustomobject] @{ Known = $false; AuthorityUncertain = $true; PendingSyncRecords = $null }
    }

    try {
        $authority = $authorityResult.Output | ConvertFrom-Json
        $authorityUncertain = $authority.PSObject.Properties['authorityUncertain']
        if ($null -eq $authorityUncertain -or $authorityUncertain.Value -isnot [bool]) {
            return [pscustomobject] @{ Known = $false; AuthorityUncertain = $true; PendingSyncRecords = $null }
        }
    }
    catch { return [pscustomobject] @{ Known = $false; AuthorityUncertain = $true; PendingSyncRecords = $null } }

    $sql = @'
SELECT
  (SELECT count(*) FROM admissions a JOIN tickets t ON t.id = a.ticket_id JOIN registrations r ON r.id = t.registration_id WHERE NOT EXISTS (SELECT 1 FROM node_synced_admissions s WHERE s.admission_id = a.id))
  + (SELECT count(*) FROM node_walk_in_issuances w WHERE w.synced_at IS NULL)
  + (SELECT count(*) FROM node_walk_in_recoveries r JOIN node_walk_in_issuances w ON w.id = r.issuance_id WHERE w.synced_at IS NOT NULL AND r.synced_at IS NULL);
'@
    $pendingResult = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @(
        'docker', 'exec', $Context.DatabaseContainerId, 'psql', '-U', $ComposeSummary.DatabaseUser,
        '-d', $ComposeSummary.DatabaseName, '-Atc', $sql
    ) -CommandRunner $CommandRunner -SuppressStderr
    if ($pendingResult.ExitCode -ne 0 -or $pendingResult.Output.Trim() -notmatch '\A[0-9]+\z') {
        return [pscustomobject] @{ Known = $false; AuthorityUncertain = [bool] $authorityUncertain.Value; PendingSyncRecords = $null }
    }

    return [pscustomobject] @{
        Known = $true
        AuthorityUncertain = [bool] $authorityUncertain.Value
        PendingSyncRecords = [int] $pendingResult.Output.Trim()
    }
}

function Get-IngressLanDemoHost {
    param([string] $SpecifiedHost)
    if (-not [string]::IsNullOrWhiteSpace($SpecifiedHost)) { return $SpecifiedHost.Trim().Trim('[', ']') }
    if (-not [string]::IsNullOrWhiteSpace($env:INGRESS_LAN_DEMO_NODE_HOST)) {
        return $env:INGRESS_LAN_DEMO_NODE_HOST.Trim().Trim('[', ']')
    }

    $repositoryRoot = Split-Path -Parent $PSScriptRoot
    $localEnvironmentFile = Join-Path $repositoryRoot 'mobile\.env'
    if (Test-Path -LiteralPath $localEnvironmentFile -PathType Leaf) {
        $match = Select-String -LiteralPath $localEnvironmentFile -Pattern '^\s*(?:export\s+)?INGRESS_LAN_DEMO_NODE_HOST\s*=\s*(.*?)\s*$' | Select-Object -First 1
        if ($match) { return ([string] $match.Matches[0].Groups[1].Value).Trim().Trim('"', "'") }
    }
    return $null
}

function Invoke-IngressLanDemoNetworkManager {
    [CmdletBinding()]
    param(
        [switch] $Status,
        [switch] $DryRun,
        [switch] $Apply,
        [switch] $SafeStop,
        [switch] $ConfirmApply,
        [switch] $ConfirmStop,
        [int] $InterfaceIndex = -1,
        [string] $BindAddress,
        [object[]] $Adapters,
        [object[]] $FirewallRules,
        [scriptblock] $CommandRunner
    )

    if ($DryRun -and $Apply) { throw 'Choose either -DryRun or -Apply.' }
    if ($ConfirmApply -and -not $Apply) { throw '-ConfirmApply requires -Apply.' }
    if ($ConfirmStop -and (-not $SafeStop -or -not $Apply)) { throw '-ConfirmStop requires -SafeStop and -Apply.' }
    if ($null -eq $Adapters) { $Adapters = @(Get-IngressNetworkSnapshot) }
    $context = Add-IngressLanDemoComposeFile -Context (Get-IngressComposeContext -CommandRunner $CommandRunner) -CommandRunner $CommandRunner
    $settings = Read-IngressNodeEnvironment -Context $context -CommandRunner $CommandRunner
    $currentAddress = [string] $settings.INGRESS_NODE_BIND_ADDRESS
    if (-not (Test-IngressPrivateIPv4 -Address $currentAddress)) { throw 'The saved Node bind address is invalid.' }

    $httpPort = 8000
    $httpPortText = [string] (Get-IngressPropertyValue $settings 'INGRESS_NODE_HTTP_PORT' '8000')
    if ($httpPortText -match '\A[0-9]{1,5}\z' -and [int] $httpPortText -ge 1 -and [int] $httpPortText -le 65535) {
        $httpPort = [int] $httpPortText
    }
    else { throw 'INGRESS_NODE_HTTP_PORT must be between 1 and 65535.' }
    $operatorPortText = [string] (Get-IngressPropertyValue $settings 'INGRESS_OPERATOR_PORT' '8444')
    $operatorPort = if ($operatorPortText -match '\A[0-9]{1,5}\z' -and [int] $operatorPortText -ge 1 -and [int] $operatorPortText -le 65535) { [int] $operatorPortText } else { 8444 }

    if ($Status -or $SafeStop) {
        $candidate = Get-IngressAdapterForAddress -Adapters $Adapters -Address $currentAddress
        $targetAddress = $currentAddress
    }
    else {
        if ($InterfaceIndex -lt 0 -or [string]::IsNullOrWhiteSpace($BindAddress)) {
            throw 'Select an active LAN adapter and its private IPv4 address before previewing or starting the Node.'
        }
        $candidate = Resolve-IngressNetworkAdapter -Adapters $Adapters -Mode 'Auto Detect' -InterfaceIndex $InterfaceIndex -SelectedAddress $BindAddress
        $targetAddress = [string] $candidate.Address
    }
    if ($null -eq $candidate -and -not $Status -and -not $SafeStop) { throw 'No usable LAN adapter is selected.' }
    if ($null -ne $candidate -and [string]::IsNullOrWhiteSpace($targetAddress)) { $targetAddress = [string] $candidate.Address }

    $targetAppUrl = "http://$targetAddress`:$httpPort"
    $composeSummary = Get-IngressComposeSummary -Context $context -BindAddress $targetAddress -AppUrl $targetAppUrl -CommandRunner $CommandRunner -LanDemo
    $nodeState = Get-IngressContainerState -Context $context -ContainerId $context.NodeContainerId -CommandRunner $CommandRunner
    $databaseState = Get-IngressContainerState -Context $context -ContainerId $context.DatabaseContainerId -CommandRunner $CommandRunner
    $migrationState = Get-IngressContainerState -Context $context -ContainerId $context.MigrationContainerId -CommandRunner $CommandRunner
    $schedulerState = Get-IngressContainerState -Context $context -ContainerId $context.SchedulerContainerId -CommandRunner $CommandRunner
    $admissionState = Read-IngressAdmissionState -Context $context -ComposeSummary $composeSummary -CommandRunner $CommandRunner
    $operations = Read-IngressNodeOperationsSummary -Context $context -ComposeSummary $composeSummary -NodeState $nodeState -CommandRunner $CommandRunner
    $actualPorts = Get-IngressActualPortStatus -Context $context -ContainerId $context.NodeContainerId -CommandRunner $CommandRunner -Address $targetAddress -HttpsPort 443 -OperatorPort $operatorPort -LanDemo -HttpPort $httpPort
    $firewallProfileState = if ($candidate) { Get-IngressFirewallProfileState -NetworkCategory $candidate.NetworkCategory } else { [pscustomobject] @{ Known = $false; Enabled = $false } }
    if ($null -eq $FirewallRules) {
        $firewallKnown = $true
        try { $FirewallRules = @(Get-IngressScopedFirewallRules -DisplayName 'Ingress Event Node LAN Demo') }
        catch { $firewallKnown = $false; $FirewallRules = @() }
    }
    else { $firewallKnown = $true }
    if (-not $firewallProfileState.Known) { $firewallKnown = $false }
    $firewallPort = $httpPort
    $firewallResult = if ($candidate) {
        Test-IngressFirewallPolicy -Rules $FirewallRules -Candidate $candidate -Port $firewallPort -RuleName 'Ingress Event Node LAN Demo' -FirewallProfileEnabled $firewallProfileState.Enabled
    }
    else { [pscustomobject] @{ Allowed = $false; RuleName = $null; RequiredRemoteScope = 'unknown' } }

    $nodeHealthy = $nodeState.Known -and $nodeState.Status -eq 'running' -and $nodeState.Health -eq 'healthy'
    $databaseHealthy = $databaseState.Known -and $databaseState.Status -eq 'running' -and $databaseState.Health -eq 'healthy'
    $migrationsComplete = -not [string]::IsNullOrWhiteSpace($context.MigrationContainerId) -and $migrationState.Known -and $migrationState.Status -eq 'exited' -and $migrationState.ExitCode -eq 0
    $schedulerRunning = $schedulerState.Known -and $schedulerState.Status -eq 'running'
    $tcpProbe = $targetAddress -eq $currentAddress -and $nodeHealthy -and (Get-IngressWindowsNetworkProbe -Address $targetAddress -Port $httpPort)
    $appUrlMatches = $composeSummary.Valid -and $composeSummary.AppUrl -ceq $targetAppUrl
    $operatorPortIsolated = $composeSummary.OperatorPortIsolated -and ($nodeState.Status -ne 'running' -or ($actualPorts.Known -and $actualPorts.OperatorIsolated))
    $operatorPortStatus = if ($nodeState.Status -eq 'running' -and $actualPorts.Known) {
        if ($actualPorts.OperatorIsolated) { "127.0.0.1 only ($($actualPorts.OperatorBinding))" } else { "exposed outside loopback ($($actualPorts.OperatorBinding))" }
    }
    elseif ($composeSummary.OperatorPortIsolated) { 'configured for 127.0.0.1 only; runtime binding unavailable' }
    else { 'not verified as loopback-only' }
    $firewallStatus = if (-not $firewallKnown) { 'active firewall policy could not be read' } elseif (-not $firewallProfileState.Enabled) { "the active $($candidate.NetworkCategory) firewall profile is disabled" } elseif ($firewallResult.Allowed) { "scoped rule '$($firewallResult.RuleName)' allows TCP/$httpPort" } else { "add the app-scoped LAN rule for TCP/$httpPort on $targetAddress and $($firewallResult.RequiredRemoteScope)" }
    $admissionStatus = if (-not $admissionState.Known) { 'unknown; restart blocked' } elseif ($admissionState.Active) { 'admissions can run; restart blocked' } else { 'no admission-enabled event window' }
    $scannerReadiness = if ($nodeHealthy -and $actualPorts.NodeMatches -and $firewallResult.Allowed -and $tcpProbe) { 'LAN path ready; verify scanner pairing and ticket scans on an Android device' } else { 'Start the Node and resolve the network checks before connecting a scanner' }
    $adapterStatuses = @(
        foreach ($adapter in $Adapters) {
            foreach ($address in @($adapter.IPv4Addresses)) {
                if ([string] $adapter.CandidateMode -and (Test-IngressPrivateIPv4 -Address ([string] $address.Address))) {
                    [pscustomobject] @{
                        Name = [string] $adapter.Name
                        Description = [string] $adapter.InterfaceDescription
                        Mode = [string] $adapter.CandidateMode
                        Address = [string] $address.Address
                        PrefixLength = [int] $address.PrefixLength
                        NetworkCategory = [string] $adapter.NetworkCategory
                        IsUp = ([string] $adapter.Status -eq 'Up' -and [string] $address.AddressState -eq 'Preferred')
                        InterfaceIndex = [int] $adapter.InterfaceIndex
                    }
                }
            }
        }
    )
    $facts = [pscustomobject] @{
        DockerReady = $databaseHealthy -and $composeSummary.Valid
        ComposeReady = $context.ComposeVersion -match '^v?[0-9]+\.'
        DatabaseHealthy = $databaseHealthy
        MigrationsComplete = [bool] $migrationsComplete
        SessionStateKnown = [bool] $admissionState.Known
        ActiveAdmissionSession = [bool] $admissionState.Active
        FirewallStateKnown = [bool] $firewallKnown
        FirewallAllowed = [bool] $firewallResult.Allowed
        ComposeValid = [bool] $composeSummary.Valid -and [bool] $composeSummary.BindMatches
        OperatorPortIsolated = [bool] $operatorPortIsolated
        NodeStateKnown = [bool] $nodeState.Known
        NodeStateSafe = [bool] (Test-IngressNodeStateSafe -State $nodeState)
    }
    $gate = Test-IngressLanDemoApplyGate -Facts $facts
    $safeStopGate = Test-IngressSafeStopGate -Facts ([pscustomobject] @{
        NodeStateKnown = [bool] $nodeState.Known; NodeHealthy = [bool] $nodeHealthy; DatabaseHealthy = [bool] $databaseHealthy
        AdmissionStateKnown = [bool] $admissionState.Known; AdmissionsPossible = [bool] $admissionState.Active
        PendingSyncStateKnown = [bool] $operations.Known; PendingSyncRecords = $operations.PendingSyncRecords
        AuthorityStateKnown = [bool] $operations.Known; AuthorityUncertain = [bool] $operations.AuthorityUncertain
        OperatorPortIsolated = [bool] $operatorPortIsolated
    })
    $statusObject = [pscustomobject] @{
        SchemaVersion = 1
        NetworkMode = if ($candidate) { [string] $candidate.Mode } else { 'Unknown' }
        LanAddress = if ($candidate) { "$($candidate.Address)/$($candidate.PrefixLength)" } else { $currentAddress }
        NodeUrl = Get-IngressSafeDisplayUrl -Url $targetAppUrl
        LanMapping = "http://${targetAddress}:${httpPort}"
        LanHttpPort = $httpPort
        DockerStatus = if ($databaseHealthy -and $composeSummary.Valid) { "WSL2/Docker ready ($($context.DockerVersion), Compose $($context.ComposeVersion))" } else { 'not ready' }
        NodeStatus = if ($nodeState.Known) { "$($nodeState.Status) ($($nodeState.Health))" } else { 'unknown' }
        DatabaseStatus = if ($databaseState.Known) { "$($databaseState.Status) ($($databaseState.Health))" } else { 'unknown' }
        MigrationStatus = if ($migrationState.Known) { "$($migrationState.Status) (exit $($migrationState.ExitCode))" } else { 'unknown' }
        SchedulerStatus = if ($schedulerState.Known) { "$($schedulerState.Status) ($($schedulerState.Health))" } else { 'unknown' }
        SchedulerDetail = 'The scheduler runs in WSL2 Docker; it remains running during a Node-only network change.'
        OperatorPortStatus = $operatorPortStatus; OperatorPort = $operatorPort
        CertificateStatus = 'Not used in the isolated LAN demo'
        FirewallStatus = $firewallStatus
        AdmissionStatus = $admissionStatus
        LanDemoStatus = 'HTTP is limited to the selected private IPv4 binding.'
        LanProbeStatus = if ($tcpProbe) { 'reachable from this PC' } else { 'not reachable from this PC' }
        ScannerReadiness = $scannerReadiness
        DockerVersion = $context.DockerVersion; WslDistribution = $context.Distro
        PendingSyncRecords = $operations.PendingSyncRecords
        DockerStateKnown = $true; DockerAvailable = $true; WslStateKnown = $true; WslAvailable = $true
        NodeStateKnown = [bool] $nodeState.Known; NodeHealthy = [bool] $nodeHealthy
        DatabaseStateKnown = [bool] $databaseState.Known; DatabaseHealthy = [bool] $databaseHealthy
        MigrationStateKnown = [bool] $migrationState.Known; MigrationsComplete = [bool] $migrationsComplete
        SchedulerStateKnown = (-not [string]::IsNullOrWhiteSpace($context.SchedulerContainerId) -and [bool] $schedulerState.Known)
        SchedulerProcessRunning = [bool] $schedulerRunning; SchedulerHeartbeatVerified = $false
        OperatorPortStateKnown = [bool] ($actualPorts.Known -or ($nodeState.Status -ne 'running' -and $composeSummary.Valid))
        OperatorPortIsolated = [bool] $operatorPortIsolated
        CertificateKnown = $true; CertificateValid = $true; CertificateHostMatches = $true
        FirewallStateKnown = [bool] $firewallKnown; FirewallAllowed = [bool] $firewallResult.Allowed
        AdmissionStateKnown = [bool] $admissionState.Known; AdmissionsPossible = [bool] $admissionState.Active
        PendingSyncStateKnown = [bool] $operations.Known; AuthorityStateKnown = [bool] $operations.Known
        AuthorityUncertain = [bool] $operations.AuthorityUncertain
        LanProbeReachable = [bool] $tcpProbe; LanDemoHostMatches = [bool] $appUrlMatches; LanDemoTrustVerified = $false
        CanApplyNetworkChange = [bool] $gate.CanApply; CanSafelyStop = [bool] $safeStopGate.CanStop
        Blockers = @($gate.Blockers); SafeStopBlockers = @($safeStopGate.Blockers); Warnings = @($gate.Warnings)
        Adapters = $adapterStatuses; ProfileDiscoveryKnown = $true; TargetProfileMatches = $true
        SavedProfiles = @(); TargetProfileName = 'Current LAN'; TargetAppUrl = Get-IngressSafeDisplayUrl -Url $targetAppUrl
        CurrentBindAddress = $currentAddress; TargetBindAddress = $targetAddress
    }

    if ($SafeStop) {
        if (-not $safeStopGate.CanStop -or -not $statusObject.CanSafelyStop) { return $statusObject }
        if (-not $Apply) { return $statusObject }
        if (-not $ConfirmStop -and (Read-Host 'Type STOP to stop only ingress-node after verified closeout') -cne 'STOP') { return $statusObject }
        $latestAdmission = Read-IngressAdmissionState -Context $context -ComposeSummary $composeSummary -CommandRunner $CommandRunner
        $latestNode = Get-IngressContainerState -Context $context -ContainerId $context.NodeContainerId -CommandRunner $CommandRunner
        $latestDatabase = Get-IngressContainerState -Context $context -ContainerId $context.DatabaseContainerId -CommandRunner $CommandRunner
        $latestOperations = Read-IngressNodeOperationsSummary -Context $context -ComposeSummary $composeSummary -NodeState $latestNode -CommandRunner $CommandRunner
        $latestPorts = Get-IngressActualPortStatus -Context $context -ContainerId $context.NodeContainerId -CommandRunner $CommandRunner -Address $currentAddress -HttpsPort 443 -OperatorPort $operatorPort -LanDemo -HttpPort $httpPort
        $latestGate = Test-IngressSafeStopGate -Facts ([pscustomobject] @{
            NodeStateKnown = [bool] $latestNode.Known; NodeHealthy = [bool] ($latestNode.Status -eq 'running' -and $latestNode.Health -eq 'healthy')
            DatabaseHealthy = [bool] ($latestDatabase.Known -and $latestDatabase.Status -eq 'running' -and $latestDatabase.Health -eq 'healthy')
            AdmissionStateKnown = [bool] $latestAdmission.Known; AdmissionsPossible = [bool] $latestAdmission.Active
            PendingSyncStateKnown = [bool] $latestOperations.Known; PendingSyncRecords = $latestOperations.PendingSyncRecords
            AuthorityStateKnown = [bool] $latestOperations.Known; AuthorityUncertain = [bool] $latestOperations.AuthorityUncertain
            OperatorPortIsolated = [bool] ($latestPorts.Known -and $latestPorts.OperatorIsolated)
        })
        if (-not $latestGate.CanStop) {
            $statusObject.CanSafelyStop = $false
            $statusObject.SafeStopBlockers = @($latestGate.Blockers)
            return $statusObject
        }
        $stop = New-IngressNodeStopArguments -Project $context.Project -WorkingDirectory $context.WorkingDirectory -EnvironmentFile $context.EnvironmentFile -ComposeFiles $context.ComposeFiles
        $stopResult = Invoke-IngressWslCommand -Distro $context.Distro -Arguments (@('docker', 'compose') + $stop) -CommandRunner $CommandRunner -SuppressStderr
        if ($stopResult.ExitCode -ne 0) { throw 'Compose could not stop ingress-node safely. PostgreSQL and its volume were not changed.' }
        $stopped = $false
        for ($attempt = 0; $attempt -lt 15; $attempt++) {
            Start-Sleep -Seconds 2
            $state = Get-IngressContainerState -Context $context -ContainerId $context.NodeContainerId -CommandRunner $CommandRunner
            if ($state.Known -and $state.Status -eq 'exited') { $stopped = $true; break }
        }
        if (-not $stopped) { throw 'The Node did not confirm a clean stop within 30 seconds. PostgreSQL and its volume were not changed.' }
        $statusObject.NodeStatus = 'exited (stopped after verified closeout)'
        $statusObject.NodeHealthy = $false
        $statusObject.CanSafelyStop = $false
        $statusObject.SafeStopBlockers = @('The Event Node is stopped; refresh status before starting it again.')
        return $statusObject
    }
    if ($Status) { return $statusObject }
    if (-not $gate.CanApply) { return $statusObject }
    if (-not $Apply) { return $statusObject }
    if (-not (Test-IngressApplyConfirmation -ConfirmApply:$ConfirmApply)) { return $statusObject }

    $latestCandidate = Resolve-IngressNetworkAdapter -Adapters @(Get-IngressNetworkSnapshot) -Mode 'Auto Detect' -InterfaceIndex $InterfaceIndex -SelectedAddress $targetAddress
    if ($latestCandidate.InterfaceIndex -ne $candidate.InterfaceIndex -or $latestCandidate.Address -ne $targetAddress) {
        throw 'The selected adapter or address changed during confirmation. Refresh and select the current network.'
    }
    $latestAdmission = Read-IngressAdmissionState -Context $context -ComposeSummary $composeSummary -CommandRunner $CommandRunner
    if (-not $latestAdmission.Known) { throw 'Admission state could not be rechecked; the Event Node was not changed.' }
    if ($latestAdmission.Active) { throw 'An event can currently accept admissions; the Event Node was not changed.' }
    $latestNode = Get-IngressContainerState -Context $context -ContainerId $context.NodeContainerId -CommandRunner $CommandRunner
    if (-not (Test-IngressNodeStateSafe -State $latestNode)) { throw 'The Event Node changed state or became unhealthy; it was not restarted.' }

    $previousAddress = $currentAddress
    $previousAppUrl = [string] $settings.APP_URL
    $bindChanged = $currentAddress -ne $targetAddress -or $previousAppUrl -cne $targetAppUrl -or
        ($nodeState.Status -eq 'running' -and $actualPorts.Known -and -not $actualPorts.NodeMatches)
    $writeBinding = {
        param($address, $appUrl)
        $isHttp = $appUrl.StartsWith('http://', [StringComparison]::OrdinalIgnoreCase)
        $null = Write-IngressNodeBindAddress -Context $context -Address $address -AppUrl $appUrl -CommandRunner $CommandRunner -LanDemo:$isHttp
    }.GetNewClosure()
    $applyNode = {
        param($forceRecreate)
        $up = New-IngressComposeUpArguments -Project $context.Project -WorkingDirectory $context.WorkingDirectory -EnvironmentFile $context.EnvironmentFile -ComposeFiles $context.ComposeFiles -ForceRecreate:$forceRecreate -Build:$context.LanDemoBuildRequired
        $upResult = Invoke-IngressWslCommand -Distro $context.Distro -Arguments (@('docker', 'compose') + $up) -CommandRunner $CommandRunner -SuppressStderr
        if ($upResult.ExitCode -ne 0) { throw 'Docker Compose could not start the LAN demo Node.' }
        $healthy = $false
        $nodeIds = @()
        for ($attempt = 0; $attempt -lt 30; $attempt++) {
            Start-Sleep -Seconds 2
            $nodeIds = @(Get-IngressComposeContainerIds -Distro $context.Distro -Project $context.Project -Service 'ingress-node' -CommandRunner $CommandRunner)
            if ($nodeIds.Count -eq 1) {
                $after = Get-IngressContainerState -Context $context -ContainerId $nodeIds[0] -CommandRunner $CommandRunner
                if ($after.Status -eq 'running' -and $after.Health -eq 'healthy') { $healthy = $true; break }
            }
        }
        if (-not $healthy) { throw 'The LAN demo Node did not become healthy within 60 seconds.' }
        $postSettings = Read-IngressNodeEnvironment -Context $context -CommandRunner $CommandRunner
        $postSummary = Get-IngressComposeSummary -Context $context -BindAddress $targetAddress -AppUrl $targetAppUrl -CommandRunner $CommandRunner -LanDemo
        if (-not $postSummary.Valid -or -not $postSummary.BindMatches -or -not $postSummary.OperatorPortIsolated -or
            [string] $postSettings.INGRESS_NODE_BIND_ADDRESS -cne $targetAddress -or [string] $postSettings.APP_URL -cne $targetAppUrl) {
            throw 'The started Node did not retain the selected LAN address and operator-console isolation.'
        }
        $ports = Get-IngressActualPortStatus -Context $context -ContainerId $nodeIds[0] -CommandRunner $CommandRunner -Address $targetAddress -HttpsPort 443 -OperatorPort $operatorPort -LanDemo -HttpPort $httpPort
        if (-not $ports.Known -or -not $ports.NodeMatches -or -not $ports.OperatorIsolated) { throw 'The LAN HTTP binding or loopback-only operator binding failed verification.' }
        if (-not (Get-IngressWindowsNetworkProbe -Address $targetAddress -Port $httpPort)) { throw 'The LAN HTTP port did not pass a local TCP probe.' }
    }.GetNewClosure()
    $gateObject = [pscustomobject] @{ CanApply = $true }
    $outcome = Invoke-IngressPlannedChange -Gate $gateObject -BindChanged $bindChanged -NodeRunning ($nodeState.Status -eq 'running' -and $nodeHealthy) -TargetAddress $targetAddress -TargetAppUrl $targetAppUrl -PreviousAddress $previousAddress -PreviousAppUrl $previousAppUrl -WriteBinding $writeBinding -ApplyNode $applyNode
    $statusObject | Add-Member -Force -NotePropertyName LastOperation -NotePropertyValue $outcome.Outcome
    return $statusObject
}

function Get-IngressWindowsNetworkProbe {
    param([string] $Address, [int] $Port)
    try {
        return [bool] (Test-NetConnection -ComputerName $Address -Port $Port -InformationLevel Quiet -WarningAction SilentlyContinue -ErrorAction Stop)
    }
    catch { return $false }
}

function Get-IngressAdapterForAddress {
    param([object[]] $Adapters, [string] $Address)
    if ([string]::IsNullOrWhiteSpace($Address)) { return $null }
    try { return Resolve-IngressNetworkAdapter -Adapters $Adapters -Mode 'Auto Detect' -SelectedAddress $Address }
    catch { return $null }
}
function Write-IngressStatus {
    param([object] $Status)
    Write-Host 'Ingress Event Node network status'
    Write-Host "Network mode: $($Status.NetworkMode)"
    Write-Host "LAN IP: $($Status.LanAddress)"
    Write-Host "Node URL: $($Status.NodeUrl)"
    Write-Host "LAN mapping: $($Status.LanMapping)"
    Write-Host "Docker: $($Status.DockerStatus)"
    Write-Host "Event Node: $($Status.NodeStatus)"
    Write-Host "Operator port: $($Status.OperatorPortStatus)"
    Write-Host "TLS hostname: $($Status.CertificateStatus)"
    Write-Host "Firewall: $($Status.FirewallStatus)"
    Write-Host "Admission state: $($Status.AdmissionStatus)"
    Write-Host "Android lanDemo: $($Status.LanDemoStatus)"
    Write-Host "LAN TCP probe: $($Status.LanProbeStatus)"
    Write-Host "Scanner readiness: $($Status.ScannerReadiness)"
    foreach ($warning in $Status.Warnings) { Write-Host "Note: $warning" }
}

function Get-IngressSafeDisplayUrl {
    param([string] $Url)

    try {
        $parsed = [uri] $Url
        if (-not $parsed.IsAbsoluteUri -or $parsed.Scheme -notin @('https', 'http')) { return 'unavailable' }
        if ($parsed.Scheme -eq 'http' -and (
                -not (Test-IngressPrivateIPv4 -Address $parsed.DnsSafeHost) -or
                -not [string]::IsNullOrWhiteSpace($parsed.UserInfo) -or
                -not [string]::IsNullOrWhiteSpace($parsed.Query) -or
                -not [string]::IsNullOrWhiteSpace($parsed.Fragment))) { return 'unavailable' }
        $builder = [UriBuilder]::new($parsed)
        $builder.UserName = ''
        $builder.Password = ''
        $builder.Query = ''
        $builder.Fragment = ''
        return $builder.Uri.GetLeftPart([UriPartial]::Authority)
    }
    catch { return 'unavailable' }
}

function Protect-IngressSensitiveText {
    param([AllowEmptyString()][string] $Text)

    $Text = [regex]::Replace($Text, '(?im)(\b[A-Z0-9_]*(?:API[_-]?KEY|API[_-]?TOKEN|SECRET|PASSWORD|PRIVATE[_-]?KEY|CREDENTIALS?|TOKEN|_KEY)\b\s*[:=]\s*)("[^"]*"|''[^'']*''|[^\s,;]+)', '$1[REDACTED]')
    $Text = [regex]::Replace($Text, '(?i)([?&](?:access_token|refresh_token|token|code|credential|pairing_token|ticket_credential|verification_token)=)[^&#\s]+', '$1[REDACTED]')

    $safe = [regex]::Replace($Text, '(?im)(?<prefix>\b(?:APP_KEY|DB_URL|DB_PASSWORD|INGRESS_NODE_SYNC_KEY|INGRESS_AUTHORITY_PRIVATE_KEY|INGRESS_NODE_PRIVATE_KEY|INGRESS_NODE_TLS_PRIVATE_KEY|FIREBASE_PRIVATE_KEY|RESEND_API_KEY|MAIL_PASSWORD|PASSWORD|ACCESS_TOKEN|REFRESH_TOKEN|PAIRING_TOKEN|TICKET_CREDENTIAL)\b\s*[:=]\s*)[^\s,;]+', '${prefix}[REDACTED]')
    $safe = [regex]::Replace($safe, '(?i)(\b(?:postgres(?:ql)?|mysql)://[^:/@\s]+:)[^@/\s]+(@)', '$1[REDACTED]$2')
    $safe = [regex]::Replace($safe, '-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----', '[REDACTED PRIVATE KEY]', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
    $safe = [regex]::Replace($safe, '\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b', '[REDACTED TOKEN]')
    $safe = [regex]::Replace($safe, '(?i)https?://[^\s"''<>]*(?:/tickets?/|/pair(?:ing)?(?:/|\?)|/verify(?:/|\?))[^\s"''<>]*', '[REDACTED LINK]')
    return [regex]::Replace($safe, '(?i)(\bBearer\s+)[A-Za-z0-9._~+/-]+=*', '$1[REDACTED]')
}

function Write-IngressNodeBindAddress {
    param([object] $Context, [string] $Address, [string] $AppUrl, [scriptblock] $CommandRunner, [switch] $LanDemo)
    if (-not (Test-IngressPrivateIPv4 -Address $Address)) { throw 'The bind address must be a private IPv4 address.' }
    if (-not $LanDemo -and $AppUrl -cne "https://$Address") { throw 'The Node APP_URL must be the HTTPS origin for the selected private address.' }
    if ($LanDemo -and $AppUrl -notmatch "\Ahttp://$([regex]::Escape($Address)):[1-9][0-9]{0,4}\z") { throw 'The Node APP_URL must be an HTTP origin on the selected private address.' }

    $bash = @'
set -eu
path="$1"
address="$2"
app_url="$3"
lan_demo="$4"
case "$address" in
    *[!0-9.]*|'') echo "invalid bind address" >&2; exit 2 ;;
esac
if [[ "$address" =~ ^(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})$ ]]; then
    IFS='.' read -r one two three four <<< "$address"
    if (( 10#$one > 255 || 10#$two > 255 || 10#$three > 255 || 10#$four > 255 )); then
        echo "invalid bind address" >&2
        exit 2
    fi
else
    echo "invalid bind address" >&2
    exit 2
fi
if [[ "$lan_demo" == true ]]; then
    prefix="http://$address:"
    if [[ "$app_url" != "$prefix"* ]]; then echo "invalid Node APP_URL" >&2; exit 2; fi
    port="${app_url#"$prefix"}"
    if [[ ! "$port" =~ ^[1-9][0-9]{0,4}$ ]] || (( port > 65535 )); then echo "invalid Node APP_URL" >&2; exit 2; fi
elif [[ "$app_url" != "https://$address" ]]; then
    echo "invalid Node APP_URL" >&2
    exit 2
fi
if [[ ! -f "$path" || -L "$path" ]]; then
    echo "node-compose.env must be a regular file" >&2
    exit 3
fi
directory="$(dirname -- "$path")"
metadata="$(stat -c '%d:%i:%u:%g:%a:%s:%y' -- "$path")"
size="$(stat -c '%s' -- "$path")"
if (( size > 262144 )); then
    echo "node-compose.env exceeds the safe size limit" >&2
    exit 4
fi
mode="$(stat -c '%a' -- "$path")"
owner="$(stat -c '%u:%g' -- "$path")"
last_byte="$(tail -c 1 -- "$path" | od -An -t x1 | tr -d '[:space:]')"
temporary="$(mktemp "$directory/.node-compose.env.XXXXXX")"
cleanup() { rm -f -- "$temporary"; }
trap cleanup EXIT
awk -v address="$address" -v app_url="$app_url" '
{
    line = $0
    ending = ""
    if (line ~ /\r$/) { sub(/\r$/, "", line); ending = "\r" }
    if (line ~ /^INGRESS_NODE_BIND_ADDRESS=/) {
        bind_count++
        if (bind_count > 1) exit 5
        line = "INGRESS_NODE_BIND_ADDRESS=" address
    }
    if (line ~ /^APP_URL=/) {
        app_url_count++
        if (app_url_count > 1) exit 5
        line = "APP_URL=" app_url
    }
    printf "%s%s\n", line, ending
}
END { if (bind_count != 1 || app_url_count != 1) exit 6 }
' "$path" > "$temporary"
if [[ -L "$path" || ! -f "$path" || "$(stat -c '%d:%i:%u:%g:%a:%s:%y' -- "$path")" != "$metadata" ]]; then
    echo "node-compose.env changed while it was being prepared" >&2
    exit 7
fi
if [[ "$last_byte" != 0a ]]; then truncate -s -1 "$temporary"; fi
chown "$owner" "$temporary"
chmod "$mode" "$temporary"
sync -f "$temporary"
if cmp -s -- "$path" "$temporary"; then
    echo "unchanged"
    exit 0
fi
mv -f -- "$temporary" "$path"
trap - EXIT
sync -f "$directory"
echo "updated INGRESS_NODE_BIND_ADDRESS and APP_URL"
'@
    $bash = $bash.Replace([Environment]::NewLine, [string] [char] 10)
    $result = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @(
        'bash', '--noprofile', '--norc', '-c', $bash, '--', $Context.EnvironmentFile, $Address, $AppUrl, $(if ($LanDemo) { 'true' } else { 'false' })
    ) -CommandRunner $CommandRunner -SuppressStderr
    if ($result.ExitCode -ne 0) { throw 'The bind address could not be updated safely; the Node was not restarted.' }
    return $result.Output.Trim()
}

function Get-IngressModeFromSavedAddress {
    param([object[]] $Adapters, [string] $Address)
    $candidate = Get-IngressAdapterForAddress -Adapters $Adapters -Address $Address
    if ($candidate) { return $candidate }
    return $null
}

function Test-IngressApplyConfirmation {
    param(
        [switch] $ConfirmApply,
        [scriptblock] $Prompt = { Read-Host 'Type APPLY to update the bind address and HTTPS URL, then start/recreate only ingress-node' }
    )

    if ($ConfirmApply) { return $true }
    return ((& $Prompt) -ceq 'APPLY')
}

function Invoke-IngressNetworkManager {
    [CmdletBinding()]
    param(
        [ValidateSet('Laptop Hotspot', 'Phone Hotspot', 'Auto Detect', 'LAN')][string] $Mode,
        [switch] $Status,
        [switch] $DryRun,
        [switch] $Apply,
        [switch] $Json,
        [switch] $Logs,
        [switch] $SafeStop,
        [switch] $ConfirmApply,
        [switch] $ConfirmStop,
        [switch] $LanDemo,
        [int] $InterfaceIndex = -1,
        [string] $BindAddress,
        [string] $LanDemoHost,
        [scriptblock] $CommandRunner,
        [object[]] $Adapters,
        [object[]] $FirewallRules
    )

    if ($Logs -and ($Status -or $DryRun -or $Apply -or $SafeStop)) { throw '-Logs cannot be combined with status, preview, apply, or safe-stop operations.' }
    if ($Status -and ($DryRun -or $Apply -or $SafeStop -or $Logs)) { throw '-Status cannot be combined with preview, apply, logs, or safe-stop operations.' }
    if ($DryRun -and $Apply) { throw 'Choose either -DryRun or -Apply.' }
    if ($ConfirmApply -and -not $Apply) { throw '-ConfirmApply requires -Apply.' }
    if ($ConfirmStop -and (-not $SafeStop -or -not $Apply)) { throw '-ConfirmStop requires -SafeStop and -Apply.' }
    if ($SafeStop -and -not [string]::IsNullOrWhiteSpace($Mode)) { throw '-SafeStop operates on the current Node binding and cannot select a network mode.' }
    if ($Logs) {
        $context = Get-IngressComposeContext -CommandRunner $CommandRunner
        if ([string]::IsNullOrWhiteSpace($context.NodeContainerId)) { throw 'The Event Node container is not available; recent logs cannot be read.' }
        $result = Invoke-IngressWslCommand -Distro $context.Distro -Arguments @('docker', 'logs', '--tail', '150', $context.NodeContainerId) -CommandRunner $CommandRunner -SuppressStderr
        if ($result.ExitCode -ne 0) { throw 'Recent Event Node logs could not be read.' }
        return [pscustomobject] @{ SchemaVersion = 1; Known = $true; Lines = @($result.Output -split '\r?\n' | Select-Object -Last 150 | ForEach-Object { Protect-IngressSensitiveText -Text $_ }) }
    }
    if ($LanDemo) {
        return Invoke-IngressLanDemoNetworkManager -Status:$Status -DryRun:$DryRun -Apply:$Apply -SafeStop:$SafeStop `
            -ConfirmApply:$ConfirmApply -ConfirmStop:$ConfirmStop -InterfaceIndex $InterfaceIndex -BindAddress $BindAddress `
            -Adapters $Adapters -FirewallRules $FirewallRules -CommandRunner $CommandRunner
    }
    if (-not $Status -and -not $SafeStop -and [string]::IsNullOrWhiteSpace($Mode)) {
        Write-Host 'Choose the network to use:'
        Write-Host '  1. Laptop Hotspot'
        Write-Host '  2. Phone Hotspot'
        Write-Host '  3. Auto Detect'
        switch ((Read-Host 'Selection').Trim()) {
            '1' { $Mode = 'Laptop Hotspot' }
            '2' { $Mode = 'Phone Hotspot' }
            '3' { $Mode = 'Auto Detect' }
            default { throw 'No valid hotspot mode was selected.' }
        }
    }

    if ($null -eq $Adapters) { $Adapters = @(Get-IngressNetworkSnapshot) }
    if ($null -eq $FirewallRules) {
        $firewallKnown = $true
        try { $FirewallRules = @(Get-IngressScopedFirewallRules) }
        catch { $firewallKnown = $false; $FirewallRules = @() }
    }
    else { $firewallKnown = $true }

    $context = Get-IngressComposeContext -CommandRunner $CommandRunner
    $settings = Read-IngressNodeEnvironment -Context $context -CommandRunner $CommandRunner
    $currentAddress = [string] (Get-IngressPropertyValue $settings 'INGRESS_NODE_BIND_ADDRESS' '')
    $currentAppUrl = [string] (Get-IngressPropertyValue $settings 'APP_URL' '')
    if (-not (Test-IngressPrivateIPv4 -Address $currentAddress)) { throw 'The current Node bind setting is missing or invalid; no changes were made.' }

    $candidate = $null
    if ($Status -or $SafeStop) {
        $candidate = Get-IngressModeFromSavedAddress -Adapters $Adapters -Address $currentAddress
        if ($candidate) { $Mode = $candidate.Mode } else { $Mode = 'Unmatched or unavailable' }
        $targetAddress = $currentAddress
    }
    else {
        $candidate = Resolve-IngressNetworkAdapter -Adapters $Adapters -Mode $Mode
        $targetAddress = [string] $candidate.Address
        $Mode = [string] $candidate.Mode
    }

    $httpsPortText = [string] (Get-IngressPropertyValue $settings 'INGRESS_NODE_HTTPS_PORT' '443')
    $httpsPort = 443
    if ($httpsPortText -match '\A[0-9]{1,5}\z' -and [int] $httpsPortText -ge 1 -and [int] $httpsPortText -le 65535) { $httpsPort = [int] $httpsPortText }
    $operatorPortText = [string] (Get-IngressPropertyValue $settings 'INGRESS_OPERATOR_PORT' '8444')
    $operatorPort = 8444
    if ($operatorPortText -match '\A[0-9]{1,5}\z' -and [int] $operatorPortText -ge 1 -and [int] $operatorPortText -le 65535) { $operatorPort = [int] $operatorPortText }

    $savedProfiles = Get-IngressSavedNetworkProfiles -Distro $context.Distro -CommandRunner $CommandRunner
    $profileMode = if ($Status -or $SafeStop) { $null } elseif ($candidate) { [string] $candidate.Mode } else { $null }
    $matchingProfiles = @($savedProfiles.Profiles | Where-Object {
        [bool] $_.IsConfigured -and [string] $_.Address -eq $targetAddress -and
        ([string]::IsNullOrWhiteSpace($profileMode) -or [string] $_.Mode -eq $profileMode)
    })
    $targetProfile = if ($matchingProfiles.Count -eq 1) { $matchingProfiles[0] } else { $null }
    $targetAppUrl = ''
    $targetProfileMatches = $false
    if ($targetProfile) {
        try {
            $profileUri = [uri] ([string] $targetProfile.AppUrl)
            $allowedProfilePath = $profileUri.AbsolutePath -in @('', '/', '/api/v1')
            if ($profileUri.IsAbsoluteUri -and $profileUri.Scheme -eq 'https' -and
                [string]::IsNullOrWhiteSpace($profileUri.UserInfo) -and
                [string]::IsNullOrWhiteSpace($profileUri.Query) -and
                [string]::IsNullOrWhiteSpace($profileUri.Fragment) -and
                $profileUri.DnsSafeHost.Trim('[', ']') -eq $targetAddress -and
                $profileUri.Port -eq $httpsPort -and $allowedProfilePath) {
                $targetAppUrl = $profileUri.GetLeftPart([UriPartial]::Authority)
                $targetProfileMatches = $true
            }
        }
        catch { $targetProfileMatches = $false }
    }

    $firewallProfileState = if ($candidate) { Get-IngressFirewallProfileState -NetworkCategory $candidate.NetworkCategory } else { [pscustomobject] @{ Known = $false; Enabled = $false } }
    if (-not $firewallProfileState.Known) { $firewallKnown = $false }

    $composeAppUrl = if ($Status -or $SafeStop) { '' } elseif ($targetProfileMatches) { $targetAppUrl } else { [string] $settings.APP_URL }
    $composeSummary = Get-IngressComposeSummary -Context $context -BindAddress $targetAddress -AppUrl $composeAppUrl -CommandRunner $CommandRunner
    $nodeState = Get-IngressContainerState -Context $context -ContainerId $context.NodeContainerId -CommandRunner $CommandRunner
    $databaseState = Get-IngressContainerState -Context $context -ContainerId $context.DatabaseContainerId -CommandRunner $CommandRunner
    $migrationState = Get-IngressContainerState -Context $context -ContainerId $context.MigrationContainerId -CommandRunner $CommandRunner
    $schedulerState = Get-IngressContainerState -Context $context -ContainerId $context.SchedulerContainerId -CommandRunner $CommandRunner
    $admissionState = Read-IngressAdmissionState -Context $context -ComposeSummary $composeSummary -CommandRunner $CommandRunner
    $certificate = Read-IngressCertificateNames -Context $context -CertificatePath $composeSummary.CertificatePath -CommandRunner $CommandRunner
    $operations = Read-IngressNodeOperationsSummary -Context $context -ComposeSummary $composeSummary -NodeState $nodeState -CommandRunner $CommandRunner
    $actualPorts = Get-IngressActualPortStatus -Context $context -ContainerId $context.NodeContainerId -CommandRunner $CommandRunner -Address $targetAddress -HttpsPort $httpsPort -OperatorPort $operatorPort
    $appUrl = [string] $composeSummary.AppUrl
    $appUrlMatches = $false
    $appUrlHost = ''
    $urlPortMatches = $false
    try {
        $appUri = [uri] $appUrl
        $appUrlHost = $appUri.DnsSafeHost.Trim('[', ']')
        $urlPortMatches = $appUri.Port -eq $httpsPort
        $certificateHostMatches = $certificate.Known -and $certificate.Valid -and (Test-IngressCertificateHost -SubjectAlternativeNames $certificate.Names -HostName $appUrlHost)
        $appUrlMatches = $certificate.Known -and $certificate.Valid -and $urlPortMatches -and
            $certificateHostMatches -and
            (Test-IngressUrlResolvesToAddress -Url $appUrl -Address $targetAddress)
    }
    catch { $appUrlMatches = $false; $certificateHostMatches = $false }

    $firewallResult = if ($candidate) {
        Test-IngressFirewallPolicy -Rules $FirewallRules -Candidate $candidate -Port $httpsPort -FirewallProfileEnabled $firewallProfileState.Enabled
    }
    else {
        [pscustomobject] @{ Allowed = $false; RuleName = $null; RequiredRemoteScope = 'unknown' }
    }

    $lanDemoHostValue = Get-IngressLanDemoHost -SpecifiedHost $LanDemoHost
    $lanDemoHostMatches = $false
    if ($lanDemoHostValue -and $appUrlHost) {
        $lanDemoHostMatches = ($lanDemoHostValue -ieq $appUrlHost) -and $appUrlMatches
    }

    $nodeHealthy = $nodeState.Known -and $nodeState.Status -eq 'running' -and $nodeState.Health -eq 'healthy'
    $databaseHealthy = $databaseState.Known -and $databaseState.Status -eq 'running' -and $databaseState.Health -eq 'healthy'
    $migrationsComplete = -not [string]::IsNullOrWhiteSpace($context.MigrationContainerId) -and $migrationState.Known -and $migrationState.Status -eq 'exited' -and $migrationState.ExitCode -eq 0
    $schedulerRunning = $schedulerState.Known -and $schedulerState.Status -eq 'running'
    $tcpProbe = if ($targetAddress -eq $currentAddress -and $nodeHealthy) { Get-IngressWindowsNetworkProbe -Address $targetAddress -Port $httpsPort } else { $false }

    $operatorPortStatus = if ($nodeState.Status -eq 'running' -and $actualPorts.Known) {
        if ($actualPorts.OperatorIsolated) { "127.0.0.1 only ($($actualPorts.OperatorBinding))" } else { "exposed outside loopback ($($actualPorts.OperatorBinding))" }
    }
    elseif ($composeSummary.OperatorPortIsolated) { 'configured for 127.0.0.1 only; runtime binding unavailable' }
    else { 'not verified as loopback-only' }
    $certificateStatus = if (-not $certificate.Known) { 'unknown' } elseif (-not $appUrlMatches) { 'certificate or DNS does not match the selected Node URL/address' } else { 'valid for the configured Node URL/address' }
    $firewallStatus = if (-not $firewallKnown) { 'firewall policy or active profile could not be read' } elseif (-not $firewallProfileState.Enabled) { "the active $($candidate.NetworkCategory) firewall profile is disabled" } elseif ($firewallResult.Allowed) { "scoped rule '$($firewallResult.RuleName)' allows TCP/$httpsPort" } else { "no scoped TCP/$httpsPort rule for $targetAddress and $($firewallResult.RequiredRemoteScope)" }
    $admissionStatus = if (-not $admissionState.Known) { 'unknown; restart blocked' } elseif ($admissionState.Active) { 'admissions can run; restart blocked' } else { 'no admission-enabled event window' }
    $lanDemoStatus = if (-not $lanDemoHostValue) { 'host is unavailable locally; EAS build-time setting needs manual check' } elseif ($lanDemoHostMatches) { "host '$lanDemoHostValue' matches" } else { "host '$lanDemoHostValue' does not match the selected Node URL" }

    if (-not $appUrlMatches -or -not $firewallResult.Allowed -or -not $composeSummary.OperatorPortIsolated -or -not $actualPorts.Known -or -not $actualPorts.NodeMatches -or -not $actualPorts.OperatorIsolated -or -not $tcpProbe -or -not $nodeHealthy) {
        $scannerReadiness = 'Not ready'
    }
    elseif (-not $lanDemoHostMatches) {
        $scannerReadiness = 'Not verified: Android lanDemo host and CA must be checked; rebuild may be required'
    }
    else {
        $scannerReadiness = 'Network path ready; installed APK CA trust remains unverified'
    }

    $warnings = @()
    $detectionNote = [string] (Get-IngressPropertyValue $candidate 'DetectionNote' '')
    if ($detectionNote) { $warnings += $detectionNote }
    if (-not $lanDemoHostMatches) { $warnings += 'The manager does not change EAS variables, Android trust anchors, DNS, or certificates.' }
    if (-not $tcpProbe) { $warnings += 'The selected HTTPS port did not pass a local TCP probe; the phone still needs its own acceptance test.' }

    $adapterStatuses = @(
        foreach ($adapter in $Adapters) {
            foreach ($address in @($adapter.IPv4Addresses)) {
                [pscustomobject] @{
                    Name = [string] $adapter.Name
                    Description = [string] $adapter.InterfaceDescription
                    Mode = [string] $adapter.CandidateMode
                    Address = [string] $address.Address
                    PrefixLength = [int] $address.PrefixLength
                    NetworkCategory = [string] $adapter.NetworkCategory
                    IsUp = ([string] $adapter.Status -eq 'Up')
                }
            }
        }
    )
    $facts = [pscustomobject] @{
        DockerReady = $databaseHealthy -and $composeSummary.Valid
        ComposeReady = $context.ComposeVersion -match '^v?[0-9]+\.'
        DatabaseHealthy = $databaseHealthy
        MigrationsComplete = [bool] $migrationsComplete
        SessionStateKnown = [bool] $admissionState.Known
        ActiveAdmissionSession = [bool] $admissionState.Active
        CertificateMatch = [bool] $appUrlMatches
        ProfileMatch = [bool] $targetProfileMatches
        FirewallStateKnown = [bool] $firewallKnown
        FirewallAllowed = [bool] $firewallResult.Allowed
        ComposeValid = [bool] $composeSummary.Valid -and [bool] $composeSummary.BindMatches
        OperatorPortIsolated = [bool] ($composeSummary.OperatorPortIsolated -and ($nodeState.Status -ne 'running' -or ($actualPorts.Known -and $actualPorts.OperatorIsolated)))
        NodeStateKnown = [bool] $nodeState.Known
        NodeStateSafe = [bool] (Test-IngressNodeStateSafe -State $nodeState)
        LanDemoHostMatch = [bool] $lanDemoHostMatches
        LanDemoTrustVerified = $false
    }
    $gate = Test-IngressApplyGate -Facts $facts
    $safeStopFacts = [pscustomobject] @{
        NodeStateKnown = [bool] $nodeState.Known
        NodeHealthy = [bool] $nodeHealthy
        DatabaseHealthy = [bool] $databaseHealthy
        AdmissionStateKnown = [bool] $admissionState.Known
        AdmissionsPossible = [bool] $admissionState.Active
        PendingSyncStateKnown = [bool] $operations.Known
        PendingSyncRecords = $operations.PendingSyncRecords
        AuthorityStateKnown = [bool] $operations.Known
        AuthorityUncertain = [bool] $operations.AuthorityUncertain
        OperatorPortIsolated = [bool] ($composeSummary.OperatorPortIsolated -and ($nodeState.Status -ne 'running' -or ($actualPorts.Known -and $actualPorts.OperatorIsolated)))
    }
    $safeStopGate = Test-IngressSafeStopGate -Facts $safeStopFacts
    $statusObject = [pscustomobject] @{
        SchemaVersion = 1
        NetworkMode = $Mode
        LanAddress = if ($candidate) { "$($candidate.Address)/$($candidate.PrefixLength)" } else { $currentAddress }
        NodeUrl = Get-IngressSafeDisplayUrl -Url $appUrl
        LanMapping = if ($actualPorts.Known) { "https://$($actualPorts.NodeBinding)" } else { 'unknown' }
        DockerStatus = if ($databaseHealthy -and $composeSummary.Valid) { "WSL2/Docker Desktop ready ($($context.DockerVersion), Compose $($context.ComposeVersion))" } else { 'not ready' }
        NodeStatus = if ($nodeState.Known) { "$($nodeState.Status) ($($nodeState.Health))" } else { 'unknown' }
        DatabaseStatus = if ($databaseState.Known) { "$($databaseState.Status) ($($databaseState.Health))" } else { 'unknown' }
        MigrationStatus = if ($migrationState.Known) { "$($migrationState.Status) (exit $($migrationState.ExitCode))" } else { 'unknown' }
        SchedulerStatus = if ($schedulerState.Known) { "$($schedulerState.Status) ($($schedulerState.Health))" } else { 'unknown' }
        SchedulerDetail = 'Container state is visible; no scheduler heartbeat is exposed by the current Event Node.'
        OperatorPortStatus = $operatorPortStatus
        OperatorPort = $operatorPort
        CertificateStatus = $certificateStatus
        FirewallStatus = $firewallStatus
        AdmissionStatus = $admissionStatus
        LanDemoStatus = $lanDemoStatus
        LanProbeStatus = if ($tcpProbe) { 'reachable from this PC' } else { 'not reachable from this PC' }
        ScannerReadiness = $scannerReadiness
        DockerVersion = $context.DockerVersion
        WslDistribution = $context.Distro
        CertificateExpiresAtUtc = if ($certificate.ExpiresAtUtc) { $certificate.ExpiresAtUtc.ToString('o') } else { $null }
        CertificateDaysRemaining = $certificate.DaysRemaining
        AdmissionCapableEventCount = $admissionState.Count
        PendingSyncRecords = $operations.PendingSyncRecords
        DockerStateKnown = $true
        DockerAvailable = $true
        WslStateKnown = $true
        WslAvailable = $true
        NodeStateKnown = [bool] $nodeState.Known
        NodeHealthy = [bool] $nodeHealthy
        DatabaseStateKnown = [bool] $databaseState.Known
        DatabaseHealthy = [bool] $databaseHealthy
        MigrationStateKnown = [bool] $migrationState.Known
        MigrationsComplete = [bool] $migrationsComplete
        SchedulerStateKnown = (-not [string]::IsNullOrWhiteSpace($context.SchedulerContainerId) -and [bool] $schedulerState.Known)
        SchedulerProcessRunning = [bool] $schedulerRunning
        SchedulerHeartbeatVerified = $false
        OperatorPortStateKnown = [bool] ($actualPorts.Known -or ($nodeState.Status -ne 'running' -and $composeSummary.Valid))
        OperatorPortIsolated = [bool] ($composeSummary.OperatorPortIsolated -and ($nodeState.Status -ne 'running' -or ($actualPorts.Known -and $actualPorts.OperatorIsolated)))
        CertificateKnown = [bool] $certificate.Known
        CertificateValid = [bool] $certificate.Valid
        CertificateHostMatches = [bool] $certificateHostMatches
        FirewallStateKnown = [bool] $firewallKnown
        FirewallAllowed = [bool] $firewallResult.Allowed
        AdmissionStateKnown = [bool] $admissionState.Known
        AdmissionsPossible = [bool] $admissionState.Active
        PendingSyncStateKnown = [bool] $operations.Known
        AuthorityStateKnown = [bool] $operations.Known
        AuthorityUncertain = [bool] $operations.AuthorityUncertain
        LanProbeReachable = [bool] $tcpProbe
        LanDemoHostMatches = [bool] $lanDemoHostMatches
        LanDemoTrustVerified = $false
        CanApplyNetworkChange = [bool] $gate.CanApply
        CanSafelyStop = [bool] $safeStopGate.CanStop
        Blockers = @($gate.Blockers)
        SafeStopBlockers = @($safeStopGate.Blockers)
        Warnings = @($warnings + $gate.Warnings)
        Adapters = $adapterStatuses
        ProfileDiscoveryKnown = [bool] $savedProfiles.Known
        SavedProfiles = @($savedProfiles.Profiles)
        TargetProfileName = if ($targetProfileMatches) { [string] $targetProfile.Name } else { 'None' }
        TargetProfileMatches = [bool] $targetProfileMatches
        TargetAppUrl = if ($targetProfileMatches) { Get-IngressSafeDisplayUrl -Url $targetAppUrl } else { 'Unknown' }
        CurrentBindAddress = $currentAddress
        TargetBindAddress = $targetAddress
    }

    if ($Status -or $SafeStop) {
        if (-not $Json -and $Status) { Write-IngressStatus -Status $statusObject }
    }

    if ($SafeStop) {
        if (-not $safeStopGate.CanStop) {
            if (-not $Json) { foreach ($blocker in $safeStopGate.Blockers) { Write-Host "Blocked: $blocker" }; Write-Host 'The Node remains running.' }
            return $statusObject
        }

        if (-not $Apply) {
            if (-not $Json) { Write-Host 'Safe-stop preview passed. No Docker state was changed.' }
            return $statusObject
        }

        if (-not $ConfirmStop -and (Read-Host 'Type STOP to stop only ingress-node after confirming closeout') -cne 'STOP') {
            if (-not $Json) { Write-Host 'Cancelled. The Node remains running.' }
            return $statusObject
        }

        $latestAdmissionState = Read-IngressAdmissionState -Context $context -ComposeSummary $composeSummary -CommandRunner $CommandRunner
        $latestNodeState = Get-IngressContainerState -Context $context -ContainerId $context.NodeContainerId -CommandRunner $CommandRunner
        $latestDatabaseState = Get-IngressContainerState -Context $context -ContainerId $context.DatabaseContainerId -CommandRunner $CommandRunner
        $latestPorts = Get-IngressActualPortStatus -Context $context -ContainerId $context.NodeContainerId -CommandRunner $CommandRunner -Address $currentAddress -HttpsPort $httpsPort -OperatorPort $operatorPort
        $latestOperations = Read-IngressNodeOperationsSummary -Context $context -ComposeSummary $composeSummary -NodeState $latestNodeState -CommandRunner $CommandRunner
        $latestStopFacts = [pscustomobject] @{
            NodeStateKnown = [bool] $latestNodeState.Known
            NodeHealthy = [bool] ($latestNodeState.Status -eq 'running' -and $latestNodeState.Health -eq 'healthy')
            DatabaseHealthy = [bool] ($latestDatabaseState.Known -and $latestDatabaseState.Status -eq 'running' -and $latestDatabaseState.Health -eq 'healthy')
            AdmissionStateKnown = [bool] $latestAdmissionState.Known
            AdmissionsPossible = [bool] $latestAdmissionState.Active
            PendingSyncStateKnown = [bool] $latestOperations.Known
            PendingSyncRecords = $latestOperations.PendingSyncRecords
            AuthorityStateKnown = [bool] $latestOperations.Known
            AuthorityUncertain = [bool] $latestOperations.AuthorityUncertain
            OperatorPortIsolated = [bool] ($latestPorts.Known -and $latestPorts.OperatorIsolated -and $composeSummary.OperatorPortIsolated)
        }
        $latestStopGate = Test-IngressSafeStopGate -Facts $latestStopFacts
        if (-not $latestStopGate.CanStop) {
            if (-not $Json) { foreach ($blocker in $latestStopGate.Blockers) { Write-Host "Blocked: $blocker" }; Write-Host 'The Node remains running.' }
            $statusObject.CanSafelyStop = $false
            $statusObject.SafeStopBlockers = @($latestStopGate.Blockers)
            return $statusObject
        }

        $stopArguments = New-IngressNodeStopArguments -Project $context.Project -WorkingDirectory $context.WorkingDirectory -EnvironmentFile $context.EnvironmentFile -ComposeFiles $context.ComposeFiles
        $stopResult = Invoke-IngressWslCommand -Distro $context.Distro -Arguments (@('docker', 'compose') + $stopArguments) -CommandRunner $CommandRunner -SuppressStderr
        if ($stopResult.ExitCode -ne 0) { throw 'Compose could not safely stop ingress-node. The database service and volume were not changed.' }
        $stopped = $false
        for ($attempt = 0; $attempt -lt 15; $attempt++) {
            Start-Sleep -Seconds 2
            $stoppedState = Get-IngressContainerState -Context $context -ContainerId $context.NodeContainerId -CommandRunner $CommandRunner
            if ($stoppedState.Known -and $stoppedState.Status -eq 'exited') { $stopped = $true; break }
        }
        if (-not $stopped) { throw 'The Node did not confirm a clean stopped state within 30 seconds. The database service and volume were not changed.' }
        $statusObject.NodeStatus = 'exited (stopped after verified closeout)'
        $statusObject.NodeHealthy = $false
        $statusObject.CanSafelyStop = $false
        $statusObject.SafeStopBlockers = @('The Event Node is stopped; refresh status before another operation.')
        if (-not $Json) { Write-Host 'Only ingress-node was stopped. PostgreSQL, scheduler, and the database volume were left intact.' }
        return $statusObject
    }

    if ($Status) {
        return $statusObject
    }

    $bindChanged = $currentAddress -ne $targetAddress -or $currentAppUrl -cne $targetAppUrl -or ($nodeState.Status -eq 'running' -and $actualPorts.Known -and -not $actualPorts.NodeMatches)
    Write-Host "Mode: $($candidate.Mode)"
    Write-Host "Bind address: $currentAddress -> $targetAddress"
    if ($targetProfileMatches) { Write-Host "Node URL: $(Get-IngressSafeDisplayUrl -Url $currentAppUrl) -> $(Get-IngressSafeDisplayUrl -Url $targetAppUrl)" }
    Write-Host 'Compose plan: validate the active files, then start/recreate ingress-node only with --no-deps.'
    foreach ($warning in $gate.Warnings) { Write-Host "Note: $warning" }
    if (-not $gate.CanApply) {
        foreach ($blocker in $gate.Blockers) { Write-Host "Blocked: $blocker" }
        Write-Host 'No environment or Docker changes were made.'
        return $statusObject
    }
    if (-not $Apply) {
        Write-Host 'Dry run complete. No environment or Docker changes were made.'
        Write-Host 'Rerun with -Apply to confirm and apply this change.'
        return $statusObject
    }

    if (-not (Test-IngressApplyConfirmation -ConfirmApply:$ConfirmApply)) {
        Write-Host 'Cancelled. No environment or Docker changes were made.'
        return $statusObject
    }

    $latestAdapters = @(Get-IngressNetworkSnapshot)
    $latestCandidate = Resolve-IngressNetworkAdapter -Adapters $latestAdapters -Mode $Mode
    if ($latestCandidate.InterfaceIndex -ne $candidate.InterfaceIndex -or $latestCandidate.Address -ne $targetAddress -or $latestCandidate.NetworkCategory -ne $candidate.NetworkCategory) {
        throw 'The selected hotspot adapter or address changed during confirmation. No environment or Docker changes were made.'
    }
    $latestSettings = Read-IngressNodeEnvironment -Context $context -CommandRunner $CommandRunner
    if ([string] $latestSettings.INGRESS_NODE_BIND_ADDRESS -cne $currentAddress -or [string] $latestSettings.APP_URL -cne $currentAppUrl) {
        throw 'The saved Node network settings changed during confirmation. Refresh status and preview again; no change was made.'
    }
    $latestProfiles = Get-IngressSavedNetworkProfiles -Distro $context.Distro -CommandRunner $CommandRunner
    $latestProfileMatches = @($latestProfiles.Profiles | Where-Object {
        [bool] $_.IsConfigured -and [string] $_.Address -eq $targetAddress -and [string] $_.Mode -eq [string] $latestCandidate.Mode
    })
    if (-not $latestProfiles.Known -or $latestProfileMatches.Count -ne 1 -or [string] $latestProfileMatches[0].AppUrl -notin @($targetAppUrl, "$targetAppUrl/api/v1")) {
        throw 'The saved network profile changed or became unavailable during confirmation. No environment or Docker changes were made.'
    }
    $latestAdmissionState = Read-IngressAdmissionState -Context $context -ComposeSummary $composeSummary -CommandRunner $CommandRunner
    if (-not $latestAdmissionState.Known) { throw 'Admission state could not be confirmed immediately before the change. No environment or Docker changes were made.' }
    if ($latestAdmissionState.Active) { throw 'An event can now accept admissions. The Node was left running and no changes were made.' }
    $latestNodeState = Get-IngressContainerState -Context $context -ContainerId $context.NodeContainerId -CommandRunner $CommandRunner
    if (-not (Test-IngressNodeStateSafe -State $latestNodeState)) {
        throw 'The Event Node state changed or is unhealthy. No environment or Docker changes were made.'
    }

    $writeBinding = {
        param($address, $appUrl)
        $message = Write-IngressNodeBindAddress -Context $context -Address $address -AppUrl $appUrl -CommandRunner $CommandRunner
        Write-Host $message
    }.GetNewClosure()
    $applyNode = {
        param($forceRecreate)
        $upArguments = New-IngressComposeUpArguments -Project $context.Project -WorkingDirectory $context.WorkingDirectory -EnvironmentFile $context.EnvironmentFile -ComposeFiles $context.ComposeFiles -ForceRecreate:$forceRecreate
        $result = Invoke-IngressWslCommand -Distro $context.Distro -Arguments (@('docker', 'compose') + $upArguments) -CommandRunner $CommandRunner -SuppressStderr
        if ($result.ExitCode -ne 0) { throw 'Compose could not start ingress-node.' }

        $healthy = $false
        $nodeIds = @()
        for ($attempt = 0; $attempt -lt 30; $attempt++) {
            Start-Sleep -Seconds 2
            $nodeIds = @(Get-IngressComposeContainerIds -Distro $context.Distro -Project $context.Project -Service 'ingress-node' -CommandRunner $CommandRunner)
            if ($nodeIds.Count -eq 1) {
                $postState = Get-IngressContainerState -Context $context -ContainerId $nodeIds[0] -CommandRunner $CommandRunner
                if ($postState.Status -eq 'running' -and $postState.Health -eq 'healthy') { $healthy = $true; break }
            }
        }
        if (-not $healthy) { throw 'ingress-node did not become healthy within 60 seconds.' }

        $postSettings = Read-IngressNodeEnvironment -Context $context -CommandRunner $CommandRunner
        $postSummary = Get-IngressComposeSummary -Context $context -BindAddress $targetAddress -CommandRunner $CommandRunner
        $postPorts = Get-IngressActualPortStatus -Context $context -ContainerId $nodeIds[0] -CommandRunner $CommandRunner -Address $targetAddress -HttpsPort $httpsPort -OperatorPort $operatorPort
        if (-not $postSummary.Valid -or -not $postSummary.BindMatches -or [string] $postSettings.APP_URL -cne $targetAppUrl -or [string] $postSettings.INGRESS_NODE_BIND_ADDRESS -cne $targetAddress -or -not $postSummary.OperatorPortIsolated -or -not $postPorts.Known -or -not $postPorts.NodeMatches -or -not $postPorts.OperatorIsolated) {
            throw 'Post-start Compose settings or port verification failed.'
        }
        if (-not (Get-IngressWindowsNetworkProbe -Address $targetAddress -Port $httpsPort)) {
            throw 'The selected HTTPS port did not pass a local TCP probe.'
        }
    }.GetNewClosure()

    $outcome = Invoke-IngressPlannedChange -Gate $gate -BindChanged $bindChanged -NodeRunning ($nodeState.Status -eq 'running' -and $nodeHealthy) -TargetAddress $targetAddress -TargetAppUrl $targetAppUrl -PreviousAddress $currentAddress -PreviousAppUrl $currentAppUrl -WriteBinding $writeBinding -ApplyNode $applyNode
    Write-Host "Result: $($outcome.Outcome)"
    if ($outcome.Changed) {
        Write-Host 'ingress-node is healthy and the operator port remains loopback-only. Android device verification is still required.'
    }
    return $statusObject
}
Export-ModuleMember -Function Get-IngressNetworkSnapshot, Get-IngressAdapterMode, Resolve-IngressNetworkAdapter, Get-IngressIPv4Range, Test-IngressFirewallPolicy, Update-IngressBindContent, Test-IngressApplyGate, Test-IngressLanDemoApplyGate, Test-IngressSafeStopGate, New-IngressComposeUpArguments, New-IngressNodeStopArguments, Protect-IngressSensitiveText, Invoke-IngressPlannedChange, Test-IngressPrivateIPv4, Test-IngressCertificateHost, Invoke-IngressNetworkManager
