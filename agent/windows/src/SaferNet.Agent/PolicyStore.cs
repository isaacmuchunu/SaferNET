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

public sealed class PolicyStore(IOptions<AgentOptions> options, ILogger<PolicyStore> logger)
{
    private readonly string _path = Path.Combine(options.Value.DataDirectory, "policy.json");
    private volatile FilterPolicy _current = FilterPolicy.Empty;
    public FilterPolicy Current => _current;

    /// <summary>
    /// Loads the cached policy, if there is a readable one.
    /// </summary>
    /// <remarks>
    /// An unreadable cache must never stop the agent starting. This runs before
    /// anything else in the worker, so throwing here would fail the service,
    /// Windows recovery would restart it, it would read the same broken file,
    /// and the machine would sit in a restart loop filtering nothing — with the
    /// cause buried in the event log. Discarding the file and starting empty
    /// costs one policy fetch and keeps the agent alive to make it.
    /// </remarks>
    public async Task LoadAsync(CancellationToken cancellationToken)
    {
        if (!File.Exists(_path)) return;

        try
        {
            await using var stream = File.OpenRead(_path);
            var policy = await JsonSerializer.DeserializeAsync<FilterPolicy>(stream, cancellationToken: cancellationToken);
            _current = policy?.Normalised() ?? FilterPolicy.Empty;
        }
        catch (Exception exception) when (exception is JsonException or IOException or UnauthorizedAccessException)
        {
            logger.LogError(exception, "The cached policy at {Path} is unreadable and has been discarded; the next cycle will fetch a fresh one", _path);

            _current = FilterPolicy.Empty;
            Discard();
        }
    }

    /// <summary>
    /// Removes a cache that could not be read, so the agent does not log the
    /// same failure on every restart. Failing to delete it is not fatal — the
    /// policy in memory is already empty.
    /// </summary>
    private void Discard()
    {
        try
        {
            File.Delete(_path);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            logger.LogWarning(exception, "Could not remove the unreadable policy cache at {Path}", _path);
        }
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
