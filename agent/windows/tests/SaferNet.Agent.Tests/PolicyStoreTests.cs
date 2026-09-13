using System.Collections.Immutable;
using Microsoft.Extensions.Options;
using SaferNet.Agent;
using Xunit;

namespace SaferNet.Agent.Tests;

public sealed class PolicyStoreTests
{
    [Fact]
    public async Task CachedPolicyBlocksExactDomainsAndSubdomains()
    {
        await WithStore(async store =>
        {
            await store.ReplaceAsync(Policy(blocked: ["blocked.example"]), CancellationToken.None);

            Assert.True(store.IsBlocked("blocked.example"));
            Assert.True(store.IsBlocked("www.blocked.example"));
            Assert.False(store.IsBlocked("example.org"));
        });
    }

    [Fact]
    public async Task AnApprovedExceptionOutranksAParentDomainBlock()
    {
        await WithStore(async store =>
        {
            await store.ReplaceAsync(
                Policy(blocked: ["example.com"], allowed: ["lessons.example.com"]),
                CancellationToken.None);

            Assert.False(store.IsBlocked("lessons.example.com"));
            Assert.False(store.IsBlocked("maths.lessons.example.com"));
            Assert.True(store.IsBlocked("adverts.example.com"));
            Assert.True(store.IsBlocked("example.com"));
        });
    }

    [Fact]
    public async Task APolicyWrittenToDiskIsEnforcedAfterAReload()
    {
        var directory = TemporaryDirectory();
        try
        {
            var options = Options.Create(new AgentOptions { DataDirectory = directory, ServiceToken = "test", ManagedDeviceId = 1 });
            await new PolicyStore(options).ReplaceAsync(
                Policy(revision: 42, blocked: ["blocked.example"], allowed: ["ok.blocked.example"]),
                CancellationToken.None);

            var reloaded = new PolicyStore(options);
            await reloaded.LoadAsync(CancellationToken.None);

            Assert.Equal(42L, reloaded.Current.Revision);
            Assert.True(reloaded.IsBlocked("BLOCKED.example"));
            Assert.False(reloaded.IsBlocked("OK.blocked.example"));
        }
        finally
        {
            if (Directory.Exists(directory)) Directory.Delete(directory, true);
        }
    }

    [Fact]
    public async Task AnEmptyStoreLoadsWithoutACacheAndBlocksNothing()
    {
        await WithStore(async store =>
        {
            await store.LoadAsync(CancellationToken.None);

            Assert.Equal(0L, store.Current.Revision);
            Assert.False(store.IsBlocked("anything.example"));
        });
    }

    private static FilterPolicy Policy(long revision = 1, string[]? blocked = null, string[]? allowed = null) => new(
        revision,
        (blocked ?? []).ToImmutableHashSet(StringComparer.OrdinalIgnoreCase),
        (allowed ?? []).ToImmutableHashSet(StringComparer.OrdinalIgnoreCase),
        DateTimeOffset.UtcNow);

    private static string TemporaryDirectory() => Path.Combine(Path.GetTempPath(), "safernet-agent-" + Guid.NewGuid());

    private static async Task WithStore(Func<PolicyStore, Task> assertions)
    {
        var directory = TemporaryDirectory();
        try
        {
            await assertions(new PolicyStore(Options.Create(
                new AgentOptions { DataDirectory = directory, ServiceToken = "test", ManagedDeviceId = 1 })));
        }
        finally
        {
            if (Directory.Exists(directory)) Directory.Delete(directory, true);
        }
    }
}
