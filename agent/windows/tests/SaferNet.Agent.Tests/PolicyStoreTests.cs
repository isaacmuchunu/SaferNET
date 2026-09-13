using System.Collections.Immutable;
using Microsoft.Extensions.Logging.Abstractions;
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
            await new PolicyStore(options, NullLogger<PolicyStore>.Instance).ReplaceAsync(
                Policy(revision: 42, blocked: ["blocked.example"], allowed: ["ok.blocked.example"]),
                CancellationToken.None);

            var reloaded = new PolicyStore(options, NullLogger<PolicyStore>.Instance);
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

    [Fact]
    public async Task ATruncatedCacheLoadsEmptyRatherThanStoppingTheAgent()
    {
        await WithCacheContaining(
            file => File.WriteAllText(file, File.ReadAllText(file)[..12]),
            async store =>
            {
                // Must not throw: LoadAsync runs before anything else in the
                // worker, so a throw here is a service that never starts.
                await store.LoadAsync(CancellationToken.None);

                Assert.Equal(0L, store.Current.Revision);
                Assert.False(store.IsBlocked("blocked.example"));
            });
    }

    [Fact]
    public async Task ACacheOfGarbageLoadsEmptyAndIsDiscarded()
    {
        string? cachePath = null;

        await WithCacheContaining(
            file =>
            {
                cachePath = file;
                File.WriteAllBytes(file, [0x00, 0x01, 0x02, 0xff, 0xfe]);
            },
            async store =>
            {
                await store.LoadAsync(CancellationToken.None);

                Assert.Equal(0L, store.Current.Revision);
            });

        // Removed, so the agent does not re-read and re-log it every restart.
        Assert.False(File.Exists(cachePath));
    }

    [Fact]
    public async Task AnAgentWithADiscardedCacheStillAppliesTheNextPolicy()
    {
        await WithCacheContaining(
            file => File.WriteAllText(file, "{ this is not json"),
            async store =>
            {
                await store.LoadAsync(CancellationToken.None);
                await store.ReplaceAsync(Policy(revision: 9, blocked: ["blocked.example"]), CancellationToken.None);

                Assert.Equal(9L, store.Current.Revision);
                Assert.True(store.IsBlocked("blocked.example"));
            });
    }

    /// <summary>
    /// Writes a valid cache, lets the caller corrupt it, then hands a fresh
    /// store over that directory — the shape of a real restart after the file
    /// on disk went bad.
    /// </summary>
    private static async Task WithCacheContaining(Action<string> corrupt, Func<PolicyStore, Task> assertions)
    {
        var directory = TemporaryDirectory();

        try
        {
            var options = Options.Create(new AgentOptions { DataDirectory = directory, ServiceToken = "test", ManagedDeviceId = 1 });
            await new PolicyStore(options, NullLogger<PolicyStore>.Instance).ReplaceAsync(
                Policy(revision: 7, blocked: ["blocked.example"]),
                CancellationToken.None);

            corrupt(Path.Combine(directory, "policy.json"));

            await assertions(new PolicyStore(options, NullLogger<PolicyStore>.Instance));
        }
        finally
        {
            if (Directory.Exists(directory)) Directory.Delete(directory, true);
        }
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
            await assertions(new PolicyStore(
                Options.Create(new AgentOptions { DataDirectory = directory, ServiceToken = "test", ManagedDeviceId = 1 }),
                NullLogger<PolicyStore>.Instance));
        }
        finally
        {
            if (Directory.Exists(directory)) Directory.Delete(directory, true);
        }
    }
}
