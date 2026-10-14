using System.Net;
using System.Text.Json;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using SaferNet.Agent;
using Xunit;

namespace SaferNet.Agent.Tests;

public sealed class BlockedDomainReporterTests
{
    [Fact]
    public void AFullQueueDropsReportsWithoutStartingNetworkRequests()
    {
        using var handler = new TestHandler((_, _) => throw new InvalidOperationException("No request should start"));
        using var client = new HttpClient(handler) { BaseAddress = new Uri("https://api.test/") };
        using var reporter = Reporter(client, capacity: 4);
        Parallel.For(0, 1000, _ => reporter.Enqueue("blocked.example"));
        Assert.Equal(4, reporter.QueuedReports);
        Assert.Equal(996, reporter.DroppedReports);
    }

    [Fact]
    public async Task ASlowApiHasOnlyOneRequestInFlightAndShutdownCancelsIt()
    {
        var started = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var canceled = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var requests = 0;
        using var handler = new TestHandler(async (_, token) =>
        {
            Interlocked.Increment(ref requests);
            started.TrySetResult();
            try { await Task.Delay(Timeout.Infinite, token); }
            catch (OperationCanceledException) { canceled.TrySetResult(); throw; }
            return new HttpResponseMessage(HttpStatusCode.OK);
        });
        using var client = new HttpClient(handler) { BaseAddress = new Uri("https://api.test/") };
        using var reporter = Reporter(client, capacity: 4);
        await reporter.StartAsync(TestContext.Current.CancellationToken);
        reporter.Enqueue("first.example");
        await started.Task.WaitAsync(TimeSpan.FromSeconds(5), TestContext.Current.CancellationToken);
        Parallel.For(0, 1000, _ => reporter.Enqueue("blocked.example"));
        Assert.Equal(1, Volatile.Read(ref requests));
        Assert.Equal(4, reporter.QueuedReports);
        Assert.Equal(996, reporter.DroppedReports);
        await reporter.StopAsync(TestContext.Current.CancellationToken);
        Assert.True(canceled.Task.IsCompleted);
    }

    [Fact]
    public async Task RetriesKeepTheEventIdentityAndStopAfterThreeFailures()
    {
        var payloads = new List<string>();
        var third = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        using var handler = new TestHandler(async (request, token) =>
        {
            payloads.Add(await request.Content!.ReadAsStringAsync(token));
            if (payloads.Count == 3) third.TrySetResult();
            return new HttpResponseMessage(HttpStatusCode.ServiceUnavailable);
        });
        using var client = new HttpClient(handler) { BaseAddress = new Uri("https://api.test/") };
        using var reporter = Reporter(client, capacity: 4);
        await reporter.StartAsync(TestContext.Current.CancellationToken);
        reporter.Enqueue("blocked.example");
        await third.Task.WaitAsync(TimeSpan.FromSeconds(10), TestContext.Current.CancellationToken);
        using var completed = new CancellationTokenSource(TimeSpan.FromSeconds(5));
        while (reporter.DroppedReports == 0) await Task.Delay(10, completed.Token);
        await reporter.StopAsync(TestContext.Current.CancellationToken);
        Assert.Equal(3, payloads.Count);
        Assert.Equal(payloads[0], payloads[1]);
        Assert.Equal(payloads[0], payloads[2]);
        using var body = JsonDocument.Parse(payloads[0]);
        Assert.Equal("blocked.example", body.RootElement.GetProperty("metadata").GetProperty("domain").GetString());
        Assert.Equal(1, reporter.DroppedReports);
    }

    private static BlockedDomainReporter Reporter(HttpClient client, int capacity)
    {
        var options = Options.Create(new AgentOptions { ServiceToken = "test", ManagedDeviceId = 1, MaxQueuedBlockReports = capacity });
        return new(new SaferNetApiClient(client, options), options, NullLogger<BlockedDomainReporter>.Instance);
    }

    private sealed class TestHandler(Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> send) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) => send(request, cancellationToken);
    }
}
