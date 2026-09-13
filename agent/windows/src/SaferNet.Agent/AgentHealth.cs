namespace SaferNet.Agent;

/// <summary>
/// What the agent is actually doing, as opposed to the fact that it managed to
/// send a heartbeat.
///
/// Reaching the API proves the process is alive. It proves nothing about
/// whether names are being filtered, so the two are reported separately: a
/// resolver that is not listening is <c>offline</c> however reliably the
/// heartbeat arrives.
/// </summary>
public sealed record AgentHealth(
    string Status,
    bool ResolverListening,
    IReadOnlyList<string> Endpoints,
    long AppliedRevision,
    DateTimeOffset? PolicyInstalledAt,
    string? LastError)
{
    public const string Healthy = "healthy";

    public const string Degraded = "degraded";

    public const string Offline = "offline";

    /// <summary>
    /// Derives health from the resolver's readiness and the age of the policy
    /// it is enforcing. Healthy means both: a listener that is answering, and a
    /// policy recent enough to trust.
    /// </summary>
    public static AgentHealth Evaluate(
        DnsFilter dns,
        FilterPolicy policy,
        DateTimeOffset? policyInstalledAt,
        AgentOptions options,
        string? lastError,
        DateTimeOffset now)
    {
        var stale = policyInstalledAt is null
            || now - policyInstalledAt.Value > TimeSpan.FromSeconds(options.PolicyStaleSeconds);

        var status = (dns.IsListening, policy.Revision, stale, lastError) switch
        {
            (false, _, _, _) => Offline,
            (_, 0, _, _) => Degraded,
            (_, _, true, _) => Degraded,
            (_, _, _, not null) => Degraded,
            _ => Healthy,
        };

        return new(
            status,
            dns.IsListening,
            dns.BoundEndpoints,
            policy.Revision,
            policyInstalledAt,
            lastError ?? dns.LastFailure);
    }
}
