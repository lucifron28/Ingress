$modulePath = Join-Path $PSScriptRoot '..\IngressNetworkManager.psm1'
Import-Module $modulePath -Force
$fixturePath = Join-Path $PSScriptRoot 'fixtures\network-manager.compose.yaml'

function New-TestAdapter {
    param([string] $Name, [string] $Mode, [string] $Address, [int] $PrefixLength = 24, [int] $InterfaceIndex = 10, [string] $NetworkCategory = 'Public')
    [pscustomobject] @{ Name = $Name; InterfaceDescription = $Name; CandidateMode = $Mode; Status = 'Up'; InterfaceIndex = $InterfaceIndex; NetworkCategory = $NetworkCategory; IPv4Addresses = @([pscustomobject] @{ Address = $Address; PrefixLength = $PrefixLength; AddressState = 'Preferred' }) }
}

function New-ReadyGateFacts {
    [pscustomobject] @{
        DockerReady = $true; ComposeReady = $true; DatabaseHealthy = $true; MigrationsComplete = $true
        SessionStateKnown = $true; ActiveAdmissionSession = $false; CertificateMatch = $true
        FirewallStateKnown = $true; FirewallAllowed = $true; ComposeValid = $true; OperatorPortIsolated = $true
        NodeStateKnown = $true; NodeStateSafe = $true; LanDemoHostMatch = $true; LanDemoTrustVerified = $false
    }
}

function Get-TestFreePort {
    $listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Loopback, 0)
    $listener.Start()
    $port = [int] $listener.LocalEndpoint.Port
    $listener.Stop()
    return $port
}

function Test-IngressThrows {
    param([Parameter(Mandatory = $true)][scriptblock] $ScriptBlock)
    try {
        $null = & $ScriptBlock
        return $false
    }
    catch {
        return $true
    }
}

Describe 'Ingress Network Manager safety' {
    It 'changes only the binding line and preserves other env content' {
        $nl = [Environment]::NewLine
        $content = 'APP_URL=https://node.example' + $nl + 'INGRESS_NODE_BIND_ADDRESS=10.1.2.3' + $nl + 'DB_PASSWORD=fake-only' + $nl
        $expected = 'APP_URL=https://node.example' + $nl + 'INGRESS_NODE_BIND_ADDRESS=192.168.1.12' + $nl + 'DB_PASSWORD=fake-only' + $nl
        (Update-IngressBindContent -Content $content -Address '192.168.1.12') | Should Be $expected
    }

    It 'rejects missing, duplicate, and non-private bindings' {
        (Test-IngressThrows { Update-IngressBindContent -Content 'APP_URL=https://node.example' -Address '192.168.1.12' }) | Should Be $true
        $nl = [Environment]::NewLine
        $duplicate = 'INGRESS_NODE_BIND_ADDRESS=10.0.0.2' + $nl + 'INGRESS_NODE_BIND_ADDRESS=10.0.0.3'
        (Test-IngressThrows { Update-IngressBindContent -Content $duplicate -Address '192.168.1.12' }) | Should Be $true
        (Test-IngressThrows { Update-IngressBindContent -Content 'INGRESS_NODE_BIND_ADDRESS=10.0.0.2' -Address '8.8.8.8' }) | Should Be $true
    }

    It 'distinguishes physical Wi-Fi, Wi-Fi Direct, and excluded VPN adapters' {
        $laptop = [pscustomobject] @{ Name = 'Wi-Fi Direct'; InterfaceDescription = 'Microsoft Wi-Fi Direct Virtual Adapter'; Virtual = $true; NdisPhysicalMedium = 9; MediaType = 'Native 802.11' }
        $phone = [pscustomobject] @{ Name = 'Wi-Fi'; InterfaceDescription = 'Wireless NIC'; Virtual = $false; NdisPhysicalMedium = 9; MediaType = 'Native 802.11' }
        $vpn = [pscustomobject] @{ Name = 'Radmin VPN'; InterfaceDescription = 'Famatech Radmin VPN Ethernet Adapter'; Virtual = $true; NdisPhysicalMedium = 14; MediaType = '802.3' }
        (Get-IngressAdapterMode $laptop) | Should Be 'Laptop Hotspot'
        (Get-IngressAdapterMode $phone) | Should Be 'Phone Hotspot'
        (Get-IngressAdapterMode $vpn) | Should Be $null
    }

    It 'selects a unique private address and refuses ambiguity and APIPA' {
        $phone = New-TestAdapter -Name 'Wi-Fi' -Mode 'Phone Hotspot' -Address '192.168.55.158'
        (Resolve-IngressNetworkAdapter -Adapters @($phone) -Mode 'Phone Hotspot').Address | Should Be '192.168.55.158'
        $second = New-TestAdapter -Name 'Wi-Fi 2' -Mode 'Phone Hotspot' -Address '192.168.1.50' -InterfaceIndex 11
        (Test-IngressThrows { Resolve-IngressNetworkAdapter -Adapters @($phone, $second) -Mode 'Auto Detect' }) | Should Be $true
        $apipa = New-TestAdapter -Name 'Wi-Fi' -Mode 'Phone Hotspot' -Address '169.254.10.5'
        (Test-IngressThrows { Resolve-IngressNetworkAdapter -Adapters @($apipa) -Mode 'Phone Hotspot' }) | Should Be $true
        $tentative = New-TestAdapter -Name 'Wi-Fi' -Mode 'Phone Hotspot' -Address '192.168.1.50'
        $tentative.IPv4Addresses[0].AddressState = 'Tentative'
        (Test-IngressThrows { Resolve-IngressNetworkAdapter -Adapters @($tentative) -Mode 'Phone Hotspot' }) | Should Be $true
    }

    It 'requires a same-subnet remote scope and the HTTPS port' {
        (Get-IngressIPv4Range '192.168.55.158/24').Cidr | Should Be '192.168.55.0/24'
        (Get-IngressIPv4Range '192.168.55.0/255.255.255.0').Cidr | Should Be '192.168.55.0/24'
        $candidate = Resolve-IngressNetworkAdapter -Adapters @((New-TestAdapter -Name 'Wi-Fi' -Mode 'Phone Hotspot' -Address '192.168.55.158')) -Mode 'Phone Hotspot'
        $rule = [pscustomobject] @{
            Name = 'Ingress Event Node HTTPS'; Enabled = $true; Direction = 'Inbound'; Action = 'Allow'
            Profiles = @('Any'); EdgeTraversalPolicy = 'Block'; Protocol = 'TCP'; LocalPorts = @('443')
            LocalAddresses = @('192.168.55.158'); RemoteAddresses = @('192.168.55.0/24')
            ApplicationPrograms = @('C:\Program Files\Docker\Docker\resources\com.docker.backend.exe')
            ApplicationPackages = @(''); Services = @('Any')
            InterfaceAliases = @('Wi-Fi'); InterfaceTypes = @('Any')
        }
        (Test-IngressFirewallPolicy -Rules @($rule) -Candidate $candidate -Port 443).Allowed | Should Be $true
        $broad = $rule.PSObject.Copy(); $broad.RemoteAddresses = @('Any')
        (Test-IngressFirewallPolicy -Rules @($broad) -Candidate $candidate -Port 443).Allowed | Should Be $false
        (Test-IngressFirewallPolicy -Rules @($rule) -Candidate $candidate -Port 8443).Allowed | Should Be $false
        (Test-IngressFirewallPolicy -Rules @($rule) -Candidate $candidate -Port 443 -FirewallProfileEnabled $false).Allowed | Should Be $false
        $wrongProgram = $rule.PSObject.Copy(); $wrongProgram.ApplicationPrograms = @('C:\Other\listener.exe')
        (Test-IngressFirewallPolicy -Rules @($wrongProgram) -Candidate $candidate -Port 443).Allowed | Should Be $false
        $wrongInterface = $rule.PSObject.Copy(); $wrongInterface.InterfaceAliases = @('Ethernet')
        (Test-IngressFirewallPolicy -Rules @($wrongInterface) -Candidate $candidate -Port 443).Allowed | Should Be $false
        $edgeTraversal = $rule.PSObject.Copy(); $edgeTraversal.EdgeTraversalPolicy = 'Allow'
        (Test-IngressFirewallPolicy -Rules @($edgeTraversal) -Candidate $candidate -Port 443).Allowed | Should Be $false
        $widePorts = $rule.PSObject.Copy(); $widePorts.LocalPorts = @('443', '8443')
        (Test-IngressFirewallPolicy -Rules @($widePorts) -Candidate $candidate -Port 443).Allowed | Should Be $false
        $wideAddresses = $rule.PSObject.Copy(); $wideAddresses.LocalAddresses = @('Any')
        (Test-IngressFirewallPolicy -Rules @($wideAddresses) -Candidate $candidate -Port 443).Allowed | Should Be $false
        $wideInterfaces = $rule.PSObject.Copy(); $wideInterfaces.InterfaceAliases = @('Any', 'Wi-Fi')
        (Test-IngressFirewallPolicy -Rules @($wideInterfaces) -Candidate $candidate -Port 443).Allowed | Should Be $false
    }

    It 'requires an exact certificate SAN host' {
        $sans = 'IP Address:10.212.56.36, IP Address:127.0.0.1, DNS:node.example.test'
        (Test-IngressCertificateHost $sans '10.212.56.36') | Should Be $true
        (Test-IngressCertificateHost $sans 'node.example.test') | Should Be $true
        (Test-IngressCertificateHost $sans '192.168.55.158') | Should Be $false
        (Test-IngressCertificateHost 'DNS:*.example.test' 'scanner.example.test') | Should Be $true
        (Test-IngressCertificateHost 'DNS:*.example.test' 'tablet.scanner.example.test') | Should Be $false
    }

    It 'blocks apply when admission state is active or unknown' {
        $facts = New-ReadyGateFacts
        (Test-IngressApplyGate $facts).CanApply | Should Be $true
        $facts.SessionStateKnown = $false
        (Test-IngressApplyGate $facts).CanApply | Should Be $false
        $facts.SessionStateKnown = $true; $facts.ActiveAdmissionSession = $true
        (Test-IngressApplyGate $facts).CanApply | Should Be $false
    }

    It 'blocks restart while the Event Node is unhealthy or in an unknown state' {
        $facts = New-ReadyGateFacts
        $facts.NodeStateSafe = $false
        (Test-IngressApplyGate $facts).CanApply | Should Be $false
        $facts.NodeStateSafe = $true; $facts.NodeStateKnown = $false
        (Test-IngressApplyGate $facts).CanApply | Should Be $false
    }

    It 'does not invoke write or Compose callbacks when blocked' {
        $calls = New-Object 'System.Collections.Generic.List[string]'
        $write = { param($address) $calls.Add("write:$address") }
        $apply = { param($force) $calls.Add("apply:$force") }
        $blocked = [pscustomobject] @{ CanApply = $false }
        $result = Invoke-IngressPlannedChange $blocked $true $true '192.168.55.158' $write $apply
        $result.Outcome | Should Be 'Blocked'
        $calls.Count | Should Be 0
    }

    It 'targets only ingress-node and disables dependency recreation' {
        $arguments = New-IngressComposeUpArguments 'ingress-node' '/home/test/api' '/home/test/api/node-compose.env' @('/home/test/api/compose.node.yaml') $true
        ($arguments -join ' ') | Should Match 'up -d --no-deps --force-recreate ingress-node$'
        ($arguments -join ' ') | Should Not Match 'down|-v|ingress-db|ingress-scheduler'
    }

    It 'updates only the WSL env binding and preserves file mode on a temporary fixture' {
        $distro = 'Ubuntu-26.04'
        $tempDirectory = (& wsl.exe -d $distro --exec mktemp -d 2>$null | Out-String).Trim()
        if ($LASTEXITCODE -ne 0 -or $tempDirectory -notmatch '^/tmp/tmp\.') { throw 'Could not create a safe temporary WSL fixture.' }
        $environmentFile = "$tempDirectory/node-compose.env"
        $original = 'APP_URL=https://fixture.example' + [Environment]::NewLine + 'INGRESS_NODE_BIND_ADDRESS=10.0.0.2' + [Environment]::NewLine + 'FIXTURE_VALUE=keep-me' + [Environment]::NewLine
        $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($original))
        $python = 'import base64,sys; open(sys.argv[1],"wb").write(base64.b64decode(sys.argv[2]))'
        try {
            & wsl.exe -d $distro --exec python3 -c $python $environmentFile $encoded 2>$null | Out-Null
            if ($LASTEXITCODE -ne 0) { throw 'Could not write the temporary env fixture.' }
            & wsl.exe -d $distro --exec chmod 600 $environmentFile 2>$null | Out-Null
            if ($LASTEXITCODE -ne 0) { throw 'Could not set the temporary env fixture mode.' }
            $context = [pscustomobject] @{ Distro = $distro; EnvironmentFile = $environmentFile }
            $module = Get-Module IngressNetworkManager
            $null = & $module { param($fixtureContext) Write-IngressNodeBindAddress -Context $fixtureContext -Address '192.168.1.24' } $context
            $actual = (& wsl.exe -d $distro --exec cat $environmentFile 2>$null | Out-String)
            if ($LASTEXITCODE -ne 0) { throw 'Could not read the temporary env fixture after update.' }
            $expected = 'APP_URL=https://fixture.example' + [Environment]::NewLine + 'INGRESS_NODE_BIND_ADDRESS=192.168.1.24' + [Environment]::NewLine + 'FIXTURE_VALUE=keep-me' + [Environment]::NewLine
            $actual | Should Be $expected
            ((& wsl.exe -d $distro --exec stat -c '%a' $environmentFile 2>$null | Out-String).Trim()) | Should Be '600'
            $linkFile = "$tempDirectory/node-compose.env-link"
            & wsl.exe -d $distro --exec ln -s -- $environmentFile $linkFile 2>$null | Out-Null
            if ($LASTEXITCODE -ne 0) { throw 'Could not create the temporary symlink fixture.' }
            $linkContext = [pscustomobject] @{ Distro = $distro; EnvironmentFile = $linkFile }
            (Test-IngressThrows { $null = & $module { param($fixtureContext) Write-IngressNodeBindAddress -Context $fixtureContext -Address '192.168.1.25' } $linkContext }) | Should Be $true
            & wsl.exe -d $distro --exec test -L $linkFile 2>$null
            $LASTEXITCODE | Should Be 0
            (& wsl.exe -d $distro --exec cat $environmentFile 2>$null | Out-String) | Should Be $expected
        }
        finally {
            if ($tempDirectory -match '^/tmp/tmp\.[A-Za-z0-9]+$') { & wsl.exe -d $distro --exec rm -rf -- $tempDirectory 2>$null | Out-Null }
        }
    }

    It 'recreates only the isolated fixture Node and keeps the fixture database container' {
        $docker = Get-Command docker.exe -ErrorAction Stop
        $testRoot = Join-Path ([IO.Path]::GetTempPath()) ('ingress-network-manager-' + [guid]::NewGuid().ToString('N'))
        $project = 'ingress-network-manager-fixture-' + [guid]::NewGuid().ToString('N').Substring(0, 8)
        $operatorPort = Get-TestFreePort
        $httpsPort = Get-TestFreePort
        $environmentFile = Join-Path $testRoot 'node-compose.env'
        $savedBind = $env:INGRESS_NODE_BIND_ADDRESS
        $hadBind = Test-Path Env:INGRESS_NODE_BIND_ADDRESS
        $started = $false
        $nl = [Environment]::NewLine

        try {
            New-Item -ItemType Directory -Path $testRoot -Force | Out-Null
            $envText = 'INGRESS_NODE_BIND_ADDRESS=127.0.0.1' + $nl + "INGRESS_NODE_TEST_PORT=$httpsPort" + $nl + "INGRESS_OPERATOR_TEST_PORT=$operatorPort" + $nl
            [IO.File]::WriteAllText($environmentFile, $envText, [Text.UTF8Encoding]::new($false))
            if ($hadBind) { Remove-Item Env:INGRESS_NODE_BIND_ADDRESS -ErrorAction SilentlyContinue }

            $common = @('compose', '--project-name', $project, '--project-directory', $testRoot, '--env-file', $environmentFile, '-f', $fixturePath)
            $upOutput = & $docker.Source @($common + @('up', '-d')) 2>&1
            if ($LASTEXITCODE -ne 0) { throw 'Isolated Compose fixture startup failed.' }
            $started = $true
            $databaseBefore = (& $docker.Source @($common + @('ps', '-q', 'ingress-db')) 2>$null | Out-String).Trim()
            $nodeBefore = (& $docker.Source @($common + @('ps', '-q', 'ingress-node')) 2>$null | Out-String).Trim()
            if (-not $databaseBefore -or -not $nodeBefore) { throw 'The fixture did not create both services.' }

            $envText = 'INGRESS_NODE_BIND_ADDRESS=127.0.0.2' + $nl + "INGRESS_NODE_TEST_PORT=$httpsPort" + $nl + "INGRESS_OPERATOR_TEST_PORT=$operatorPort" + $nl
            [IO.File]::WriteAllText($environmentFile, $envText, [Text.UTF8Encoding]::new($false))
            $rebind = New-IngressComposeUpArguments $project $testRoot $environmentFile @($fixturePath) $true
            $rebindOutput = & $docker.Source @(@('compose') + $rebind) 2>&1
            if ($LASTEXITCODE -ne 0) { throw 'The fixture Node could not be rebound.' }

            $databaseAfter = (& $docker.Source @($common + @('ps', '-q', 'ingress-db')) 2>$null | Out-String).Trim()
            $nodeAfter = (& $docker.Source @($common + @('ps', '-q', 'ingress-node')) 2>$null | Out-String).Trim()
            $databaseAfter | Should Be $databaseBefore
            $nodeAfter | Should Not Be $nodeBefore
            ((& $docker.Source port $nodeAfter '8443/tcp' 2>$null | Out-String).Trim()) | Should Match '^127\.0\.0\.2:'
            ((& $docker.Source port $nodeAfter '8444/tcp' 2>$null | Out-String).Trim()) | Should Match '^127\.0\.0\.1:'
            (Select-String -LiteralPath $fixturePath -Pattern '^\s+volumes:' -Quiet) | Should Be $false
        }
        finally {
            if ($project -match '^ingress-network-manager-fixture-[a-f0-9]{8}$' -and $started) {
                & $docker.Source @($common + @('down', '--remove-orphans')) 2>$null | Out-Null
            }
            if ($hadBind) { $env:INGRESS_NODE_BIND_ADDRESS = $savedBind }
            else { Remove-Item Env:INGRESS_NODE_BIND_ADDRESS -ErrorAction SilentlyContinue }
            if (Test-Path -LiteralPath $testRoot) {
                $tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar
                $resolvedTestRoot = [IO.Path]::GetFullPath($testRoot)
                $safeLeaf = [IO.Path]::GetFileName($resolvedTestRoot) -match '^ingress-network-manager-[a-f0-9]{32}$'
                if (-not $resolvedTestRoot.StartsWith($tempRoot, [StringComparison]::OrdinalIgnoreCase) -or -not $safeLeaf) {
                    throw 'Refusing to remove a test directory outside the uniquely named ingress temp fixture.'
                }
                Remove-Item -LiteralPath $resolvedTestRoot -Recurse -Force
            }
        }
    }
}
