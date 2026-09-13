using Microsoft.Extensions.Options;

namespace SaferNet.Agent;

public sealed class AgentWorker(PolicyStore policies, SaferNetApiClient api, DnsFilter dns, IOptions<AgentOptions> options, ILogger<AgentWorker> logger) : BackgroundService
{
    private readonly AgentOptions _options = options.Value;

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        await policies.LoadAsync(stoppingToken);
        var dnsTask = dns.RunAsync(stoppingToken);
        var nextPolicy = DateTimeOffset.MinValue;

        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                if (DateTimeOffset.UtcNow >= nextPolicy)
                {
                    var policy = await api.FetchPolicyAsync(stoppingToken);
                    await policies.ReplaceAsync(policy, stoppingToken);
                    nextPolicy = DateTimeOffset.UtcNow.AddSeconds(_options.PolicyRefreshSeconds);
                    logger.LogInformation("Applied policy revision {Revision} with {Count} blocked domains", policy.Revision, policy.BlockedDomains.Count);
                }
                await api.SendHeartbeatAsync(policies.Current, stoppingToken);
            }
            catch (Exception exception) when (exception is not OperationCanceledException)
            {
                logger.LogError(exception, "Agent cycle failed; cached policy revision {Revision} remains active", policies.Current.Revision);
            }

            await Task.Delay(TimeSpan.FromSeconds(_options.HeartbeatSeconds), stoppingToken);
        }

        await dnsTask;
    }
}
