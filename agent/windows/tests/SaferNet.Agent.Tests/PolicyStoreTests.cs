using Microsoft.Extensions.Options;
using SaferNet.Agent;
using Xunit;

namespace SaferNet.Agent.Tests;

public sealed class PolicyStoreTests
{
    [Fact]
    public async Task CachedPolicyBlocksExactDomainsAndSubdomains()
    {
        var directory = Path.Combine(Path.GetTempPath(), "safernet-agent-" + Guid.NewGuid());
        try
        {
            var store = new PolicyStore(Options.Create(new AgentOptions { DataDirectory = directory, ServiceToken = "test", ManagedDeviceId = 1 }));
            await store.ReplaceAsync(new FilterPolicy(7, System.Collections.Immutable.ImmutableHashSet.Create(StringComparer.OrdinalIgnoreCase, "blocked.example"), DateTimeOffset.UtcNow), CancellationToken.None);
            Assert.True(store.IsBlocked("blocked.example"));
            Assert.True(store.IsBlocked("www.blocked.example"));
            Assert.False(store.IsBlocked("example.org"));
        }
        finally
        {
            if (Directory.Exists(directory)) Directory.Delete(directory, true);
        }
    }
}
