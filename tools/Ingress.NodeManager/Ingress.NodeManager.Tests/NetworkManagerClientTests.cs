using Ingress.NodeManager.Core.Execution;
using Ingress.NodeManager.Core.Networking;
using Ingress.NodeManager.Core.Status;

namespace Ingress.NodeManager.Tests;

public sealed class NetworkManagerClientTests
{
    [Fact]
    public async Task StatusUsesExplicitPowerShellFileAndStructuredArguments()
    {
        var runner = new RecordingProcessRunner(StatusJson);
        var client = CreateClient(runner);

        await client.GetStatusAsync(CancellationToken.None);

        Assert.Equal("C:\\Program Files\\PowerShell\\7\\pwsh.exe", runner.LastRequest!.Executable);
        Assert.Contains("-File", runner.LastRequest.Arguments);
        Assert.Contains("scripts\\IngressNetworkManager.ps1", string.Join('\\', runner.LastRequest.Arguments), StringComparison.OrdinalIgnoreCase);
        Assert.Contains("-Json", runner.LastRequest.Arguments);
        Assert.Contains("-Status", runner.LastRequest.Arguments);
        Assert.Contains("-LanDemo", runner.LastRequest.Arguments);
    }

    [Fact]
    public async Task LanDemoPreviewUsesSelectedInterfaceAndPrivateAddressAsSeparateArguments()
    {
        var runner = new RecordingProcessRunner(StatusJson);
        var client = CreateClient(runner);
        var adapter = new NetworkAdapterStatus("Wi-Fi", "Wireless", "LAN", "192.168.1.30", 24, "Private", true)
        {
            InterfaceIndex = 8,
        };

        await client.PreviewLanDemoNetworkChangeAsync(adapter, CancellationToken.None);

        var args = runner.LastRequest!.Arguments;
        Assert.Contains("-LanDemo", args);
        Assert.Equal("8", args[Array.IndexOf(args.ToArray(), "-InterfaceIndex") + 1]);
        Assert.Equal("192.168.1.30", args[Array.IndexOf(args.ToArray(), "-BindAddress") + 1]);
        Assert.Contains("-DryRun", args);
    }

    [Fact]
    public async Task LanDemoClientRejectsPublicAddressBeforeLaunchingPowerShell()
    {
        var runner = new RecordingProcessRunner(StatusJson);
        var client = CreateClient(runner);
        var adapter = new NetworkAdapterStatus("Wi-Fi", "Wireless", "LAN", "8.8.8.8", 24, "Public", true)
        {
            InterfaceIndex = 8,
        };

        await Assert.ThrowsAsync<InvalidOperationException>(() => client.PreviewLanDemoNetworkChangeAsync(adapter, CancellationToken.None));
        Assert.Equal(0, runner.Calls);
    }

    [Fact]
    public async Task NetworkModeIsPassedAsOneArgumentAndApplyNeedsExplicitConfirmation()
    {
        var runner = new RecordingProcessRunner(StatusJson);
        var client = CreateClient(runner);

        await client.PreviewNetworkChangeAsync(NetworkMode.LaptopHotspot, CancellationToken.None);

        var modeIndex = Array.IndexOf(runner.LastRequest!.Arguments.ToArray(), "-Mode");
        Assert.Equal("Laptop Hotspot", runner.LastRequest.Arguments[modeIndex + 1]);
        var callsBeforeUnconfirmedApply = runner.Calls;

        await Assert.ThrowsAsync<InvalidOperationException>(() => client.ApplyNetworkChangeAsync(
            NetworkMode.LaptopHotspot,
            confirmationAccepted: false,
            CancellationToken.None));

        Assert.Equal(callsBeforeUnconfirmedApply, runner.Calls);
    }

    [Fact]
    public async Task ConfirmedNetworkApplyPassesNonInteractiveConfirmationAndJsonAsSeparateArguments()
    {
        var runner = new RecordingProcessRunner(StatusJson);
        var client = CreateClient(runner);

        await client.ApplyNetworkChangeAsync(NetworkMode.PhoneHotspot, confirmationAccepted: true, CancellationToken.None);

        var arguments = runner.LastRequest!.Arguments;
        Assert.Contains("-NonInteractive", arguments);
        Assert.Contains("-Apply", arguments);
        Assert.Contains("-ConfirmApply", arguments);
        Assert.Contains("-Json", arguments);
        var modeIndex = Array.IndexOf(arguments.ToArray(), "-Mode");
        Assert.Equal("Phone Hotspot", arguments[modeIndex + 1]);
    }

    [Fact]
    public async Task ConfirmedStartPassesNonInteractiveConfirmationAndJsonAsSeparateArguments()
    {
        var runner = new RecordingProcessRunner(StatusJson);
        var client = CreateClient(runner);

        await client.StartNodeAsync(NetworkMode.PhoneHotspot, confirmationAccepted: true, CancellationToken.None);

        var arguments = runner.LastRequest!.Arguments;
        Assert.Contains("-NonInteractive", arguments);
        Assert.Contains("-Apply", arguments);
        Assert.Contains("-ConfirmApply", arguments);
        Assert.Contains("-Json", arguments);
        var modeIndex = Array.IndexOf(arguments.ToArray(), "-Mode");
        Assert.Equal("Phone Hotspot", arguments[modeIndex + 1]);
    }

    [Fact]
    public async Task ApplyDoesNotProceedWhenPreviewReportsAdmissionBlocker()
    {
        var runner = new RecordingProcessRunner("""
            {
              "SchemaVersion": 1,
              "CanApplyNetworkChange": false,
              "Blockers": ["An event is currently accepting admissions."]
            }
            """);
        var client = CreateClient(runner);

        var preview = await client.PreviewNetworkChangeAsync(NetworkMode.PhoneHotspot, CancellationToken.None);

        Assert.False(preview.CanApplyNetworkChange);
        Assert.Contains(preview.Blockers, blocker => blocker.Contains("admissions", StringComparison.OrdinalIgnoreCase));
    }

    [Fact]
    public async Task SafeStopPreviewUsesDedicatedReadOnlyOperation()
    {
        var runner = new RecordingProcessRunner(StatusJson);
        var client = CreateClient(runner);

        await client.PreviewSafeStopNodeAsync(CancellationToken.None);

        Assert.Contains("-SafeStop", runner.LastRequest!.Arguments);
        Assert.Contains("-DryRun", runner.LastRequest.Arguments);
        Assert.DoesNotContain("-Apply", runner.LastRequest.Arguments);
        Assert.DoesNotContain("-ConfirmStop", runner.LastRequest.Arguments);
    }

    [Fact]
    public async Task ConfirmedSafeStopStillPassesThroughTheFailClosedManager()
    {
        var runner = new RecordingProcessRunner(StatusJson);
        var client = CreateClient(runner);

        await client.SafeStopNodeAsync(confirmationAccepted: true, CancellationToken.None);

        Assert.Contains("-SafeStop", runner.LastRequest!.Arguments);
        Assert.Contains("-Apply", runner.LastRequest.Arguments);
        Assert.Contains("-ConfirmStop", runner.LastRequest.Arguments);
    }

    [Fact]
    public async Task CommandRunnerCancellationIsPropagated()
    {
        using var cancellation = new CancellationTokenSource();
        var runner = new RecordingProcessRunner(StatusJson, cancellation);
        var client = CreateClient(runner);

        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => client.GetStatusAsync(cancellation.Token));
    }

    [Fact]
    public async Task ProcessFailureDoesNotExposeCredentialsOrTicketLinks()
    {
        var runner = new RecordingProcessRunner(
            output: "",
            exitCode: 1,
            standardError: "DB_PASSWORD=db-secret https://node.example/tickets/11/pass?code=ticket-secret");
        var client = CreateClient(runner);

        var error = await Assert.ThrowsAsync<InvalidOperationException>(() => client.GetStatusAsync(CancellationToken.None));

        Assert.DoesNotContain("db-secret", error.Message, StringComparison.Ordinal);
        Assert.DoesNotContain("ticket-secret", error.Message, StringComparison.Ordinal);
        Assert.DoesNotContain("/tickets/", error.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task InvalidStatusDocumentIsRejectedWithoutAssumingHealthyState()
    {
        var client = CreateClient(new RecordingProcessRunner("not-json"));

        var error = await Assert.ThrowsAsync<InvalidOperationException>(() => client.GetStatusAsync(CancellationToken.None));

        Assert.Contains("invalid status", error.Message, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task StartRequiresConfirmationAndCannotUseAutoDetect()
    {
        var runner = new RecordingProcessRunner(StatusJson);
        var client = CreateClient(runner);

        await Assert.ThrowsAsync<InvalidOperationException>(() =>
            client.StartNodeAsync(NetworkMode.PhoneHotspot, confirmationAccepted: false, CancellationToken.None));
        await Assert.ThrowsAsync<InvalidOperationException>(() =>
            client.StartNodeAsync(NetworkMode.AutoDetect, confirmationAccepted: true, CancellationToken.None));

        Assert.Equal(0, runner.Calls);
    }

    [Fact]
    public async Task SafeStopRequiresConfirmationBeforeRunningTheManager()
    {
        var runner = new RecordingProcessRunner(StatusJson);
        var client = CreateClient(runner);

        await Assert.ThrowsAsync<InvalidOperationException>(() =>
            client.SafeStopNodeAsync(confirmationAccepted: false, CancellationToken.None));

        Assert.Equal(0, runner.Calls);
    }

    [Fact]
    public async Task LongRunningProcessReturnsControlUntilTheAsyncOperationCompletes()
    {
        var runner = new RecordingProcessRunner(StatusJson, delay: TimeSpan.FromMilliseconds(250));
        var client = CreateClient(runner);

        var operation = client.GetStatusAsync(CancellationToken.None);
        var callerContinued = true;

        Assert.True(callerContinued);
        Assert.False(operation.IsCompleted);
        await operation;
    }

    private static NetworkManagerClient CreateClient(RecordingProcessRunner runner) => new(
        runner,
        new NodeManagerInstallation(
            "C:\\Program Files\\PowerShell\\7\\pwsh.exe",
            "C:\\Ingress\\scripts\\IngressNetworkManager.ps1",
            "C:\\Ingress"));

    private const string StatusJson = """
        {
          "SchemaVersion": 1,
          "NetworkMode": "Phone Hotspot",
          "NodeUrl": "https://192.168.55.158",
          "CanApplyNetworkChange": true,
          "Blockers": [],
          "Warnings": []
        }
        """;

    private sealed class RecordingProcessRunner(
        string output,
        CancellationTokenSource? cancellation = null,
        int exitCode = 0,
        string standardError = "",
        TimeSpan? delay = null) : IProcessRunner
    {
        public ProcessRequest? LastRequest { get; private set; }
        public int Calls { get; private set; }

        public async Task<ProcessResult> RunAsync(ProcessRequest request, CancellationToken cancellationToken)
        {
            LastRequest = request;
            Calls++;
            cancellation?.Cancel();
            cancellationToken.ThrowIfCancellationRequested();
            if (delay is not null)
            {
                await Task.Delay(delay.Value, cancellationToken);
            }
            return new ProcessResult(exitCode, output, standardError, false);
        }
    }
}
