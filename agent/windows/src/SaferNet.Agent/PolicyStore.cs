using System.Collections.Immutable;
using System.Text.Json;
using Microsoft.Extensions.Options;

namespace SaferNet.Agent;

public sealed record FilterPolicy(int Revision, ImmutableHashSet<string> BlockedDomains, DateTimeOffset SyncedAt)
{
    public static FilterPolicy Empty { get; } = new(0, ImmutableHashSet<string>.Empty.WithComparer(StringComparer.OrdinalIgnoreCase), DateTimeOffset.MinValue);
}

public sealed class PolicyStore(IOptions<AgentOptions> options)
{
    private readonly string _path = Path.Combine(options.Value.DataDirectory, "policy.json");
    private volatile FilterPolicy _current = FilterPolicy.Empty;
    public FilterPolicy Current => _current;

    public async Task LoadAsync(CancellationToken cancellationToken)
    {
        if (!File.Exists(_path)) return;
        await using var stream = File.OpenRead(_path);
        _current = await JsonSerializer.DeserializeAsync<FilterPolicy>(stream, cancellationToken: cancellationToken) ?? FilterPolicy.Empty;
    }

    public async Task ReplaceAsync(FilterPolicy policy, CancellationToken cancellationToken)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(_path)!);
        var temporary = _path + ".tmp";
        await using (var stream = File.Create(temporary))
            await JsonSerializer.SerializeAsync(stream, policy, cancellationToken: cancellationToken);
        File.Move(temporary, _path, true);
        _current = policy;
    }

    public bool IsBlocked(string domain)
    {
        var candidate = domain.TrimEnd('.');
        while (candidate.Length > 0)
        {
            if (_current.BlockedDomains.Contains(candidate)) return true;
            var separator = candidate.IndexOf('.');
            if (separator < 0) break;
            candidate = candidate[(separator + 1)..];
        }
        return false;
    }
}
