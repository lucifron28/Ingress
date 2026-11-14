using Ingress.NodeManager.Core.Status;

namespace Ingress.NodeManager.Tests;

public sealed class NodeManagerStatusTests
{
    [Fact]
    public void MissingEvidenceStaysUnknownAndCannotAuthorizeSafeStop()
    {
        var status = NodeManagerStatus.Parse("{}");

        Assert.Equal(HealthLevel.Unknown, status.GetIndicator("Event Node").Level);
        Assert.Equal(HealthLevel.Unknown, status.GetIndicator("Network Mode").Level);
        Assert.Equal(HealthLevel.Unknown, status.GetIndicator("Database Migrations").Level);
        Assert.Equal(HealthLevel.Unknown, status.GetIndicator("Windows Firewall").Level);
        Assert.False(status.CanSafelyStop);
        Assert.Contains("admission", string.Join(' ', status.SafeStopBlockers), StringComparison.OrdinalIgnoreCase);
        Assert.Contains("sync", string.Join(' ', status.SafeStopBlockers), StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void UnknownFirewallStateShowsTheSpecificNextStepWhenAvailable()
    {
        const string nextStep = "Select the current LAN adapter and private IPv4 address to check its scoped firewall rule.";
        var status = NodeManagerStatus.Parse("""
            {"FirewallStatus":"Select the current LAN adapter and private IPv4 address to check its scoped firewall rule."}
            """);

        var firewall = status.GetIndicator("Windows Firewall");
        Assert.Equal(HealthLevel.Unknown, firewall.Level);
        Assert.Equal(nextStep, firewall.Detail);
    }

    [Fact]
    public void MissingWslOrDockerEvidenceStaysUnknownAndCannotApply()
    {
        var status = NodeManagerStatus.Parse("""
            {
              "CanApplyNetworkChange": false,
              "WslStateKnown": false,
              "DockerStateKnown": false,
              "NodeStateKnown": false,
              "DatabaseStateKnown": false
            }
            """);

        Assert.Equal(HealthLevel.Unknown, status.GetIndicator("Docker / WSL2").Level);
        Assert.Equal(HealthLevel.Unknown, status.GetIndicator("Event Node").Level);
        Assert.False(status.CanApplyNetworkChange);
        Assert.False(status.CanSafelyStop);
    }

    [Fact]
    public void VerifiedUnavailableDockerIsBlockedRatherThanHealthy()
    {
        var status = NodeManagerStatus.Parse("""
            {
              "WslStateKnown": true,
              "WslAvailable": true,
              "DockerStateKnown": true,
              "DockerAvailable": false
            }
            """);

        Assert.Equal(HealthLevel.Blocked, status.GetIndicator("Docker / WSL2").Level);
    }

    [Fact]
    public void NetworkAndMigrationIndicatorsNeedVerifiedEvidence()
    {
        var unmatched = NodeManagerStatus.Parse("""
            {"SchemaVersion":1,"NetworkMode":"Unmatched or unavailable","LanAddress":"192.168.1.2"}
            """);
        Assert.Equal(HealthLevel.Unknown, unmatched.GetIndicator("Network Mode").Level);

        var migrationFailed = NodeManagerStatus.Parse("""
            {"MigrationStateKnown":true,"MigrationsComplete":false,"MigrationStatus":"exited (exit 1)"}
            """);
        Assert.Equal(HealthLevel.Blocked, migrationFailed.GetIndicator("Database Migrations").Level);

        var migrationPassed = migrationFailed with { MigrationsComplete = true };
        Assert.Equal(HealthLevel.Healthy, migrationPassed.GetIndicator("Database Migrations").Level);
    }

    [Fact]
    public void CertificateNearExpiryIsWarningEvenWhenHostMatches()
    {
        var status = NodeManagerStatus.Parse("""
            {
              "CertificateKnown": true,
              "CertificateValid": true,
              "CertificateHostMatches": true,
              "CertificateDaysRemaining": 5
            }
            """);

        Assert.Equal(HealthLevel.Warning, status.GetIndicator("HTTPS Certificate").Level);
    }

    [Fact]
    public void PhysicalScannerVerificationNeverAppearsHealthyFromDesktopEvidence()
    {
        var status = NodeManagerStatus.Parse("""
            {
              "LanProbeReachable": true,
              "LanDemoHostMatches": true,
              "LanDemoTrustVerified": false
            }
            """);

        Assert.Equal(HealthLevel.Unknown, status.GetIndicator("Android Scanner").Level);
        Assert.Contains("physical device", status.GetIndicator("Android Scanner").Detail, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void CertificateFirewallAndSchedulerMismatchesRemainVisible()
    {
        var status = NodeManagerStatus.Parse("""
            {
              "CertificateKnown": true,
              "CertificateValid": true,
              "CertificateHostMatches": false,
              "CertificateDaysRemaining": 100,
              "FirewallStateKnown": true,
              "FirewallAllowed": false,
              "SchedulerStateKnown": true,
              "SchedulerProcessRunning": true,
              "SchedulerHeartbeatVerified": false
            }
            """);

        Assert.Equal(HealthLevel.Blocked, status.GetIndicator("HTTPS Certificate").Level);
        Assert.Equal(HealthLevel.Blocked, status.GetIndicator("Windows Firewall").Level);
        Assert.Equal(HealthLevel.Warning, status.GetIndicator("Scheduler").Level);
    }

    [Fact]
    public void SavedProfilesAreReadAsSafeNetworkSettings()
    {
        var status = NodeManagerStatus.Parse("""
            {
              "ProfileDiscoveryKnown": true,
              "SavedProfiles": [
                {"Name":"Phone Hotspot","Mode":"Phone Hotspot","Address":"192.168.55.158","AppUrl":"https://192.168.55.158","IsConfigured":true},
                {"Name":"Laptop Hotspot","Mode":"Laptop Hotspot","Address":"192.168.137.1","AppUrl":"https://192.168.137.1","IsConfigured":true}
              ]
            }
            """);

        Assert.Equal(2, status.SavedProfiles.Length);
        Assert.All(status.SavedProfiles, profile => Assert.True(profile.IsConfigured));
        Assert.Equal("192.168.137.1", status.SavedProfiles[1].Address);
    }

    [Fact]
    public void MalformedProfileDataDoesNotCreateAnApplyableNetwork()
    {
        var status = NodeManagerStatus.Parse("""
            {
              "ProfileDiscoveryKnown": false,
              "SavedProfiles": [],
              "CanApplyNetworkChange": false,
              "TargetBindAddress": "Unknown"
            }
            """);

        Assert.False(status.ProfileDiscoveryKnown);
        Assert.Empty(status.SavedProfiles);
        Assert.False(status.CanApplyNetworkChange);
        Assert.Equal("Unknown", status.TargetBindAddress);
    }

    [Fact]
    public void ANetworkChangeRequiresItsSavedProfileToMatchTheHttpsTarget()
    {
        const string json = """
        {
          "ProfileDiscoveryKnown": true,
          "TargetProfileName": "Laptop Hotspot",
          "TargetBindAddress": "192.168.137.1",
          "TargetAppUrl": "https://192.168.137.1",
          "TargetProfileMatches": false,
          "CanApplyNetworkChange": false
        }
        """;

        var status = NodeManagerStatus.Parse(json);

        Assert.False(status.TargetProfileMatches);
        Assert.False(status.CanApplyNetworkChange);
        var indicator = status.GetIndicator("Saved Network Profile");
        Assert.Equal(HealthLevel.Blocked, indicator.Level);
        Assert.Contains("matching saved profile", indicator.Detail, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void SafeStopRequiresEndedAdmissionAndZeroKnownPendingRecords()
    {
        var safe = NodeManagerStatus.Parse("""
            {
              "NodeStateKnown": true,
              "NodeHealthy": true,
              "DatabaseStateKnown": true,
              "DatabaseHealthy": true,
              "AdmissionStateKnown": true,
              "AdmissionsPossible": false,
              "PendingSyncStateKnown": true,
              "PendingSyncRecords": 0,
              "AuthorityStateKnown": true,
              "AuthorityUncertain": false,
              "OperatorPortStateKnown": true,
              "OperatorPortIsolated": true
            }
            """);
        var pending = NodeManagerStatus.Parse("""
            {
              "NodeStateKnown": true,
              "NodeHealthy": true,
              "DatabaseStateKnown": true,
              "DatabaseHealthy": true,
              "AdmissionStateKnown": true,
              "AdmissionsPossible": false,
              "PendingSyncStateKnown": true,
              "PendingSyncRecords": 2,
              "AuthorityStateKnown": true,
              "AuthorityUncertain": false,
              "OperatorPortStateKnown": true,
              "OperatorPortIsolated": true
            }
            """);

        Assert.True(safe.CanSafelyStop);
        Assert.False(pending.CanSafelyStop);
        Assert.Contains("pending", string.Join(' ', pending.SafeStopBlockers), StringComparison.OrdinalIgnoreCase);
    }
}
