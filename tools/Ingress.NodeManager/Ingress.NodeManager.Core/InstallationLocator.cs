using Ingress.NodeManager.Core.Networking;

namespace Ingress.NodeManager.Core;

public static class InstallationLocator
{
    public static NodeManagerInstallation? TryLocate(
        string applicationBaseDirectory,
        string? pathValue = null,
        string? programFiles = null,
        string? systemDirectory = null)
    {
        var managerScript = FindManagerScript(applicationBaseDirectory);
        var powershell = FindExecutable("pwsh.exe", pathValue ?? Environment.GetEnvironmentVariable("PATH"));
        powershell ??= FindPowerShellAt(Path.Combine(programFiles ?? Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "PowerShell", "7", "pwsh.exe"));
        powershell ??= FindPowerShellAt(Path.Combine(systemDirectory ?? Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe"));

        if (managerScript is null || powershell is null)
        {
            return null;
        }

        var repositoryRoot = Directory.GetParent(Path.GetDirectoryName(managerScript)!)!.FullName;
        return new NodeManagerInstallation(powershell, managerScript, repositoryRoot);
    }

    private static string? FindManagerScript(string baseDirectory)
    {
        var current = new DirectoryInfo(Path.GetFullPath(baseDirectory));
        while (current is not null)
        {
            var candidate = Path.Combine(current.FullName, "scripts", "IngressNetworkManager.ps1");
            if (File.Exists(candidate))
            {
                return candidate;
            }

            current = current.Parent;
        }

        return null;
    }

    private static string? FindExecutable(string fileName, string? pathValue)
    {
        if (string.IsNullOrWhiteSpace(pathValue))
        {
            return null;
        }

        foreach (var directory in pathValue.Split(Path.PathSeparator, StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
        {
            var candidate = Path.Combine(directory.Trim('"'), fileName);
            if (File.Exists(candidate))
            {
                return Path.GetFullPath(candidate);
            }
        }

        return null;
    }

    private static string? FindPowerShellAt(string candidate) => File.Exists(candidate) ? Path.GetFullPath(candidate) : null;
}
