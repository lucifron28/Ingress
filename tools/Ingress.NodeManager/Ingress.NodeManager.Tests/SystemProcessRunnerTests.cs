using Ingress.NodeManager.Core.Execution;

namespace Ingress.NodeManager.Tests;

public sealed class SystemProcessRunnerTests
{
    [Fact]
    public async Task PassesUntrustedTextAsLiteralArgumentInsteadOfShellCode()
    {
        if (!OperatingSystem.IsWindows())
        {
            return;
        }

        var root = Path.Combine(Path.GetTempPath(), "ingress-node-manager-test-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(root);
        try
        {
            var scriptPath = Path.Combine(root, "echo-argument.ps1");
            var resultPath = Path.Combine(root, "result.txt");
            const string payload = "safe'; Set-Content -Path injected.txt compromised; #";
            await File.WriteAllTextAsync(scriptPath,
                "param([string]$Path, [string]$Value)\n[IO.File]::WriteAllText($Path, $Value)\n");
            var powershell = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System),
                "WindowsPowerShell", "v1.0", "powershell.exe");

            var result = await new SystemProcessRunner().RunAsync(new ProcessRequest(
                powershell,
                ["-NoProfile", "-NonInteractive", "-File", scriptPath, resultPath, payload],
                root,
                TimeSpan.FromSeconds(10)),
                CancellationToken.None);

            Assert.Equal(0, result.ExitCode);
            Assert.Equal(payload, await File.ReadAllTextAsync(resultPath));
            Assert.False(File.Exists(Path.Combine(root, "injected.txt")));
        }
        finally
        {
            Directory.Delete(root, recursive: true);
        }
    }

    [Fact]
    public async Task CancellingKillsTheChildProcessAndPropagatesCancellation()
    {
        if (!OperatingSystem.IsWindows())
        {
            return;
        }

        using var cancellation = new CancellationTokenSource(TimeSpan.FromMilliseconds(250));
        var powershell = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System),
            "WindowsPowerShell", "v1.0", "powershell.exe");

        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => new SystemProcessRunner().RunAsync(
            new ProcessRequest(powershell, ["-NoProfile", "-NonInteractive", "-Command", "Start-Sleep -Seconds 30"],
                Environment.CurrentDirectory, TimeSpan.FromSeconds(10)),
            cancellation.Token));
    }

    [Fact]
    public async Task LongProcessExecutionReturnsControlWithoutBlockingTheCaller()
    {
        if (!OperatingSystem.IsWindows())
        {
            return;
        }

        var powershell = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System),
            "WindowsPowerShell", "v1.0", "powershell.exe");
        using var cancellation = new CancellationTokenSource(TimeSpan.FromMilliseconds(250));
        var operation = new SystemProcessRunner().RunAsync(
            new ProcessRequest(powershell, ["-NoProfile", "-NonInteractive", "-Command", "Start-Sleep -Seconds 30"],
                Environment.CurrentDirectory, TimeSpan.FromSeconds(10)),
            cancellation.Token);

        Assert.False(operation.IsCompleted);
        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => operation);
    }

    [Fact]
    public async Task TimeoutIsReportedWithoutReturningChildOutput()
    {
        if (!OperatingSystem.IsWindows())
        {
            return;
        }

        var powershell = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System),
            "WindowsPowerShell", "v1.0", "powershell.exe");
        var result = await new SystemProcessRunner().RunAsync(
            new ProcessRequest(powershell, ["-NoProfile", "-NonInteractive", "-Command", "Write-Output secret; Start-Sleep -Seconds 30"],
                Environment.CurrentDirectory, TimeSpan.FromMilliseconds(250)),
            CancellationToken.None);

        Assert.True(result.TimedOut);
        Assert.Empty(result.StandardOutput);
        Assert.Empty(result.StandardError);
    }
}
