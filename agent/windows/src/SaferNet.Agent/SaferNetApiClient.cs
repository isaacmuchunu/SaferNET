using System.Collections.Immutable;
using System.Net.Http.Json;
using System.Text.Json.Serialization;
using Microsoft.Extensions.Options;

namespace SaferNet.Agent;

public sealed class SaferNetApiClient(HttpClient client, IOptions<AgentOptions> options)
{
    private readonly AgentOptions _options = options.Value;

    public async Task<FilterPolicy> FetchPolicyAsync(CancellationToken cancellationToken)
    {
        var response = await client.GetFromJsonAsync<PolicyResponse>("agent/policy", cancellationToken)
            ?? throw new InvalidOperationException("The policy endpoint returned an empty response.");
        return new(
            response.Revision,
            (response.BlockedDomains ?? []).ToImmutableHashSet(StringComparer.OrdinalIgnoreCase),
            (response.AllowedDomains ?? []).ToImmutableHashSet(StringComparer.OrdinalIgnoreCase),
            response.SyncedAt);
    }

    /// <summary>
    /// Reports contact and enforcement separately: <c>policy_synced_at</c> is
    /// when a policy was last actually installed, not when this heartbeat was
    /// sent, and the status comes from the resolver rather than being asserted.
    /// </summary>
    public async Task SendHeartbeatAsync(FilterPolicy policy, AgentHealth health, CancellationToken cancellationToken)
    {
        using var response = await client.PostAsJsonAsync("protection-components", new
        {
            device_id = _options.ManagedDeviceId,
            type = "endpoint_agent",
            identifier = _options.WorkstationId,
            version = "1.0.0",
            health_status = health.Status,
            policy_synced_at = health.PolicyInstalledAt,
            metadata = new
            {
                blocked_domains = policy.BlockedDomains.Count,
                allowed_domains = policy.AllowedDomains.Count,
                applied_revision = health.AppliedRevision,
                resolver_listening = health.ResolverListening,
                dns_endpoints = health.Endpoints,
                last_error = health.LastError,
                machine = Environment.MachineName,
            },
        }, cancellationToken);
        response.EnsureSuccessStatusCode();
    }

    public async Task ReportBlockedDomainAsync(string domain, CancellationToken cancellationToken)
    {
        using var response = await client.PostAsJsonAsync("security-events", new
        {
            event_uuid = Guid.NewGuid(),
            device_id = _options.ManagedDeviceId,
            type = "dns_domain_blocked",
            severity = "medium",
            description = $"Endpoint DNS filter blocked {domain}",
            response = "NXDOMAIN",
            occurred_at = DateTimeOffset.UtcNow,
            metadata = new { domain, workstation_id = _options.WorkstationId },
        }, cancellationToken);
        response.EnsureSuccessStatusCode();
    }

    private sealed record PolicyResponse(
        [property: JsonPropertyName("revision")] long Revision,
        [property: JsonPropertyName("blocked_domains")] string[]? BlockedDomains,
        [property: JsonPropertyName("allowed_domains")] string[]? AllowedDomains,
        [property: JsonPropertyName("synced_at")] DateTimeOffset SyncedAt);
}
