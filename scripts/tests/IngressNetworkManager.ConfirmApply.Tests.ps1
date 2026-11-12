$script:modulePath = Join-Path $PSScriptRoot '..\IngressNetworkManager.psm1'
Import-Module $script:modulePath -Force

function New-ApplyConfirmationFixture {
    $adapter = [pscustomobject] @{
        Name = 'Fixture Wi-Fi'
        InterfaceDescription = 'Fixture Wi-Fi Adapter'
        CandidateMode = 'Phone Hotspot'
        Status = 'Up'
        InterfaceIndex = 50
        NetworkCategory = 'Private'
        IPv4Addresses = @([pscustomobject] @{ Address = '192.168.55.158'; PrefixLength = 24; AddressState = 'Preferred' })
    }

    [pscustomobject] @{
        PlannedChangeCalls = 0
        LastBindChanged = $false
        StopInvoked = $false
        LastWslArguments = @()
        AdmissionKnown = $true
        AdmissionsActive = $false
        Context = [pscustomobject] @{
            Distro = 'Fixture-Ubuntu'
            Project = 'ingress-node-fixture'
            WorkingDirectory = '/fixture/api'
            EnvironmentFile = '/fixture/api/node-compose.env'
            ComposeFiles = @('/fixture/api/compose.node.yaml')
            NodeContainerId = 'fixture-node'
            DatabaseContainerId = 'fixture-db'
            MigrationContainerId = 'fixture-migrate'
            SchedulerContainerId = 'fixture-scheduler'
            DockerVersion = 'fixture'
            ComposeVersion = 'v2.0.0'
        }
        Adapter = $adapter
        Settings = [pscustomobject] @{
            INGRESS_NODE_BIND_ADDRESS = '192.168.55.158'
            APP_URL = 'https://192.168.55.158'
            INGRESS_NODE_HTTPS_PORT = '443'
            INGRESS_OPERATOR_PORT = '8444'
            DB_PASSWORD = 'fixture-db-secret'
        }
        Profile = [pscustomobject] @{
            Name = 'Phone Hotspot'
            Mode = 'Phone Hotspot'
            Address = '192.168.55.158'
            AppUrl = 'https://192.168.55.158'
            IsConfigured = $true
        }
    }
}

function Install-ApplyConfirmationFixtures {
    param([Parameter(Mandatory = $true)] $Fixture)

    $module = Get-Module IngressNetworkManager
    & $module {
        param($fixture)
        $script:ApplyConfirmationFixture = $fixture

        Set-Item Function:\Get-IngressComposeContext -Value {
            return $script:ApplyConfirmationFixture.Context
        }
        Set-Item Function:\Read-IngressNodeEnvironment -Value {
            return $script:ApplyConfirmationFixture.Settings
        }
        Set-Item Function:\Get-IngressNetworkSnapshot -Value {
            return @($script:ApplyConfirmationFixture.Adapter)
        }
        Set-Item Function:\Get-IngressSavedNetworkProfiles -Value {
            return [pscustomobject] @{ Known = $true; Profiles = @($script:ApplyConfirmationFixture.Profile) }
        }
        Set-Item Function:\Get-IngressFirewallProfileState -Value {
            return [pscustomobject] @{ Known = $true; Enabled = $true }
        }
        Set-Item Function:\Get-IngressComposeSummary -Value {
            [pscustomobject] @{
                Valid = $true
                BindMatches = $true
                OperatorPortIsolated = $true
                AppUrl = $script:ApplyConfirmationFixture.Profile.AppUrl
                CertificatePath = '/fixture/node.crt'
            }
        }
        Set-Item Function:\Get-IngressContainerState -Value {
            param($Context, $ContainerId, $CommandRunner)
            if ($ContainerId -eq 'fixture-node' -and $script:ApplyConfirmationFixture.StopInvoked) {
                return [pscustomobject] @{ Known = $true; Status = 'exited'; Health = 'none'; ExitCode = 0 }
            }
            switch ($ContainerId) {
                'fixture-node' { return [pscustomobject] @{ Known = $true; Status = 'running'; Health = 'healthy'; ExitCode = 0 } }
                'fixture-db' { return [pscustomobject] @{ Known = $true; Status = 'running'; Health = 'healthy'; ExitCode = 0 } }
                'fixture-migrate' { return [pscustomobject] @{ Known = $true; Status = 'exited'; Health = 'none'; ExitCode = 0 } }
                'fixture-scheduler' { return [pscustomobject] @{ Known = $true; Status = 'running'; Health = 'healthy'; ExitCode = 0 } }
                default { return [pscustomobject] @{ Known = $false; Status = 'unknown'; Health = 'unknown'; ExitCode = -1 } }
            }
        }
        Set-Item Function:\Read-IngressAdmissionState -Value {
            return [pscustomobject] @{
                Known = [bool] $script:ApplyConfirmationFixture.AdmissionKnown
                Active = [bool] $script:ApplyConfirmationFixture.AdmissionsActive
                Count = 0
            }
        }
        Set-Item Function:\Read-IngressCertificateNames -Value {
            return [pscustomobject] @{
                Known = $true
                Valid = $true
                Names = 'IP Address:192.168.55.158'
                ExpiresAtUtc = $null
                DaysRemaining = $null
            }
        }
        Set-Item Function:\Read-IngressNodeOperationsSummary -Value {
            return [pscustomobject] @{ Known = $true; PendingSyncRecords = 0; AuthorityUncertain = $false }
        }
        Set-Item Function:\Get-IngressActualPortStatus -Value {
            return [pscustomobject] @{
                Known = $true
                NodeMatches = $true
                OperatorIsolated = $true
                NodeBinding = '192.168.55.158:8443'
                OperatorBinding = '127.0.0.1:8444'
            }
        }
        Set-Item Function:\Test-IngressFirewallPolicy -Value {
            return [pscustomobject] @{ Allowed = $true; RuleName = 'fixture scoped rule'; RequiredRemoteScope = '192.168.55.0/24' }
        }
        Set-Item Function:\Get-IngressLanDemoHost -Value {
            return $null
        }
        Set-Item Function:\Get-IngressWindowsNetworkProbe -Value {
            return $true
        }
        Set-Item Function:\Invoke-IngressPlannedChange -Value {
            param($Gate, $BindChanged, $NodeRunning, $TargetAddress, $TargetAppUrl, $PreviousAddress, $PreviousAppUrl, $WriteBinding, $ApplyNode)
            $script:ApplyConfirmationFixture.PlannedChangeCalls++
            $script:ApplyConfirmationFixture.LastBindChanged = [bool] $BindChanged
            return [pscustomobject] @{ Outcome = 'NoChange'; Changed = $false }
        }
        Set-Item Function:\Invoke-IngressWslCommand -Value {
            param($Distro, $Arguments, $CommandRunner, [switch] $SuppressStderr)
            $script:ApplyConfirmationFixture.StopInvoked = $true
            $script:ApplyConfirmationFixture.LastWslArguments = @($Arguments)
            return [pscustomobject] @{ ExitCode = 0; Output = '' }
        }
        Set-Item Function:\Start-Sleep -Value { param($Seconds) }
    } $Fixture
}

Describe 'Ingress Network Manager apply confirmation' {
    BeforeEach {
        Import-Module $script:modulePath -Force
    }

    AfterEach {
        Import-Module $script:modulePath -Force
    }

    It 'applies with -ConfirmApply without prompting on the non-interactive JSON path' {
        $fixture = New-ApplyConfirmationFixture
        $fixture.Settings.INGRESS_NODE_BIND_ADDRESS = '192.168.137.1'
        $fixture.Settings.APP_URL = 'https://192.168.137.1'
        Install-ApplyConfirmationFixtures $fixture

        $status = Invoke-IngressNetworkManager -Mode 'Phone Hotspot' -Apply -ConfirmApply -Json `
            -Adapters @($fixture.Adapter) -FirewallRules @()

        $fixture.PlannedChangeCalls | Should Be 1
        $fixture.LastBindChanged | Should Be $true
        $status.CanApplyNetworkChange | Should Be $true
        $json = ConvertTo-Json -InputObject $status -Depth 12 -Compress
        $parsed = ConvertFrom-Json $json
        $parsed.SchemaVersion | Should Be 1
        $json | Should Not Match 'fixture-db-secret'
    }

    It 'keeps interactive confirmation and makes no planned changes when the CLI user cancels' {
        $fixture = New-ApplyConfirmationFixture
        $fixture.Settings.INGRESS_NODE_BIND_ADDRESS = '192.168.137.1'
        $fixture.Settings.APP_URL = 'https://192.168.137.1'
        Install-ApplyConfirmationFixtures $fixture

        $status = Invoke-IngressNetworkManager -Mode 'Phone Hotspot' -Apply -Json `
            -Adapters @($fixture.Adapter) -FirewallRules @()

        $fixture.PlannedChangeCalls | Should Be 0
        $status.CanApplyNetworkChange | Should Be $true
    }

    It 'prompts only for interactive application and requires the exact APPLY response' {
        $module = Get-Module IngressNetworkManager
        $promptState = [pscustomobject] @{ Calls = 0 }
        $prompt = { $promptState.Calls++; 'APPLY' }.GetNewClosure()

        $confirmed = & $module {
            param($prompt)
            Test-IngressApplyConfirmation -ConfirmApply -Prompt $prompt
        } $prompt

        $confirmed | Should Be $true
        $promptState.Calls | Should Be 0

        $declinePrompt = { $promptState.Calls++; 'apply' }.GetNewClosure()
        $declined = & $module {
            param($prompt)
            Test-IngressApplyConfirmation -Prompt $prompt
        } $declinePrompt

        $declined | Should Be $false
        $promptState.Calls | Should Be 1
    }

    It 'rejects -ConfirmApply without -Apply before reading state or prompting' {
        $fixture = New-ApplyConfirmationFixture
        Install-ApplyConfirmationFixtures $fixture
        $thrown = $false

        try {
            Invoke-IngressNetworkManager -Mode 'Phone Hotspot' -ConfirmApply -Json `
                -Adapters @($fixture.Adapter) -FirewallRules @()
        }
        catch {
            $thrown = $_.Exception.Message -match '-ConfirmApply requires -Apply'
        }

        $thrown | Should Be $true
        $fixture.PlannedChangeCalls | Should Be 0
    }

    It 'blocks confirmed application when admission state is active or unknown' {
        foreach ($case in @('active', 'unknown')) {
            $fixture = New-ApplyConfirmationFixture
            if ($case -eq 'active') { $fixture.AdmissionsActive = $true }
            else { $fixture.AdmissionKnown = $false }
            Install-ApplyConfirmationFixtures $fixture

            $status = Invoke-IngressNetworkManager -Mode 'Phone Hotspot' -Apply -ConfirmApply -Json `
                -Adapters @($fixture.Adapter) -FirewallRules @()

            $status.CanApplyNetworkChange | Should Be $false
            $fixture.PlannedChangeCalls | Should Be 0
        }
    }

    It 'preserves Safe Stop confirmation and stops only ingress-node' {
        $fixture = New-ApplyConfirmationFixture
        Install-ApplyConfirmationFixtures $fixture

        $status = Invoke-IngressNetworkManager -SafeStop -Apply -ConfirmStop -Json `
            -Adapters @($fixture.Adapter) -FirewallRules @()

        $fixture.StopInvoked | Should Be $true
        ($fixture.LastWslArguments -join ' ') | Should Match 'stop --timeout 30 ingress-node$'
        ($fixture.LastWslArguments -join ' ') | Should Not Match 'down|--volumes|-v|rm|ingress-db|ingress-scheduler'
        $status.NodeStatus | Should Match 'exited'
    }

    It 'keeps Safe Stop interactive and makes no change when the CLI user cancels' {
        $fixture = New-ApplyConfirmationFixture
        Install-ApplyConfirmationFixtures $fixture

        $status = Invoke-IngressNetworkManager -SafeStop -Apply -Json `
            -Adapters @($fixture.Adapter) -FirewallRules @()

        $fixture.StopInvoked | Should Be $false
        $status.CanSafelyStop | Should Be $true
    }
}
