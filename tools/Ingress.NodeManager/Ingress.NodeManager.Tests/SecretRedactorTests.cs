using Ingress.NodeManager.Core.Security;
using Ingress.NodeManager.Core.Status;

namespace Ingress.NodeManager.Tests;

public sealed class SecretRedactorTests
{
    [Fact]
    public void RedactsKnownSecretAssignmentsBearerTokensAndSensitiveLinks()
    {
        const string input = "DB_URL=postgresql://event:db-secret@db.example/node\n"
            + "APP_KEY=base64:app-secret\n"
            + "INGRESS_NODE_SYNC_KEY=sync-secret\n"
            + "FIREBASE_API_KEY=firebase-api-secret\n"
            + "PUBLIC_ENDPOINT=https://node.example/api?token=query-secret\n"
            + "Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature\n"
            + "https://node.example/tickets/123/pass?code=ticket-secret";

        var safe = SecretRedactor.Redact(input);

        Assert.DoesNotContain("db-secret", safe, StringComparison.Ordinal);
        Assert.DoesNotContain("app-secret", safe, StringComparison.Ordinal);
        Assert.DoesNotContain("sync-secret", safe, StringComparison.Ordinal);
        Assert.DoesNotContain("firebase-api-secret", safe, StringComparison.Ordinal);
        Assert.DoesNotContain("query-secret", safe, StringComparison.Ordinal);
        Assert.DoesNotContain("payload", safe, StringComparison.Ordinal);
        Assert.DoesNotContain("ticket-secret", safe, StringComparison.Ordinal);
        Assert.Contains("[REDACTED]", safe, StringComparison.Ordinal);
    }

    [Fact]
    public void RemovesAnsiFormattingFromOperatorErrors()
    {
        var safe = SecretRedactor.Redact("\u001b[31mERROR\u001b[0m: Docker is unavailable");

        Assert.Equal("ERROR: Docker is unavailable", safe);
    }

    [Fact]
    public void SafeLogFormatterOmitsTicketAndPairingLines()
    {
        var safe = SafeLogFormatter.Format(
        [
            "INFO Node health check passed",
            "GET /tickets/redeem?code=ticket-secret",
            "Generated pairing credential=pairing-secret",
            "DB_PASSWORD=db-secret",
        ]);

        Assert.Contains("Node health check passed", safe, StringComparison.Ordinal);
        Assert.DoesNotContain("ticket-secret", safe, StringComparison.Ordinal);
        Assert.DoesNotContain("pairing-secret", safe, StringComparison.Ordinal);
        Assert.DoesNotContain("db-secret", safe, StringComparison.Ordinal);
        Assert.Contains("[sensitive log line omitted]", safe, StringComparison.Ordinal);
    }

    [Fact]
    public void SafeDiagnosticsExcludeUrlsWithUserInformationAndSecretQueryValues()
    {
        var diagnostics = SafeDiagnosticsBuilder.Build(new NodeManagerStatus
        {
            NodeUrl = "https://user:private@node.example/api?token=secret",
            ErrorMessage = "DB_PASSWORD=database-secret",
        });

        Assert.Contains("https://node.example", diagnostics, StringComparison.Ordinal);
        Assert.DoesNotContain("private", diagnostics, StringComparison.Ordinal);
        Assert.DoesNotContain("secret", diagnostics, StringComparison.Ordinal);
        Assert.DoesNotContain("database-secret", diagnostics, StringComparison.Ordinal);
    }
}
