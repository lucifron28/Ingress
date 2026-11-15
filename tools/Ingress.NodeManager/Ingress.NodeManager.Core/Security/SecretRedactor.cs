using System.Text.RegularExpressions;

namespace Ingress.NodeManager.Core.Security;

public static partial class SecretRedactor
{
    private static readonly string[] SensitiveKeys =
    [
        "APP_KEY", "DB_URL", "DB_PASSWORD", "INGRESS_NODE_SYNC_KEY", "INGRESS_AUTHORITY_PRIVATE_KEY",
        "INGRESS_NODE_PRIVATE_KEY", "INGRESS_NODE_TLS_PRIVATE_KEY", "FIREBASE_PRIVATE_KEY", "RESEND_API_KEY",
        "MAIL_PASSWORD", "PASSWORD", "ACCESS_TOKEN", "REFRESH_TOKEN", "PAIRING_TOKEN", "TICKET_CREDENTIAL",
    ];

    public static string Redact(string? value)
    {
        if (string.IsNullOrEmpty(value))
        {
            return value ?? string.Empty;
        }

        var redacted = AnsiEscapeRegex().Replace(value, string.Empty);
        redacted = PrivateKeyBlockRegex().Replace(redacted, "[REDACTED PRIVATE KEY]");
        redacted = CredentialUrlRegex().Replace(redacted, "$1[REDACTED]$2");
        redacted = JwtRegex().Replace(redacted, "[REDACTED TOKEN]");
        redacted = SensitiveLinkRegex().Replace(redacted, "[REDACTED LINK]");
        redacted = SensitiveQueryRegex().Replace(redacted, "$1[REDACTED]");
        redacted = SensitiveAssignmentRegex().Replace(redacted, "$1[REDACTED]");
        foreach (var key in SensitiveKeys)
        {
            var pattern = $@"(?im)(?<prefix>\b{Regex.Escape(key)}\b\s*[:=]\s*)(?<value>[^\s,;]+)";
            redacted = Regex.Replace(redacted, pattern, "${prefix}[REDACTED]", RegexOptions.CultureInvariant);
        }

        redacted = BearerRegex().Replace(redacted, "$1[REDACTED]");
        return redacted;
    }

    [GeneratedRegex(@"-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant)]
    private static partial Regex PrivateKeyBlockRegex();

    [GeneratedRegex(@"\x1B(?:\[[0-?]*[ -/]*[@-~]|\][^\x07]*(?:\x07|\x1B\\))", RegexOptions.CultureInvariant)]
    private static partial Regex AnsiEscapeRegex();

    [GeneratedRegex(@"(\b(?:postgres(?:ql)?|mysql)://[^:/@\s]+:)[^@/\s]+(@)", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant)]
    private static partial Regex CredentialUrlRegex();

    [GeneratedRegex(@"\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b", RegexOptions.CultureInvariant)]
    private static partial Regex JwtRegex();

    [GeneratedRegex("""https?://[^\s"'<>]*(?:/tickets?/|/pair(?:ing)?(?:/|\?)|/verify(?:/|\?))[^\s"'<>]*""", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant)]
    private static partial Regex SensitiveLinkRegex();

    [GeneratedRegex(@"([?&](?:access_token|refresh_token|token|code|credential|pairing_token|ticket_credential|verification_token)=)[^&#\s]+", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant)]
    private static partial Regex SensitiveQueryRegex();

    [GeneratedRegex("""(?im)(\b[A-Z0-9_]*(?:API[_-]?KEY|API[_-]?TOKEN|SECRET|PASSWORD|PRIVATE[_-]?KEY|CREDENTIALS?|TOKEN|_KEY)\b\s*[:=]\s*)("[^"]*"|'[^']*'|[^\s,;]+)""", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant)]
    private static partial Regex SensitiveAssignmentRegex();

    [GeneratedRegex(@"(\bBearer\s+)[A-Za-z0-9._~+/-]+=*", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant)]
    private static partial Regex BearerRegex();
}
