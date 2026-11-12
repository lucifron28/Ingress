using Ingress.NodeManager.Core.Security;

namespace Ingress.NodeManager.Core.Status;

public static class SafeDiagnosticsBuilder
{
    public static string Build(NodeManagerStatus status)
    {
        ArgumentNullException.ThrowIfNull(status);
        var lines = new List<string>
        {
            "Ingress Node Manager safe diagnostics",
            $"Generated UTC: {DateTimeOffset.UtcNow:yyyy-MM-dd HH:mm:ss}",
            $"Network mode: {status.NetworkMode}",
            $"Active adapter address: {status.LanAddress}",
            $"Saved network profile: {status.TargetProfileName}; matches: {status.TargetProfileMatches}",
            $"Target Node URL: {GetSafeOrigin(status.TargetAppUrl)}",
            $"Node URL: {GetSafeOrigin(status.NodeUrl)}",
            $"Docker / WSL2: {status.DockerStatus}",
            $"Event Node: {status.NodeStatus}",
            $"PostgreSQL: {status.DatabaseStatus}",
            $"Migrations: {status.MigrationStatus}",
            $"Migration service: {status.MigrationStatus}",
            $"Scheduler: {status.SchedulerStatus}; heartbeat verified: {status.SchedulerHeartbeatVerified}",
            $"HTTPS certificate: {status.CertificateStatus}; expires UTC: {status.CertificateExpiresAtUtc ?? "unknown"}",
            $"Windows Firewall: {status.FirewallStatus}",
            $"Operator port: {status.OperatorPortStatus}",
            $"Admission state: {status.AdmissionStatus}",
            $"Pending synchronization state known: {status.PendingSyncStateKnown}; pending records: {status.PendingSyncRecords?.ToString() ?? "unknown"}",
            $"Android scanner readiness: {status.ScannerReadiness}",
            "Physical-device connectivity and installed APK trust: requires device testing.",
        };

        lines.AddRange(status.Adapters.Select(adapter =>
            $"Adapter: {adapter.Name} · {adapter.Mode} · {adapter.Address}/{adapter.PrefixLength} · {adapter.NetworkCategory} · up: {adapter.IsUp}"));
        lines.AddRange(status.Blockers.Select(blocker => $"Network change blocked: {blocker}"));
        lines.AddRange(status.SafeStopBlockers.Select(blocker => $"Safe Stop blocked: {blocker}"));
        lines.AddRange(status.Warnings.Select(warning => $"Note: {warning}"));
        if (!string.IsNullOrWhiteSpace(status.ErrorMessage))
        {
            lines.Add($"Status error: {status.ErrorMessage}");
        }

        return SecretRedactor.Redact(string.Join(Environment.NewLine, lines));
    }

    private static string GetSafeOrigin(string value)
    {
        if (!Uri.TryCreate(value, UriKind.Absolute, out var uri) || uri.Scheme != Uri.UriSchemeHttps)
        {
            return "unknown";
        }

        var builder = new UriBuilder(uri)
        {
            UserName = string.Empty,
            Password = string.Empty,
            Path = string.Empty,
            Query = string.Empty,
            Fragment = string.Empty,
        };
        return builder.Uri.GetLeftPart(UriPartial.Authority);
    }
}
