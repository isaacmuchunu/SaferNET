using System.ComponentModel.DataAnnotations;

namespace SaferNet.Agent;

public sealed class AgentOptions
{
    [Required, Url]
    public string ApiBaseUrl { get; init; } = "https://safernet.test/api/v1";

    [Required]
    public string ServiceToken { get; init; } = string.Empty;

    [Range(1, int.MaxValue)]
    public int ManagedDeviceId { get; init; }

    [Required]
    public string WorkstationId { get; init; } = Environment.MachineName;

    public string UpstreamDns { get; init; } = "1.1.1.1";

    public int DnsPort { get; init; } = 53;

    /// <summary>
    /// Serve IPv6 loopback as well as IPv4. A host with IPv6 disabled is
    /// supported — the bind is skipped and reported — but on a host that has it,
    /// leaving it unserved means those queries bypass the filter entirely.
    /// </summary>
    public bool ListenOnIpv6 { get; init; } = true;

    /// <summary>
    /// How long an upstream has to answer before the query is failed. Answering
    /// SERVFAIL at a known moment beats leaving the client to time out.
    /// </summary>
    [Range(1, 60)]
    public int UpstreamTimeoutSeconds { get; init; } = 4;

    /// <summary>
    /// How long an idle TCP connection is held open for a resolver's next
    /// question before it is closed.
    /// </summary>
    [Range(1, 300)]
    public int TcpIdleSeconds { get; init; } = 10;

    /// <summary>
    /// The ceiling on queries being resolved at once. A burst queues in the
    /// socket buffer rather than becoming unbounded concurrent tasks.
    /// </summary>
    [Range(1, 10_000)]
    public int MaxConcurrentQueries { get; init; } = 256;

    public int PolicyRefreshSeconds { get; init; } = 300;

    public int HeartbeatSeconds { get; init; } = 60;

    /// <summary>
    /// How stale a cached policy may be before the agent stops calling itself
    /// healthy. Filtering continues on the cached policy either way.
    /// </summary>
    [Range(60, 86_400)]
    public int PolicyStaleSeconds { get; init; } = 1_800;

    public string DataDirectory { get; init; } = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SaferNET");
}
