using System.Text.Json;

namespace Ingress.NodeManager.Core.Status;

public enum HealthLevel
{
    Healthy,
    Warning,
    Blocked,
    Unknown,
}

public sealed record HealthIndicator(string Name, HealthLevel Level, string Detail);

public sealed record NetworkAdapterStatus(
    string Name,
    string Description,
    string Mode,
    string Address,
    int PrefixLength,
    string NetworkCategory,
    bool IsUp);

public sealed record SavedNetworkProfile(string Name, string Mode, string Address, string AppUrl, bool IsConfigured);

public sealed record NodeManagerStatus
{
    public int SchemaVersion { get; init; }
    public string NetworkMode { get; init; } = "Unknown";
    public string LanAddress { get; init; } = "Unknown";
    public string NodeUrl { get; init; } = "Unknown";
    public string LanMapping { get; init; } = "Unknown";
    public string DockerStatus { get; init; } = "Unknown";
    public string CurrentBindAddress { get; init; } = "Unknown";
    public string TargetBindAddress { get; init; } = "Unknown";
    public string TargetProfileName { get; init; } = "Unknown";
    public string TargetAppUrl { get; init; } = "Unknown";
    public string? ErrorMessage { get; init; }
    public string NodeStatus { get; init; } = "Unknown";
    public string DatabaseStatus { get; init; } = "Unknown";
    public string MigrationStatus { get; init; } = "Unknown";
    public string SchedulerStatus { get; init; } = "Unknown";
    public string OperatorPortStatus { get; init; } = "Unknown";
    public string CertificateStatus { get; init; } = "Unknown";
    public string FirewallStatus { get; init; } = "Unknown";
    public string AdmissionStatus { get; init; } = "Unknown";
    public string LanDemoStatus { get; init; } = "Unknown";
    public string LanProbeStatus { get; init; } = "Unknown";
    public string ScannerReadiness { get; init; } = "Unknown";
    public string DockerVersion { get; init; } = "Unknown";
    public string WslDistribution { get; init; } = "Unknown";
    public string SchedulerDetail { get; init; } = "Scheduler heartbeat is not verified by this manager.";
    public string? CertificateExpiresAtUtc { get; init; }
    public int? CertificateDaysRemaining { get; init; }
    public int? AdmissionCapableEventCount { get; init; }
    public int? PendingSyncRecords { get; init; }
    public int OperatorPort { get; init; } = 8444;
    public bool DockerStateKnown { get; init; }
    public bool DockerAvailable { get; init; }
    public bool WslStateKnown { get; init; }
    public bool WslAvailable { get; init; }
    public bool NodeStateKnown { get; init; }
    public bool NodeHealthy { get; init; }
    public bool DatabaseStateKnown { get; init; }
    public bool DatabaseHealthy { get; init; }
    public bool MigrationStateKnown { get; init; }
    public bool MigrationsComplete { get; init; }
    public bool SchedulerStateKnown { get; init; }
    public bool SchedulerProcessRunning { get; init; }
    public bool SchedulerHeartbeatVerified { get; init; }
    public bool OperatorPortStateKnown { get; init; }
    public bool OperatorPortIsolated { get; init; }
    public bool CertificateKnown { get; init; }
    public bool CertificateValid { get; init; }
    public bool CertificateHostMatches { get; init; }
    public bool FirewallStateKnown { get; init; }
    public bool FirewallAllowed { get; init; }
    public bool AdmissionStateKnown { get; init; }
    public bool AdmissionsPossible { get; init; } = true;
    public bool PendingSyncStateKnown { get; init; }
    public bool AuthorityStateKnown { get; init; }
    public bool AuthorityUncertain { get; init; } = true;
    public bool LanProbeReachable { get; init; }
    public bool LanDemoHostMatches { get; init; }
    public bool LanDemoTrustVerified { get; init; }
    public bool CanApplyNetworkChange { get; init; }
    public bool ProfileDiscoveryKnown { get; init; }
    public bool TargetProfileMatches { get; init; }
    public string[] Blockers { get; init; } = [];
    public string[] Warnings { get; init; } = [];
    public NetworkAdapterStatus[] Adapters { get; init; } = [];
    public SavedNetworkProfile[] SavedProfiles { get; init; } = [];

    public string[] SafeStopBlockers => GetSafeStopBlockers();

    public bool CanSafelyStop => SafeStopBlockers.Length == 0
        && NodeStateKnown && NodeHealthy
        && DatabaseStateKnown && DatabaseHealthy
        && AdmissionStateKnown && !AdmissionsPossible
        && PendingSyncStateKnown && PendingSyncRecords == 0
        && AuthorityStateKnown && !AuthorityUncertain
        && OperatorPortStateKnown && OperatorPortIsolated;

    public static NodeManagerStatus Parse(string json)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(json);
        return JsonSerializer.Deserialize<NodeManagerStatus>(json, new JsonSerializerOptions
        {
            PropertyNameCaseInsensitive = true,
        }) ?? new NodeManagerStatus();
    }

    private string[] GetSafeStopBlockers()
    {
        var blockers = new List<string>();
        if (!AdmissionStateKnown)
        {
            blockers.Add("Admission state is unknown; stop was refused.");
        }
        else if (AdmissionsPossible)
        {
            blockers.Add("An event may still accept admissions; stop was refused.");
        }

        if (!PendingSyncStateKnown || PendingSyncRecords is null)
        {
            blockers.Add("Pending synchronization state is unknown; stop was refused.");
        }
        else if (PendingSyncRecords > 0)
        {
            blockers.Add($"{PendingSyncRecords} pending records are still waiting for synchronization.");
        }

        if (!AuthorityStateKnown || AuthorityUncertain)
        {
            blockers.Add("Event authority state is unknown or uncertain; stop was refused.");
        }

        if (!NodeStateKnown || !NodeHealthy)
        {
            blockers.Add("The Event Node is not verified healthy; stop was refused.");
        }

        if (!DatabaseStateKnown || !DatabaseHealthy)
        {
            blockers.Add("The PostgreSQL database is not verified healthy; stop was refused.");
        }

        if (!OperatorPortStateKnown || !OperatorPortIsolated)
        {
            blockers.Add("The operator port is not verified loopback-only; stop was refused.");
        }

        return blockers.ToArray();
    }

    public HealthIndicator GetIndicator(string name)
    {
        return name switch
        {
            "Network Mode" => NetworkModeIndicator(),
            "Docker / WSL2" => DockerIndicator(),
            "Event Node" => NodeIndicator(),
            "PostgreSQL" => DatabaseIndicator(),
            "Database Migrations" => MigrationIndicator(),
            "Scheduler" => SchedulerIndicator(),
            "HTTPS Certificate" => CertificateIndicator(),
            "Windows Firewall" => FirewallIndicator(),
            "Operator Port" => OperatorPortIndicator(),
            "Admission Safety" => AdmissionIndicator(),
            "Saved Network Profile" => SavedNetworkProfileIndicator(),
            "Android Scanner" => ScannerIndicator(),
            _ => new HealthIndicator(name, HealthLevel.Unknown, "No status source is configured."),
        };
    }

    private HealthIndicator NetworkModeIndicator()
    {
        if (SchemaVersion == 0 || NetworkMode is "Unknown" or "Unmatched or unavailable" || LanAddress is "Unknown" or "")
        {
            return new HealthIndicator("Network Mode", HealthLevel.Unknown, "The active adapter and address could not be verified.");
        }

        if (NetworkMode is not ("Phone Hotspot" or "Laptop Hotspot"))
        {
            return new HealthIndicator("Network Mode", HealthLevel.Unknown, "The active hotspot mode is ambiguous.");
        }

        return new HealthIndicator("Network Mode", HealthLevel.Healthy, $"{NetworkMode} · {LanAddress}");
    }

    private HealthIndicator DockerIndicator()
    {
        if (!WslStateKnown || !DockerStateKnown)
        {
            return new HealthIndicator("Docker / WSL2", HealthLevel.Unknown, "WSL2 or Docker status could not be verified.");
        }

        return WslAvailable && DockerAvailable
            ? new HealthIndicator("Docker / WSL2", HealthLevel.Healthy, $"WSL2 {WslDistribution}; Docker {DockerVersion}.")
            : new HealthIndicator("Docker / WSL2", HealthLevel.Blocked, "WSL2 or Docker Desktop is unavailable.");
    }

    private HealthIndicator NodeIndicator()
    {
        if (!NodeStateKnown)
        {
            return new HealthIndicator("Event Node", HealthLevel.Unknown, "Container state could not be verified.");
        }

        return NodeHealthy
            ? new HealthIndicator("Event Node", HealthLevel.Healthy, NodeStatus)
            : new HealthIndicator("Event Node", HealthLevel.Warning, NodeStatus);
    }

    private HealthIndicator DatabaseIndicator()
    {
        if (!DatabaseStateKnown)
        {
            return new HealthIndicator("PostgreSQL", HealthLevel.Unknown, "Database container health could not be verified.");
        }

        return DatabaseHealthy
            ? new HealthIndicator("PostgreSQL", HealthLevel.Healthy, DatabaseStatus)
            : new HealthIndicator("PostgreSQL", HealthLevel.Blocked, DatabaseStatus);
    }

    private HealthIndicator MigrationIndicator()
    {
        if (!MigrationStateKnown)
        {
            return new HealthIndicator("Database Migrations", HealthLevel.Unknown, "The existing migration service state could not be verified.");
        }

        return MigrationsComplete
            ? new HealthIndicator("Database Migrations", HealthLevel.Healthy, MigrationStatus)
            : new HealthIndicator("Database Migrations", HealthLevel.Blocked, MigrationStatus);
    }

    private HealthIndicator SchedulerIndicator()
    {
        if (!SchedulerStateKnown)
        {
            return new HealthIndicator("Scheduler", HealthLevel.Unknown, "The scheduler container state could not be verified.");
        }

        if (!SchedulerProcessRunning)
        {
            return new HealthIndicator("Scheduler", HealthLevel.Blocked, SchedulerStatus);
        }

        return SchedulerHeartbeatVerified
            ? new HealthIndicator("Scheduler", HealthLevel.Healthy, SchedulerDetail)
            : new HealthIndicator("Scheduler", HealthLevel.Warning, "Container is running; scheduler heartbeat is not verified.");
    }

    private HealthIndicator CertificateIndicator()
    {
        if (!CertificateKnown)
        {
            return new HealthIndicator("HTTPS Certificate", HealthLevel.Unknown, "Certificate expiry and hostname could not be verified.");
        }

        if (!CertificateValid || !CertificateHostMatches || CertificateDaysRemaining is <= 0)
        {
            return new HealthIndicator("HTTPS Certificate", HealthLevel.Blocked, CertificateStatus);
        }

        if (CertificateDaysRemaining is <= 14)
        {
            return new HealthIndicator("HTTPS Certificate", HealthLevel.Warning, $"Certificate expires in {CertificateDaysRemaining} days.");
        }

        return new HealthIndicator("HTTPS Certificate", HealthLevel.Healthy, CertificateStatus);
    }

    private HealthIndicator FirewallIndicator()
    {
        if (!FirewallStateKnown)
        {
            return new HealthIndicator("Windows Firewall", HealthLevel.Unknown, "The active profile and scoped rule could not be verified.");
        }

        return FirewallAllowed
            ? new HealthIndicator("Windows Firewall", HealthLevel.Healthy, FirewallStatus)
            : new HealthIndicator("Windows Firewall", HealthLevel.Blocked, FirewallStatus);
    }

    private HealthIndicator OperatorPortIndicator()
    {
        if (!OperatorPortStateKnown)
        {
            return new HealthIndicator("Operator Port", HealthLevel.Unknown, "Loopback-only binding could not be verified.");
        }

        return OperatorPortIsolated
            ? new HealthIndicator("Operator Port", HealthLevel.Healthy, OperatorPortStatus)
            : new HealthIndicator("Operator Port", HealthLevel.Blocked, OperatorPortStatus);
    }

    private HealthIndicator AdmissionIndicator()
    {
        if (!AdmissionStateKnown)
        {
            return new HealthIndicator("Admission Safety", HealthLevel.Unknown, "Admission state is unknown; disruptive actions are blocked.");
        }

        return AdmissionsPossible
            ? new HealthIndicator("Admission Safety", HealthLevel.Warning, AdmissionStatus)
            : new HealthIndicator("Admission Safety", HealthLevel.Healthy, AdmissionStatus);
    }

    private HealthIndicator ScannerIndicator()
    {
        if (!LanDemoTrustVerified)
        {
            return new HealthIndicator("Android Scanner", HealthLevel.Unknown,
                "Desktop checks cannot verify the APK trust or device route. Physical device testing is required.");
        }

        if (!LanProbeReachable || !LanDemoHostMatches)
        {
            return new HealthIndicator("Android Scanner", HealthLevel.Blocked, ScannerReadiness);
        }

        return new HealthIndicator("Android Scanner", HealthLevel.Healthy, ScannerReadiness);
    }

    private HealthIndicator SavedNetworkProfileIndicator()
    {
        if (!ProfileDiscoveryKnown)
        {
            return new HealthIndicator("Saved Network Profile", HealthLevel.Unknown,
                "The manager could not safely read the prepared hotspot profiles.");
        }

        return TargetProfileMatches
            ? new HealthIndicator("Saved Network Profile", HealthLevel.Healthy,
                $"{TargetProfileName} matches {TargetBindAddress} and its HTTPS URL.")
            : new HealthIndicator("Saved Network Profile", HealthLevel.Blocked,
                "A matching saved profile is required before changing the Node network.");
    }
}
