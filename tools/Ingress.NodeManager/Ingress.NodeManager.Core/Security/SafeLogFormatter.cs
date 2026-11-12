namespace Ingress.NodeManager.Core.Security;

public static class SafeLogFormatter
{
    private static readonly string[] UnsafeMarkers =
    [
        "ticket", "pairing", "credential", "verification", "password", "secret", "token", "authorization",
        "request body", "payload", "/verify", "/tickets/", "/pairing",
    ];

    public static string Format(IEnumerable<string> lines, int maximumLines = 150)
    {
        ArgumentNullException.ThrowIfNull(lines);
        if (maximumLines is < 1 or > 500)
        {
            throw new ArgumentOutOfRangeException(nameof(maximumLines));
        }

        var safeLines = new List<string>();
        foreach (var rawLine in lines.TakeLast(maximumLines))
        {
            if (UnsafeMarkers.Any(marker => rawLine.Contains(marker, StringComparison.OrdinalIgnoreCase)))
            {
                safeLines.Add("[sensitive log line omitted]");
                continue;
            }

            var safeLine = SecretRedactor.Redact(rawLine);
            if (!string.IsNullOrWhiteSpace(safeLine))
            {
                safeLines.Add(safeLine);
            }
        }

        return safeLines.Count == 0 ? "No safe log lines are available." : string.Join(Environment.NewLine, safeLines);
    }
}
