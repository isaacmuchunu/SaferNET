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
    public int PolicyRefreshSeconds { get; init; } = 300;
    public int HeartbeatSeconds { get; init; } = 60;
    public string DataDirectory { get; init; } = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SaferNET");
}
