using Microsoft.Extensions.Options;

namespace SaferNet.Agent;

/// <summary>
/// Supervises the two things the agent does at once: serving DNS, and keeping
/// its policy and heartbeat current.
///
/// They are supervised together on purpose. A resolver that dies while the
/// heartbeat loop carries on is the worst outcome available — nothing is being
/// filtered and the console says the device is fine — so either task ending
/// stops the other, and a listener fault takes the service down for the
/// recovery configured against it.
/// </summary>
public sealed class AgentWorker(PolicyStore policies, SaferNetApiClient api, DnsFilter dns, IOptions<AgentOptions> options, ILogger<AgentWorker> logger) : BackgroundService
{
    private readonly AgentOptions _options = options.Value;

    private DateTimeOffset? _policyInstalledAt;

    private string? _lastError;

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        await policies.LoadAsync(stoppingToken);

        using var shutdown = CancellationTokenSource.CreateLinkedTokenSource(stoppingToken);
        var resolver = dns.RunAsync(shutdown.Token);
        var cycle = RunCyclesAsync(shutdown.Token);

        var first = await Task.WhenAny(resolver, cycle);

        if (!stoppingToken.IsCancellationRequested)
        {
            logger.LogError(
                first.Exception,
                "The {Task} task ended; stopping the agent so its configured recovery can restart it",
                first == resolver ? "DNS resolver" : "policy");
        }

        await shutdown.CancelAsync();
        await DrainAsync(resolver, cycle, stoppingToken);

        // Rethrows the real fault, rather than reporting a clean stop.
        await first;
    }

    /// <summary>
    /// Lets the sibling task finish unwinding before the fault is surfaced, so
    /// sockets are closed and no work is left running behind the service.
    /// </summary>
    private static async Task DrainAsync(Task resolver, Task cycle, CancellationToken stoppingToken)
    {
        foreach (var task in new[] { resolver, cycle })
        {
            try
            {
                await task;
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
            }
            catch
            {
                // Reported by the caller, which rethrows whichever ended first.
            }
        }
    }

    /// <summary>
    /// Refreshes policy on its own schedule and heartbeats on the shorter one.
    /// A failed cycle is recorded rather than swallowed: the next heartbeat
    /// reports it, and the cached policy keeps filtering meanwhile.
    /// </summary>
    private async Task RunCyclesAsync(CancellationToken cancellationToken)
    {
        // Adapter DNS may already point here, so nothing else should start
        // until the resolver is actually answering.
        await dns.Ready.WaitAsync(cancellationToken);

        var nextPolicy = DateTimeOffset.MinValue;

        while (!cancellationToken.IsCancellationRequested)
        {
            try
            {
                if (DateTimeOffset.UtcNow >= nextPolicy)
                {
                    var policy = await api.FetchPolicyAsync(cancellationToken);
                    await policies.ReplaceAsync(policy, cancellationToken);
                    _policyInstalledAt = DateTimeOffset.UtcNow;
                    nextPolicy = DateTimeOffset.UtcNow.AddSeconds(_options.PolicyRefreshSeconds);
                    logger.LogInformation(
                        "Applied policy revision {Revision} with {Count} blocked and {Allowed} allowed domains",
                        policy.Revision, policy.BlockedDomains.Count, policy.AllowedDomains.Count);
                }

                _lastError = null;
            }
            catch (Exception exception) when (exception is not OperationCanceledException)
            {
                _lastError = exception.Message;
                logger.LogError(exception, "Policy refresh failed; cached policy revision {Revision} remains active", policies.Current.Revision);
            }

            await HeartbeatAsync(cancellationToken);
            await Task.Delay(TimeSpan.FromSeconds(_options.HeartbeatSeconds), cancellationToken);
        }
    }

    private async Task HeartbeatAsync(CancellationToken cancellationToken)
    {
        var health = AgentHealth.Evaluate(dns, policies.Current, _policyInstalledAt, _options, _lastError, DateTimeOffset.UtcNow);

        try
        {
            await api.SendHeartbeatAsync(policies.Current, health, cancellationToken);
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            _lastError = exception.Message;
            logger.LogWarning(exception, "Could not send the heartbeat");
        }
    }
}
