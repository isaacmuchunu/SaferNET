using System.Collections.Immutable;
using System.Text.Json;
using Microsoft.Extensions.Options;

namespace SaferNet.Agent;

public sealed record FilterPolicy(
    long Revision,
    ImmutableHashSet<string> BlockedDomains,
    ImmutableHashSet<string> AllowedDomains,
    DateTimeOffset SyncedAt)
{
    private static readonly ImmutableHashSet<string> NoDomains =
        ImmutableHashSet<string>.Empty.WithComparer(StringComparer.OrdinalIgnoreCase);

    public static FilterPolicy Empty { get; } = new(0, NoDomains, NoDomains, DateTimeOffset.MinValue);

    /// <summary>
    /// A policy read back from disk carries whatever sets that file happened to
    /// have — a cache written before allowlists existed has none, and JSON
    /// deserialisation loses the case-insensitive comparer either way.
    /// </summary>
    public FilterPolicy Normalised() => this with
    {
        BlockedDomains = (BlockedDomains ?? NoDomains).WithComparer(StringComparer.OrdinalIgnoreCase),
        AllowedDomains = (AllowedDomains ?? NoDomains).WithComparer(StringComparer.OrdinalIgnoreCase),
    };
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
        var policy = await JsonSerializer.DeserializeAsync<FilterPolicy>(stream, cancellationToken: cancellationToken);
        _current = policy?.Normalised() ?? FilterPolicy.Empty;
    }

    public async Task ReplaceAsync(FilterPolicy policy, CancellationToken cancellationToken)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(_path)!);
        var temporary = _path + ".tmp";
        await using (var stream = File.Create(temporary))
            await JsonSerializer.SerializeAsync(stream, policy, cancellationToken: cancellationToken);
        File.Move(temporary, _path, true);
        _current = policy.Normalised();
    }

    /// <summary>
    /// A domain is blocked when it, or a parent of it, is on the blocklist and
    /// nothing nearer the leaf allows it. The allowlist wins at every level, so
    /// an approved exception for lessons.example.com survives a list entry for
    /// example.com.
    /// </summary>
    public bool IsBlocked(string domain)
    {
        var policy = _current;
        var candidate = domain.TrimEnd('.');

        while (candidate.Length > 0)
        {
            if (policy.AllowedDomains.Contains(candidate)) return false;
            if (policy.BlockedDomains.Contains(candidate)) return true;
            var separator = candidate.IndexOf('.');
            if (separator < 0) break;
            candidate = candidate[(separator + 1)..];
        }

        return false;
    }
}
