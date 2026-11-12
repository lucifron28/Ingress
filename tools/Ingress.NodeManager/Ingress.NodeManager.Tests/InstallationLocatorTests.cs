using Ingress.NodeManager.Core;

namespace Ingress.NodeManager.Tests;

public sealed class InstallationLocatorTests
{
    [Fact]
    public void FindsPackagedManagerAndPowerShellWithoutAssumingRepositoryPath()
    {
        var root = Path.Combine(Path.GetTempPath(), "ingress-locator-" + Guid.NewGuid().ToString("N"));
        var app = Path.Combine(root, "publish");
        var scripts = Path.Combine(app, "scripts");
        var powershell = Path.Combine(root, "PowerShell", "7", "pwsh.exe");
        Directory.CreateDirectory(scripts);
        Directory.CreateDirectory(Path.GetDirectoryName(powershell)!);
        try
        {
            File.WriteAllText(Path.Combine(scripts, "IngressNetworkManager.ps1"), "");
            File.WriteAllText(powershell, "");

            var installation = InstallationLocator.TryLocate(app, pathValue: "", programFiles: root);

            Assert.NotNull(installation);
            Assert.Equal(Path.GetFullPath(powershell), installation.PowerShellExecutable);
            Assert.Equal(Path.GetFullPath(Path.Combine(scripts, "IngressNetworkManager.ps1")), installation.ManagerScript);
            Assert.Equal(Path.GetFullPath(app), installation.RepositoryRoot);
        }
        finally
        {
            Directory.Delete(root, recursive: true);
        }
    }

    [Fact]
    public void MissingPowerShellDoesNotInventHealthyInstallation()
    {
        var root = Path.Combine(Path.GetTempPath(), "ingress-locator-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(Path.Combine(root, "scripts"));
        try
        {
            File.WriteAllText(Path.Combine(root, "scripts", "IngressNetworkManager.ps1"), "");

            var installation = InstallationLocator.TryLocate(root, pathValue: "", programFiles: root, systemDirectory: root);

            Assert.Null(installation);
        }
        finally
        {
            Directory.Delete(root, recursive: true);
        }
    }
}
