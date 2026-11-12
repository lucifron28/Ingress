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
        [Parameter(Mandatory = $true)][ValidateSet('Laptop Hotspot', 'Phone Hotspot', 'Auto Detect')][string] $Mode
    )

    $candidates = @()
    foreach ($adapter in $Adapters) {
        $candidateMode = [string] (Get-IngressPropertyValue -InputObject $adapter -Name 'CandidateMode' -Default '')
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
        throw "No active private IPv4 adapter matches '$Mode'. Start that hotspot or select the connected hotspot mode."
    }
    if ($candidates.Count -gt 1) {
        $names = ($candidates | ForEach-Object { "$($_.Name) [$($_.Address)]" }) -join ', '
        throw "Adapter selection for '$Mode' is ambiguous: $names. Choose a mode with one active address."
    }

    $selected = $candidates[0]
    if ($Mode -eq 'Auto Detect' -and $selected.Mode -eq 'Phone Hotspot') {
        $selected | Add-Member -NotePropertyName DetectionNote -NotePropertyValue 'Only one physical Wi-Fi candidate is active; confirm it is connected to the Android hotspot.'
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
        if ([string] (Get-IngressPropertyValue -InputObject $rule -Name 'Name' -Default '') -ne 'Ingress Event Node HTTPS' -or
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

function New-IngressComposeUpArguments {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string] $Project,
        [Parameter(Mandatory = $true)][string] $WorkingDirectory,
        [Parameter(Mandatory = $true)][string] $EnvironmentFile,
        [Parameter(Mandatory = $true)][string[]] $ComposeFiles,
        [Parameter(Mandatory = $true)][bool] $ForceRecreate
    )

    $arguments = New-Object 'System.Collections.Generic.List[string]'
    foreach ($argument in @('--project-name', $Project, '--project-directory', $WorkingDirectory, '--env-file', $EnvironmentFile)) {
        $arguments.Add($argument)
    }
    foreach ($file in $ComposeFiles) {
        $arguments.Add('-f')
        $arguments.Add($file)
    }
    foreach ($argument in @('up', '-d', '--no-deps')) {
        $arguments.Add($argument)
    }
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
        & $WriteBinding $TargetAddress
    }
    & $ApplyNode $BindChanged

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
        foreach ($service in @('ingress-node', 'ingress-db')) {
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
        if ($nodeIds.Count -gt 1 -or $dbIds.Count -ne 1 -or $migrateIds.Count -gt 1) { throw 'The Ingress Compose project has ambiguous service containers.' }

        $contexts.Add([pscustomobject] @{
            Distro = $distro; Project = $project; WorkingDirectory = $workingDirectory; EnvironmentFile = $environmentFile
            ComposeFiles = $composeFiles
            NodeContainerId = if ($nodeIds.Count -eq 1) { $nodeIds[0] } else { '' }
            DatabaseContainerId = $dbIds[0]
            MigrationContainerId = if ($migrateIds.Count -eq 1) { $migrateIds[0] } else { '' }
            DockerVersion = $dockerInfo.Output.Trim(); ComposeVersion = $composeVersion.Output.Trim(); Kernel = $kernel.Output.Trim()
        })
    }
    if ($contexts.Count -eq 0) { throw 'No WSL2 Docker Compose project for the Ingress Event Node was found. Keep its Compose containers available so the project can be identified safely.' }
    if ($contexts.Count -gt 1) { throw 'More than one WSL2 Ingress Docker project was found. The manager will not guess which database or env file to use.' }
    return $contexts[0]
}
function Read-IngressNodeEnvironment {
    param([object] $Context, [scriptblock] $CommandRunner)
    $pattern = '^(INGRESS_NODE_BIND_ADDRESS|INGRESS_NODE_HTTPS_PORT|INGRESS_OPERATOR_PORT)='
    $result = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @('grep', '-E', $pattern, $Context.EnvironmentFile) -CommandRunner $CommandRunner -SuppressStderr
    if ($result.ExitCode -ne 0) { throw 'The allowed Node network settings could not be read from node-compose.env.' }

    $settings = @{}
    foreach ($line in @($result.Output -split '\r?\n' | Where-Object { $_ })) {
        $parts = $line.Split('=', 2)
        if ($parts.Count -ne 2 -or $settings.ContainsKey($parts[0])) { throw 'node-compose.env contains duplicate or malformed network settings.' }
        $settings[$parts[0]] = $parts[1].Trim().Trim('"')
    }
    if (-not $settings.ContainsKey('INGRESS_NODE_BIND_ADDRESS')) { throw 'node-compose.env is missing INGRESS_NODE_BIND_ADDRESS.' }
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
    param([object] $Context, [string] $ContainerId, [scriptblock] $CommandRunner, [string] $Address, [int] $HttpsPort, [int] $OperatorPort)
    if ([string]::IsNullOrWhiteSpace($ContainerId)) {
        return [pscustomobject] @{ Known = $false; NodeBinding = ''; OperatorBinding = ''; NodeMatches = $false; OperatorIsolated = $false }
    }
    $node = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @('docker', 'port', $ContainerId, '8443/tcp') -CommandRunner $CommandRunner -SuppressStderr
    $operator = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @('docker', 'port', $ContainerId, '8444/tcp') -CommandRunner $CommandRunner -SuppressStderr
    if ($node.ExitCode -ne 0 -or $operator.ExitCode -ne 0) {
        return [pscustomobject] @{ Known = $false; NodeBinding = ''; OperatorBinding = ''; NodeMatches = $false; OperatorIsolated = $false }
    }
    $nodeBindings = @($node.Output -split '\r?\n' | ForEach-Object { $_.Trim() } | Where-Object { $_ })
    $operatorBindings = @($operator.Output -split '\r?\n' | ForEach-Object { $_.Trim() } | Where-Object { $_ })
    $operatorIsolated = $operatorBindings.Count -eq 1 -and $operatorBindings[0] -eq ('127.0.0.1:' + $OperatorPort)
    $nodeMatches = $nodeBindings.Count -eq 1 -and $nodeBindings[0] -eq ($Address + ':' + $HttpsPort)
    return [pscustomobject] @{
        Known = ($nodeBindings.Count -eq 1 -and $operatorBindings.Count -eq 1)
        NodeBinding = if ($nodeBindings.Count -eq 1) { $nodeBindings[0] } else { '' }
        OperatorBinding = if ($operatorBindings.Count -eq 1) { $operatorBindings[0] } else { '' }
        NodeMatches = $nodeMatches
        OperatorIsolated = $operatorIsolated
    }
}

function Get-IngressComposeSummary {
    param([object] $Context, [string] $BindAddress, [scriptblock] $CommandRunner)
    $arguments = @('env', "INGRESS_NODE_BIND_ADDRESS=$BindAddress", 'docker', 'compose', '--project-name', $Context.Project,
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
        $nodeMappings = @($nodePorts | Where-Object { [int] $_.target -eq 8443 -and [string] $_.protocol -eq 'tcp' })
        $operatorMappings = @($nodePorts | Where-Object { [int] $_.target -eq 8444 -and [string] $_.protocol -eq 'tcp' })
        $operatorPortIsolated = $operatorMappings.Count -eq 1 -and [string] $operatorMappings[0].host_ip -eq '127.0.0.1' -and [int] $operatorMappings[0].published -gt 0
        $bindMatches = $nodeMappings.Count -eq 1 -and [string] $nodeMappings[0].host_ip -eq $BindAddress -and [int] $nodeMappings[0].published -gt 0
        return [pscustomobject] @{
            Valid = ($null -ne $nodeService -and $null -ne $databaseService)
            OperatorPortIsolated = $operatorPortIsolated
            BindMatches = $bindMatches
            CertificatePath = [string] $configuration.secrets.node_tls_certificate.file
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
    $rules = @(Get-NetFirewallRule -DisplayName 'Ingress Event Node HTTPS' -ErrorAction Stop | Where-Object { [string] $_.Direction -eq 'Inbound' })
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
        return [pscustomobject] @{ Known = $false; Valid = $false; Names = '' }
    }
    $san = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @('openssl', 'x509', '-in', $CertificatePath, '-noout', '-ext', 'subjectAltName') -CommandRunner $CommandRunner -SuppressStderr
    $validity = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @('openssl', 'x509', '-in', $CertificatePath, '-checkend', '0', '-noout') -CommandRunner $CommandRunner -SuppressStderr
    if ($san.ExitCode -ne 0 -or $validity.ExitCode -ne 0 -or [string]::IsNullOrWhiteSpace($san.Output)) {
        return [pscustomobject] @{ Known = $false; Valid = $false; Names = '' }
    }
    return [pscustomobject] @{ Known = $true; Valid = $true; Names = $san.Output }
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
    foreach ($mode in @('Laptop Hotspot', 'Phone Hotspot')) {
        try {
            $candidate = Resolve-IngressNetworkAdapter -Adapters $Adapters -Mode $mode
            if ($candidate.Address -eq $Address) { return $candidate }
        }
        catch { continue }
    }
    return $null
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

function Write-IngressNodeBindAddress {
    param([object] $Context, [string] $Address, [scriptblock] $CommandRunner)
    $python = @'
import os
import stat
import sys
import tempfile

path = sys.argv[1]
address = sys.argv[2].encode("ascii")
source_descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
original_stat = os.fstat(source_descriptor)
if not stat.S_ISREG(original_stat.st_mode):
    os.close(source_descriptor)
    raise SystemExit("node-compose.env must be a regular file")
with os.fdopen(source_descriptor, "rb") as source:
    original = source.read()
lines = original.splitlines(keepends=True)
matching = [index for index, line in enumerate(lines) if line.rstrip(b"\r\n").startswith(b"INGRESS_NODE_BIND_ADDRESS=")]
if len(matching) != 1:
    raise SystemExit("expected exactly one bind assignment")
index = matching[0]
line = lines[index]
body = line.rstrip(b"\r\n")
ending = line[len(body):]
updated = b"INGRESS_NODE_BIND_ADDRESS=" + address + ending
if updated == line:
    print("unchanged")
    raise SystemExit(0)
descriptor, temporary = tempfile.mkstemp(prefix=".node-compose.env.", dir=os.path.dirname(path))
try:
    os.fchown(descriptor, original_stat.st_uid, original_stat.st_gid)
    os.fchmod(descriptor, stat.S_IMODE(original_stat.st_mode))
    with os.fdopen(descriptor, "wb") as destination:
        destination.writelines(lines[:index] + [updated] + lines[index + 1:])
        destination.flush()
        os.fsync(destination.fileno())
    os.replace(temporary, path)
    directory = os.open(os.path.dirname(path), os.O_DIRECTORY)
    os.fsync(directory)
    os.close(directory)
    print("updated only INGRESS_NODE_BIND_ADDRESS")
except:
    try:
        os.unlink(temporary)
    except OSError:
        pass
    raise
'@
    $result = Invoke-IngressWslCommand -Distro $Context.Distro -Arguments @('python3', '-c', $python, $Context.EnvironmentFile, $Address) -CommandRunner $CommandRunner -SuppressStderr
    if ($result.ExitCode -ne 0) { throw 'The bind address could not be updated safely; the Node was not restarted.' }
    return $result.Output.Trim()
}

function Get-IngressModeFromSavedAddress {
    param([object[]] $Adapters, [string] $Address)
    $candidate = Get-IngressAdapterForAddress -Adapters $Adapters -Address $Address
    if ($candidate) { return $candidate }
    return $null
}

function Invoke-IngressNetworkManager {
    [CmdletBinding()]
    param(
        [ValidateSet('Laptop Hotspot', 'Phone Hotspot', 'Auto Detect')][string] $Mode,
        [switch] $Status,
        [switch] $DryRun,
        [switch] $Apply,
        [string] $LanDemoHost,
        [scriptblock] $CommandRunner,
        [object[]] $Adapters,
        [object[]] $FirewallRules
    )

    if ($Status -and ($DryRun -or $Apply)) { throw '-Status cannot be combined with -DryRun or -Apply.' }
    if ($DryRun -and $Apply) { throw 'Choose either -DryRun or -Apply.' }
    if (-not $Status -and [string]::IsNullOrWhiteSpace($Mode)) {
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
    if (-not (Test-IngressPrivateIPv4 -Address $currentAddress)) { throw 'The current Node bind setting is missing or invalid; no changes were made.' }

    $candidate = $null
    if ($Status) {
        $candidate = Get-IngressModeFromSavedAddress -Adapters $Adapters -Address $currentAddress
        if ($candidate) { $Mode = $candidate.Mode } else { $Mode = 'Unmatched or unavailable' }
        $targetAddress = $currentAddress
    }
    else {
        $candidate = Resolve-IngressNetworkAdapter -Adapters $Adapters -Mode $Mode
        $targetAddress = [string] $candidate.Address
    }

    $firewallProfileState = if ($candidate) { Get-IngressFirewallProfileState -NetworkCategory $candidate.NetworkCategory } else { [pscustomobject] @{ Known = $false; Enabled = $false } }
    if (-not $firewallProfileState.Known) { $firewallKnown = $false }

    $composeSummary = Get-IngressComposeSummary -Context $context -BindAddress $targetAddress -CommandRunner $CommandRunner
    $nodeState = Get-IngressContainerState -Context $context -ContainerId $context.NodeContainerId -CommandRunner $CommandRunner
    $databaseState = Get-IngressContainerState -Context $context -ContainerId $context.DatabaseContainerId -CommandRunner $CommandRunner
    $migrationState = Get-IngressContainerState -Context $context -ContainerId $context.MigrationContainerId -CommandRunner $CommandRunner
    $admissionState = Read-IngressAdmissionState -Context $context -ComposeSummary $composeSummary -CommandRunner $CommandRunner
    $certificate = Read-IngressCertificateNames -Context $context -CertificatePath $composeSummary.CertificatePath -CommandRunner $CommandRunner

    $httpsPortText = [string] (Get-IngressPropertyValue $settings 'INGRESS_NODE_HTTPS_PORT' '443')
    $httpsPort = 443
    if ($httpsPortText -match '\A[0-9]{1,5}\z' -and [int] $httpsPortText -ge 1 -and [int] $httpsPortText -le 65535) { $httpsPort = [int] $httpsPortText }
    $operatorPortText = [string] (Get-IngressPropertyValue $settings 'INGRESS_OPERATOR_PORT' '8444')
    $operatorPort = 8444
    if ($operatorPortText -match '\A[0-9]{1,5}\z' -and [int] $operatorPortText -ge 1 -and [int] $operatorPortText -le 65535) { $operatorPort = [int] $operatorPortText }
    $actualPorts = Get-IngressActualPortStatus -Context $context -ContainerId $context.NodeContainerId -CommandRunner $CommandRunner -Address $targetAddress -HttpsPort $httpsPort -OperatorPort $operatorPort
    $appUrl = [string] $composeSummary.AppUrl
    $appUrlMatches = $false
    $appUrlHost = ''
    $urlPortMatches = $false
    try {
        $appUri = [uri] $appUrl
        $appUrlHost = $appUri.DnsSafeHost.Trim('[', ']')
        $urlPortMatches = $appUri.Port -eq $httpsPort
        $appUrlMatches = $certificate.Known -and $certificate.Valid -and $urlPortMatches -and
            (Test-IngressCertificateHost -SubjectAlternativeNames $certificate.Names -HostName $appUrlHost) -and
            (Test-IngressUrlResolvesToAddress -Url $appUrl -Address $targetAddress)
    }
    catch { $appUrlMatches = $false }

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

    $statusObject = [pscustomobject] @{
        NetworkMode = $Mode
        LanAddress = if ($candidate) { "$($candidate.Address)/$($candidate.PrefixLength)" } else { $currentAddress }
        NodeUrl = $appUrl
        LanMapping = if ($actualPorts.Known) { "https://$($actualPorts.NodeBinding)" } else { 'unknown' }
        DockerStatus = if ($databaseHealthy -and $composeSummary.Valid) { "WSL2/Docker Desktop ready ($($context.DockerVersion), Compose $($context.ComposeVersion))" } else { 'not ready' }
        NodeStatus = if ($nodeState.Known) { "$($nodeState.Status) ($($nodeState.Health))" } else { 'unknown' }
        OperatorPortStatus = $operatorPortStatus
        CertificateStatus = $certificateStatus
        FirewallStatus = $firewallStatus
        AdmissionStatus = $admissionStatus
        LanDemoStatus = $lanDemoStatus
        LanProbeStatus = if ($tcpProbe) { 'reachable from this PC' } else { 'not reachable from this PC' }
        ScannerReadiness = $scannerReadiness
        Warnings = $warnings
        CurrentBindAddress = $currentAddress
        TargetBindAddress = $targetAddress
    }

    if ($Status) {
        Write-IngressStatus -Status $statusObject
        return $statusObject
    }

    $facts = [pscustomobject] @{
        DockerReady = $databaseHealthy -and $composeSummary.Valid
        ComposeReady = $context.ComposeVersion -match '^v?[0-9]+\.'
        DatabaseHealthy = $databaseHealthy
        MigrationsComplete = [bool] $migrationsComplete
        SessionStateKnown = [bool] $admissionState.Known
        ActiveAdmissionSession = [bool] $admissionState.Active
        CertificateMatch = [bool] $appUrlMatches
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
    $bindChanged = $currentAddress -ne $targetAddress -or ($nodeState.Status -eq 'running' -and $actualPorts.Known -and -not $actualPorts.NodeMatches)
    Write-Host "Mode: $($candidate.Mode)"
    Write-Host "Bind address: $currentAddress -> $targetAddress"
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

    if ((Read-Host 'Type APPLY to update the bind address and start/recreate only ingress-node') -cne 'APPLY') {
        Write-Host 'Cancelled. No environment or Docker changes were made.'
        return $statusObject
    }

    $latestAdapters = @(Get-IngressNetworkSnapshot)
    $latestCandidate = Resolve-IngressNetworkAdapter -Adapters $latestAdapters -Mode $Mode
    if ($latestCandidate.InterfaceIndex -ne $candidate.InterfaceIndex -or $latestCandidate.Address -ne $targetAddress -or $latestCandidate.NetworkCategory -ne $candidate.NetworkCategory) {
        throw 'The selected hotspot adapter or address changed during confirmation. No environment or Docker changes were made.'
    }
    $latestAdmissionState = Read-IngressAdmissionState -Context $context -ComposeSummary $composeSummary -CommandRunner $CommandRunner
    if (-not $latestAdmissionState.Known) { throw 'Admission state could not be confirmed immediately before the change. No environment or Docker changes were made.' }
    if ($latestAdmissionState.Active) { throw 'An event can now accept admissions. The Node was left running and no changes were made.' }
    $latestNodeState = Get-IngressContainerState -Context $context -ContainerId $context.NodeContainerId -CommandRunner $CommandRunner
    if (-not (Test-IngressNodeStateSafe -State $latestNodeState)) {
        throw 'The Event Node state changed or is unhealthy. No environment or Docker changes were made.'
    }

    $writeBinding = {
        param($address)
        $message = Write-IngressNodeBindAddress -Context $context -Address $address -CommandRunner $CommandRunner
        Write-Host $message
    }.GetNewClosure()
    $applyNode = {
        param($forceRecreate)
        $upArguments = New-IngressComposeUpArguments -Project $context.Project -WorkingDirectory $context.WorkingDirectory -EnvironmentFile $context.EnvironmentFile -ComposeFiles $context.ComposeFiles -ForceRecreate:$forceRecreate
        $result = Invoke-IngressWslCommand -Distro $context.Distro -Arguments (@('docker', 'compose') + $upArguments) -CommandRunner $CommandRunner -SuppressStderr
        if ($result.ExitCode -ne 0) { throw 'Compose could not start ingress-node. The bind setting remains saved; inspect the Node service before retrying.' }
    }.GetNewClosure()

    $outcome = Invoke-IngressPlannedChange -Gate $gate -BindChanged $bindChanged -NodeRunning ($nodeState.Status -eq 'running' -and $nodeHealthy) -TargetAddress $targetAddress -WriteBinding $writeBinding -ApplyNode $applyNode
    Write-Host "Result: $($outcome.Outcome)"
    if ($outcome.Changed) {
        $healthy = $false
        for ($attempt = 0; $attempt -lt 30; $attempt++) {
            Start-Sleep -Seconds 2
            $nodeIds = @(Get-IngressComposeContainerIds -Distro $context.Distro -Project $context.Project -Service 'ingress-node' -CommandRunner $CommandRunner)
            if ($nodeIds.Count -eq 1) {
                $postState = Get-IngressContainerState -Context $context -ContainerId $nodeIds[0] -CommandRunner $CommandRunner
                if ($postState.Status -eq 'running' -and $postState.Health -eq 'healthy') { $healthy = $true; break }
            }
        }
        if (-not $healthy) { throw 'ingress-node did not become healthy within 60 seconds. Database and volumes were not changed.' }
        $postSummary = Get-IngressComposeSummary -Context $context -BindAddress $targetAddress -CommandRunner $CommandRunner
        $postPorts = Get-IngressActualPortStatus -Context $context -ContainerId $nodeIds[0] -CommandRunner $CommandRunner -Address $targetAddress -HttpsPort $httpsPort -OperatorPort $operatorPort
        if (-not $postSummary.Valid -or -not $postSummary.BindMatches -or -not $postSummary.OperatorPortIsolated -or -not $postPorts.Known -or -not $postPorts.NodeMatches -or -not $postPorts.OperatorIsolated) {
            throw 'Post-start Compose port verification failed. The operator port or Node binding needs inspection.'
        }
        if (-not (Get-IngressWindowsNetworkProbe -Address $targetAddress -Port $httpsPort)) {
            throw 'The Node is healthy internally, but its selected LAN TCP port is not reachable from this PC.'
        }
        Write-Host 'ingress-node is healthy and the operator port remains loopback-only. Android device verification is still required.'
    }
    return $statusObject
}
Export-ModuleMember -Function Get-IngressNetworkSnapshot, Get-IngressAdapterMode, Resolve-IngressNetworkAdapter, Get-IngressIPv4Range, Test-IngressFirewallPolicy, Update-IngressBindContent, Test-IngressApplyGate, New-IngressComposeUpArguments, Invoke-IngressPlannedChange, Test-IngressPrivateIPv4, Test-IngressCertificateHost, Invoke-IngressNetworkManager
