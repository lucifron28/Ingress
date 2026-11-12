using System.ComponentModel;
using System.Diagnostics;
using System.Text.Json;
using Ingress.NodeManager.Core.Execution;
using Ingress.NodeManager.Core.Security;
using Ingress.NodeManager.Core.Status;

namespace Ingress.NodeManager.Core.Networking;

public enum NetworkMode
{
    PhoneHotspot,
    LaptopHotspot,
    AutoDetect,
}

public sealed record NodeManagerInstallation(string PowerShellExecutable, string ManagerScript, string RepositoryRoot);

public sealed class NetworkManagerClient(IProcessRunner processRunner, NodeManagerInstallation installation)
{
    private static readonly TimeSpan DefaultTimeout = TimeSpan.FromMinutes(3);

    public async Task<NodeManagerStatus> GetStatusAsync(CancellationToken cancellationToken)
    {
        return await RunAsync(["-LanDemo", "-Status"], cancellationToken).ConfigureAwait(false);
    }

    public Task<NodeManagerStatus> PreviewLanDemoNetworkChangeAsync(NetworkAdapterStatus adapter, CancellationToken cancellationToken)
    {
        ValidateLanAdapter(adapter);
        return RunAsync(["-LanDemo", "-InterfaceIndex", adapter.InterfaceIndex.ToString(), "-BindAddress", adapter.Address, "-DryRun"], cancellationToken);
    }

    public Task<NodeManagerStatus> ApplyLanDemoNetworkChangeAsync(NetworkAdapterStatus adapter, bool confirmationAccepted, CancellationToken cancellationToken)
    {
        if (!confirmationAccepted) throw new InvalidOperationException("Network changes require explicit confirmation.");
        ValidateLanAdapter(adapter);
        return RunAsync(["-LanDemo", "-InterfaceIndex", adapter.InterfaceIndex.ToString(), "-BindAddress", adapter.Address, "-Apply", "-ConfirmApply"], cancellationToken);
    }

    public Task<NodeManagerStatus> StartLanDemoNodeAsync(NetworkAdapterStatus adapter, bool confirmationAccepted, CancellationToken cancellationToken)
    {
        if (!confirmationAccepted) throw new InvalidOperationException("Starting or resuming the Event Node requires explicit confirmation.");
        ValidateLanAdapter(adapter);
        return RunAsync(["-LanDemo", "-InterfaceIndex", adapter.InterfaceIndex.ToString(), "-BindAddress", adapter.Address, "-Apply", "-ConfirmApply"], cancellationToken);
    }

    public async Task ConfigureLanFirewallAsync(NetworkAdapterStatus adapter, int port, CancellationToken cancellationToken)
    {
        ValidateLanAdapter(adapter);
        if (port is < 1 or > 65535) throw new ArgumentOutOfRangeException(nameof(port));
        var script = Path.Combine(installation.RepositoryRoot, "scripts", "IngressLanFirewall.ps1");
        if (!File.Exists(script)) throw new InvalidOperationException("The bundled LAN firewall helper could not be found.");
        var startInfo = new ProcessStartInfo(installation.PowerShellExecutable)
        {
            UseShellExecute = true,
            Verb = "runas",
            WindowStyle = ProcessWindowStyle.Hidden,
        };
        foreach (var argument in new[]
        {
            "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", script,
            "-InterfaceIndex", adapter.InterfaceIndex.ToString(), "-Address", adapter.Address, "-Port", port.ToString(),
        }) startInfo.ArgumentList.Add(argument);
        Process process;
        try
        {
            process = Process.Start(startInfo) ?? throw new InvalidOperationException("Windows could not start the firewall setup helper.");
        }
        catch (Win32Exception exception) when (exception.NativeErrorCode == 1223)
        {
            throw new InvalidOperationException("Firewall setup was cancelled. The Event Node was not started.", exception);
        }

        using (process)
        {
            await process.WaitForExitAsync(cancellationToken).ConfigureAwait(false);
            if (process.ExitCode != 0) throw new InvalidOperationException("Windows Firewall did not confirm the scoped LAN rule. The Event Node was not started.");
        }
    }

    public async Task<NodeManagerStatus> PreviewNetworkChangeAsync(NetworkMode mode, CancellationToken cancellationToken)
    {
        return await RunAsync(["-Mode", ToCliValue(mode), "-DryRun"], cancellationToken).ConfigureAwait(false);
    }

    public async Task<NodeManagerStatus> ApplyNetworkChangeAsync(
        NetworkMode mode,
        bool confirmationAccepted,
        CancellationToken cancellationToken)
    {
        if (!confirmationAccepted)
        {
            throw new InvalidOperationException("Network changes require explicit confirmation.");
        }

        return await RunAsync(["-Mode", ToCliValue(mode), "-Apply", "-ConfirmApply"], cancellationToken).ConfigureAwait(false);
    }

    public async Task<NodeManagerStatus> StartNodeAsync(
        NetworkMode mode,
        bool confirmationAccepted,
        CancellationToken cancellationToken)
    {
        if (mode == NetworkMode.AutoDetect)
        {
            throw new InvalidOperationException("Select a prepared hotspot before starting the Event Node.");
        }

        if (!confirmationAccepted)
        {
            throw new InvalidOperationException("Starting or resuming the Event Node requires explicit confirmation.");
        }

        return await RunAsync(["-Mode", ToCliValue(mode), "-Apply", "-ConfirmApply"], cancellationToken).ConfigureAwait(false);
    }

    public async Task<NodeManagerStatus> SafeStopNodeAsync(bool confirmationAccepted, CancellationToken cancellationToken)
    {
        if (!confirmationAccepted)
        {
            throw new InvalidOperationException("Stopping the Event Node requires explicit confirmation.");
        }

        return await RunAsync(["-LanDemo", "-SafeStop", "-Apply", "-ConfirmStop"], cancellationToken).ConfigureAwait(false);
    }

    public async Task<NodeManagerStatus> PreviewSafeStopNodeAsync(CancellationToken cancellationToken)
    {
        return await RunAsync(["-LanDemo", "-SafeStop", "-DryRun"], cancellationToken).ConfigureAwait(false);
    }

    public async Task<string[]> GetRecentLogsAsync(CancellationToken cancellationToken)
    {
        var arguments = new List<string>
        {
            "-NoLogo", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", installation.ManagerScript,
            "-Logs", "-Json",
        };
        var result = await processRunner.RunAsync(new ProcessRequest(
            installation.PowerShellExecutable,
            arguments,
            installation.RepositoryRoot,
            DefaultTimeout), cancellationToken).ConfigureAwait(false);

        if (result.TimedOut)
        {
            throw new TimeoutException("Recent logs could not be read before the three-minute timeout.");
        }

        if (result.ExitCode != 0)
        {
            var detail = SecretRedactor.Redact(result.StandardError).Trim();
            throw new InvalidOperationException(string.IsNullOrWhiteSpace(detail)
                ? "Recent Node logs could not be read. Review safe diagnostics."
                : detail[..Math.Min(detail.Length, 600)]);
        }

        try
        {
            using var document = JsonDocument.Parse(result.StandardOutput);
            if (!document.RootElement.TryGetProperty("Known", out var known) || known.ValueKind != JsonValueKind.True
                || !document.RootElement.TryGetProperty("Lines", out var lines) || lines.ValueKind != JsonValueKind.Array)
            {
                throw new InvalidOperationException("Recent Node logs are not available.");
            }

            return lines.EnumerateArray()
                .Where(line => line.ValueKind == JsonValueKind.String)
                .Select(line => SecretRedactor.Redact(line.GetString()))
                .ToArray();
        }
        catch (JsonException)
        {
            throw new InvalidOperationException("The Network Manager returned an invalid log document.");
        }
    }

    private async Task<NodeManagerStatus> RunAsync(IReadOnlyList<string> operationArguments, CancellationToken cancellationToken)
    {
        var arguments = new List<string>
        {
            "-NoLogo", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", installation.ManagerScript,
        };
        arguments.AddRange(operationArguments);
        arguments.Add("-Json");

        ProcessResult result;
        try
        {
            result = await processRunner.RunAsync(new ProcessRequest(
                installation.PowerShellExecutable,
                arguments,
                installation.RepositoryRoot,
                DefaultTimeout), cancellationToken).ConfigureAwait(false);
        }
        catch (OperationCanceledException)
        {
            throw;
        }
        catch (ProcessExecutionException exception)
        {
            throw new InvalidOperationException(SecretRedactor.Redact(exception.Message));
        }

        if (result.TimedOut)
        {
            throw new TimeoutException("The Network Manager did not finish before its three-minute timeout.");
        }

        if (result.ExitCode != 0)
        {
            var detail = SecretRedactor.Redact(result.StandardError).Trim();
            if (detail.Length > 600)
            {
                detail = detail[..600] + "…";
            }

            throw new InvalidOperationException(string.IsNullOrWhiteSpace(detail)
                ? "The Network Manager could not complete this operation. Review safe diagnostics."
                : detail);
        }

        try
        {
            return NodeManagerStatus.Parse(result.StandardOutput);
        }
        catch (JsonException)
        {
            throw new InvalidOperationException("The Network Manager returned an invalid status document. No result was treated as healthy.");
        }
    }

    private static string ToCliValue(NetworkMode mode) => mode switch
    {
        NetworkMode.PhoneHotspot => "Phone Hotspot",
        NetworkMode.LaptopHotspot => "Laptop Hotspot",
        NetworkMode.AutoDetect => "Auto Detect",
        _ => throw new ArgumentOutOfRangeException(nameof(mode)),
    };

    private static void ValidateLanAdapter(NetworkAdapterStatus adapter)
    {
        ArgumentNullException.ThrowIfNull(adapter);
        if (!adapter.IsUp || adapter.InterfaceIndex < 1 || !IsPrivateIpv4(adapter.Address))
        {
            throw new InvalidOperationException("Select an active network adapter with a private IPv4 address.");
        }
    }

    private static bool IsPrivateIpv4(string address)
    {
        if (!System.Net.IPAddress.TryParse(address, out var parsed)
            || parsed.AddressFamily != System.Net.Sockets.AddressFamily.InterNetwork)
        {
            return false;
        }

        var bytes = parsed.GetAddressBytes();
        return bytes[0] == 10
            || (bytes[0] == 172 && bytes[1] is >= 16 and <= 31)
            || (bytes[0] == 192 && bytes[1] == 168);
    }
}
