using System.Threading.Channels;
using Microsoft.Extensions.Options;

namespace SaferNet.Agent;

/// <summary>A bounded, single-consumer reporting queue that never delays DNS.</summary>
public sealed class BlockedDomainReporter : BackgroundService
{
    private readonly Channel<BlockReport> _queue;
    private readonly SaferNetApiClient _api;
    private readonly ILogger<BlockedDomainReporter> _logger;
    private long _dropped;

    public long DroppedReports => Interlocked.Read(ref _dropped);
    public int QueuedReports => _queue.Reader.Count;

    public BlockedDomainReporter(SaferNetApiClient api, IOptions<AgentOptions> options, ILogger<BlockedDomainReporter> logger)
    {
        _api = api;
        _logger = logger;
        _queue = Channel.CreateBounded<BlockReport>(new BoundedChannelOptions(options.Value.MaxQueuedBlockReports)
        {
            FullMode = BoundedChannelFullMode.Wait,
            SingleReader = true,
            SingleWriter = false,
        });
    }

    public void Enqueue(string domain)
    {
        if (!_queue.Writer.TryWrite(new(Guid.NewGuid(), domain, DateTimeOffset.UtcNow)))
            Interlocked.Increment(ref _dropped);
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        await foreach (var report in _queue.Reader.ReadAllAsync(stoppingToken))
        {
            for (var attempt = 0; attempt < 3; attempt++)
            {
                try
                {
                    using var deadline = CancellationTokenSource.CreateLinkedTokenSource(stoppingToken);
                    deadline.CancelAfter(TimeSpan.FromSeconds(15));
                    await _api.ReportBlockedDomainAsync(report.Domain, deadline.Token, report.Id, report.OccurredAt);
                    break;
                }
                catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
                {
                    return;
                }
                catch (Exception exception)
                {
                    if (attempt == 2)
                    {
                        Interlocked.Increment(ref _dropped);
                        _logger.LogWarning(exception, "DNS block report failed after bounded retries");
                        break;
                    }
                    await Task.Delay(TimeSpan.FromSeconds(1 << attempt), stoppingToken);
                }
            }
        }
    }

    private sealed record BlockReport(Guid Id, string Domain, DateTimeOffset OccurredAt);
}
