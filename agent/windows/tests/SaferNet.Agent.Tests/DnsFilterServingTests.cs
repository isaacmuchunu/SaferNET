using System.Buffers.Binary;
using System.Collections.Immutable;
using System.Net;
using System.Net.Sockets;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.Extensions.Options;
using SaferNet.Agent;
using Xunit;

namespace SaferNet.Agent.Tests;

/// <summary>
/// Exercises the resolver over real sockets on an ephemeral port. These are the
/// tests that say whether a device is actually being filtered, rather than
/// whether the parsing helpers agree with themselves.
/// </summary>
public sealed class DnsFilterServingTests
{
    private const int NameError = 3;

    private const int ServerFailure = 2;

    [Fact]
    public async Task ABlockedNameIsAnsweredNxdomainOverUdp()
    {
        await Serving(blocked: "blocked.example", async (port, _) =>
        {
            var answer = await AskOverUdpAsync(port, "blocked.example");

            Assert.Equal(NameError, answer[3] & 0x0f);
            Assert.Equal(0x80, answer[2] & 0x80);
        });
    }

    [Fact]
    public async Task ASubdomainOfABlockedNameIsAnsweredNxdomain()
    {
        await Serving(blocked: "blocked.example", async (port, _) =>
        {
            var answer = await AskOverUdpAsync(port, "adverts.blocked.example");

            Assert.Equal(NameError, answer[3] & 0x0f);
        });
    }

    [Fact]
    public async Task ABlockedNameIsAlsoAnsweredOverTcp()
    {
        await Serving(blocked: "blocked.example", async (port, _) =>
        {
            var answer = await AskOverTcpAsync(port, "blocked.example");

            Assert.Equal(NameError, answer[3] & 0x0f);
        });
    }

    [Fact]
    public async Task AnUnreachableUpstreamIsAnsweredServfailRatherThanDropped()
    {
        // Nothing is listening on this upstream, so resolution can only fail.
        await Serving(blocked: "blocked.example", upstream: "127.0.0.2", async (port, _) =>
        {
            var answer = await AskOverUdpAsync(port, "permitted.example", timeout: TimeSpan.FromSeconds(20));

            Assert.Equal(ServerFailure, answer[3] & 0x0f);
        });
    }

    [Fact]
    public async Task AMalformedQueryIsAnsweredRatherThanKillingTheListener()
    {
        await Serving(blocked: "blocked.example", async (port, filter) =>
        {
            using var client = new UdpClient();
            await client.SendAsync(new byte[] { 0x00, 0x01, 0x02 }, 3, new IPEndPoint(IPAddress.Loopback, port));

            // The listener survives it, and still answers a real question.
            var answer = await AskOverUdpAsync(port, "blocked.example");

            Assert.Equal(NameError, answer[3] & 0x0f);
            Assert.True(filter.IsListening);
        });
    }

    [Fact]
    public async Task TheFilterReportsTheTransportsItBound()
    {
        await Serving(blocked: "blocked.example", (port, filter) =>
        {
            Assert.Contains(filter.BoundEndpoints, endpoint => endpoint.StartsWith("udp/127.0.0.1"));
            Assert.Contains(filter.BoundEndpoints, endpoint => endpoint.StartsWith("tcp/127.0.0.1"));

            return Task.CompletedTask;
        });
    }

    [Fact]
    public async Task APortConflictFaultsTheFilterInsteadOfRunningWithoutAResolver()
    {
        var port = FreePort();
        using var occupier = new UdpClient(new IPEndPoint(IPAddress.Loopback, port));

        var filter = Filter(port, "1.1.1.1", "blocked.example");
        using var cancellation = new CancellationTokenSource();

        var run = filter.RunAsync(cancellation.Token);

        await Assert.ThrowsAsync<SocketException>(() => run);
        await Assert.ThrowsAsync<SocketException>(() => filter.Ready);
        Assert.False(filter.IsListening);
    }

    [Fact]
    public async Task CancellingStopsServingAndReleasesThePort()
    {
        var port = FreePort();
        var filter = Filter(port, "1.1.1.1", "blocked.example");
        using var cancellation = new CancellationTokenSource();

        var run = filter.RunAsync(cancellation.Token);
        await filter.Ready;

        await cancellation.CancelAsync();
        await run;

        Assert.False(filter.IsListening);

        // The port is free again, which it would not be if a listener leaked.
        using var rebound = new UdpClient(new IPEndPoint(IPAddress.Loopback, port));
    }

    [Fact]
    public async Task AnIdleTcpConnectionIsClosedRatherThanHeldOpenForever()
    {
        await Serving(blocked: "blocked.example", async (port, _) =>
        {
            using var connection = new TcpClient();
            await connection.ConnectAsync(IPAddress.Loopback, port);
            await using var stream = connection.GetStream();

            // Never sends a question. Socket.ReceiveTimeout does not apply to the
            // async reads the filter uses, so this is the bound that actually holds.
            var read = new byte[1];
            using var cancellation = new CancellationTokenSource(TimeSpan.FromSeconds(15));
            var count = await stream.ReadAsync(read, cancellation.Token);

            Assert.Equal(0, count);
        });
    }

    [Fact]
    public async Task ASecondQuestionIsServedOnTheSameConnection()
    {
        await Serving(blocked: "blocked.example", async (port, _) =>
        {
            using var cancellation = new CancellationTokenSource(TimeSpan.FromSeconds(10));
            using var client = new TcpClient();
            await client.ConnectAsync(IPAddress.Loopback, port, cancellation.Token);
            await using var stream = client.GetStream();

            foreach (var _unused in Enumerable.Range(0, 2))
            {
                await WriteFramedAsync(stream, BuildQuery("blocked.example"), cancellation.Token);
                var answer = await ReadFramedAsync(stream, cancellation.Token);

                Assert.Equal(NameError, answer[3] & 0x0f);
            }
        });
    }

    /// <summary>
    /// Runs the filter on an ephemeral port for the duration of one test.
    ///
    /// FreePort can only report a port that was free a moment ago — it has to
    /// release it before the filter can bind it, and anything on the machine may
    /// take it in between. A collision here says nothing about the code under
    /// test, so it is retried on a fresh port rather than failing the run.
    /// </summary>
    private static async Task Serving(string blocked, Func<int, DnsFilter, Task> assertions, string upstream = "1.1.1.1")
    {
        for (var attempt = 1; ; attempt++)
        {
            var port = FreePort();
            var filter = Filter(port, upstream, blocked);
            using var cancellation = new CancellationTokenSource();
            var run = filter.RunAsync(cancellation.Token);

            try
            {
                await filter.Ready;
            }
            catch (SocketException exception) when (exception.SocketErrorCode == SocketError.AddressAlreadyInUse && attempt < 5)
            {
                await ObserveAsync(run);

                continue;
            }

            try
            {
                await assertions(port, filter);
            }
            finally
            {
                await cancellation.CancelAsync();
                await run;
            }

            return;
        }
    }

    /// <summary>Consumes a discarded run's fault so it is never unobserved.</summary>
    private static async Task ObserveAsync(Task run)
    {
        try
        {
            await run;
        }
        catch
        {
        }
    }

    private static Task Serving(string blocked, string upstream, Func<int, DnsFilter, Task> assertions)
        => Serving(blocked, assertions, upstream);

    private static DnsFilter Filter(int port, string upstream, string blocked)
    {
        var options = Options.Create(new AgentOptions
        {
            DataDirectory = Path.Combine(Path.GetTempPath(), "safernet-dns-" + Guid.NewGuid()),
            ServiceToken = "test",
            ManagedDeviceId = 1,
            DnsPort = port,
            UpstreamDns = upstream,
            UpstreamTimeoutSeconds = 2,
            TcpIdleSeconds = 2,
            // The suite cannot assume an IPv6 loopback, and IPv4 is what is asserted.
            ListenOnIpv6 = false,
            MaxConcurrentQueries = 8,
        });

        var policies = new PolicyStore(options, NullLogger<PolicyStore>.Instance);
        policies.ReplaceAsync(
            new FilterPolicy(1, ImmutableHashSet.Create(StringComparer.OrdinalIgnoreCase, blocked),
                ImmutableHashSet<string>.Empty.WithComparer(StringComparer.OrdinalIgnoreCase), DateTimeOffset.UtcNow),
            CancellationToken.None).GetAwaiter().GetResult();

        // Block reporting is fire-and-forget and its failure is only logged, so
        // an unreachable API does not affect what these tests assert.
        var api = new SaferNetApiClient(new HttpClient { BaseAddress = new Uri("http://127.0.0.1:1/") }, options);

        return new DnsFilter(policies, api, options, NullLogger<DnsFilter>.Instance);
    }

    /// <summary>
    /// A port free for both UDP and TCP.
    ///
    /// The two have separate port namespaces, so a UDP-only probe regularly
    /// returns a port already held by some TCP socket on the machine — which
    /// left the filter serving UDP alone and the TCP tests refusing connections.
    /// </summary>
    private static int FreePort()
    {
        for (var attempt = 0; ; attempt++)
        {
            using var probe = new UdpClient(new IPEndPoint(IPAddress.Loopback, 0));
            var port = ((IPEndPoint)probe.Client.LocalEndPoint!).Port;

            try
            {
                var reserved = new TcpListener(new IPEndPoint(IPAddress.Loopback, port));
                reserved.Start();
                reserved.Stop();

                return port;
            }
            catch (SocketException) when (attempt < 20)
            {
            }
        }
    }

    private static async Task<byte[]> AskOverUdpAsync(int port, string name, TimeSpan? timeout = null)
    {
        using var client = new UdpClient();
        using var cancellation = new CancellationTokenSource(timeout ?? TimeSpan.FromSeconds(10));

        var query = BuildQuery(name);
        await client.SendAsync(query, query.Length, new IPEndPoint(IPAddress.Loopback, port));

        return (await client.ReceiveAsync(cancellation.Token)).Buffer;
    }

    private static async Task<byte[]> AskOverTcpAsync(int port, string name)
    {
        using var cancellation = new CancellationTokenSource(TimeSpan.FromSeconds(10));
        using var client = new TcpClient();
        await client.ConnectAsync(IPAddress.Loopback, port, cancellation.Token);
        await using var stream = client.GetStream();

        await WriteFramedAsync(stream, BuildQuery(name), cancellation.Token);

        return await ReadFramedAsync(stream, cancellation.Token);
    }

    private static async Task WriteFramedAsync(Stream stream, byte[] message, CancellationToken cancellationToken)
    {
        var framed = new byte[2 + message.Length];
        BinaryPrimitives.WriteUInt16BigEndian(framed, (ushort)message.Length);
        message.CopyTo(framed, 2);

        await stream.WriteAsync(framed, cancellationToken);
    }

    private static async Task<byte[]> ReadFramedAsync(Stream stream, CancellationToken cancellationToken)
    {
        var prefix = new byte[2];
        await stream.ReadExactlyAsync(prefix, cancellationToken);
        var answer = new byte[BinaryPrimitives.ReadUInt16BigEndian(prefix)];
        await stream.ReadExactlyAsync(answer, cancellationToken);

        return answer;
    }

    private static byte[] BuildQuery(string name)
    {
        var message = new List<byte>
        {
            0x12, 0x34,
            0x01, 0x00,
            0x00, 0x01,
            0x00, 0x00,
            0x00, 0x00,
            0x00, 0x00,
        };

        foreach (var label in name.Split('.'))
        {
            message.Add((byte)label.Length);
            message.AddRange(System.Text.Encoding.ASCII.GetBytes(label));
        }

        message.Add(0x00);
        message.AddRange([0x00, 0x01]);
        message.AddRange([0x00, 0x01]);

        return [.. message];
    }
}
