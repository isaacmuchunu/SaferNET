using System.Buffers.Binary;
using System.Net;
using System.Net.Sockets;
using Microsoft.Extensions.Options;

namespace SaferNet.Agent;

/// <summary>
/// The endpoint resolver every managed adapter points at.
///
/// It serves the transports a stub resolver actually uses: UDP and TCP, on both
/// IPv4 and IPv6 loopback. TCP is not optional — a resolver that receives a
/// truncated answer retries the same question over TCP, and a filter that
/// cannot answer it simply stops resolving those names.
///
/// Two failures are never silent. A listener that cannot bind faults
/// <see cref="RunAsync"/> rather than leaving the service running without a
/// resolver, and an upstream that times out is answered SERVFAIL rather than
/// dropped, so the client fails fast instead of waiting out its own timeout.
/// </summary>
public sealed class DnsFilter(PolicyStore policies, SaferNetApiClient api, IOptions<AgentOptions> options, ILogger<DnsFilter> logger)
{
    private const int HeaderLength = 12;

    private const int LengthPrefixBytes = 2;

    private const int MaxMessageLength = 65535;

    private const int MinimumUdpPayload = 512;

    private const int OptRecordType = 41;

    private readonly AgentOptions _options = options.Value;

    private readonly SemaphoreSlim _inFlight = new(Math.Max(1, options.Value.MaxConcurrentQueries));

    private readonly TaskCompletionSource _ready = new(TaskCreationOptions.RunContinuationsAsynchronously);

    private volatile bool _listening;

    private volatile string? _lastFailure;

    /// <summary>Whether the resolver is bound and serving right now.</summary>
    public bool IsListening => _listening;

    /// <summary>The transports that bound, for the heartbeat to report.</summary>
    public IReadOnlyList<string> BoundEndpoints { get; private set; } = [];

    /// <summary>The most recent resolution failure, or null while healthy.</summary>
    public string? LastFailure => _lastFailure;

    /// <summary>
    /// Completes once every listener is bound, or faults if binding failed.
    /// Startup waits on it so adapter DNS is never redirected to a resolver
    /// that is not actually answering.
    /// </summary>
    public Task Ready => _ready.Task;

    /// <summary>
    /// Serves until cancelled. Faults if a listener cannot bind or a serving
    /// loop dies, which is the signal the supervisor needs: a filtering agent
    /// without a resolver is a failed agent, not a degraded one.
    /// </summary>
    public async Task RunAsync(CancellationToken cancellationToken)
    {
        var udp = new List<UdpClient>();
        var tcp = new List<TcpListener>();

        try
        {
            Bind(udp, tcp);
        }
        catch (Exception exception)
        {
            Dispose(udp, tcp);
            _ready.TrySetException(exception);
            throw;
        }

        BoundEndpoints = [.. udp.Select(client => $"udp/{client.Client.LocalEndPoint}"),
                          .. tcp.Select(listener => $"tcp/{listener.LocalEndpoint}")];
        _listening = true;
        _ready.TrySetResult();
        logger.LogInformation("DNS filter listening on {Endpoints}", string.Join(", ", BoundEndpoints));

        // Every loop runs on a token this method owns, so the first one to end can
        // stop the rest. Awaiting them all together would mask a fault: a dead UDP
        // loop would sit unobserved behind TCP listeners still blocked on accept,
        // and the agent would keep reporting a resolver it no longer has.
        using var serving = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);

        var loops = new List<Task>();
        loops.AddRange(udp.Select(client => ServeUdpAsync(client, serving.Token)));
        loops.AddRange(tcp.Select(listener => ServeTcpAsync(listener, serving.Token)));

        try
        {
            var first = await Task.WhenAny(loops);

            _listening = false;
            await serving.CancelAsync();

            foreach (var loop in loops)
            {
                try
                {
                    await loop;
                }
                catch (OperationCanceledException)
                {
                }
                catch
                {
                    // Whichever ended first carries the fault; the rest are
                    // drained so their sockets close before it is rethrown.
                }
            }

            // Rethrows a genuine listener fault, or returns cleanly on shutdown.
            await first;
        }
        finally
        {
            _listening = false;
            Dispose(udp, tcp);
        }
    }

    /// <summary>
    /// Binds every loopback transport.
    ///
    /// IPv4 UDP and IPv4 TCP are both mandatory. UDP alone is not a working
    /// resolver: a stub that receives a truncated answer retries the same
    /// question over TCP, and one that cannot be answered there simply stops
    /// resolving those names. Failing the bind surfaces that as a dead resolver
    /// rather than a quietly half-working one.
    ///
    /// IPv6 is best effort, because a host with IPv6 disabled is a supported
    /// configuration — but a failed bind is logged and absent from
    /// <see cref="BoundEndpoints"/> rather than assumed.
    /// </summary>
    private void Bind(List<UdpClient> udp, List<TcpListener> tcp)
    {
        udp.Add(new UdpClient(new IPEndPoint(IPAddress.Loopback, _options.DnsPort)));
        tcp.Add(Listen(IPAddress.Loopback));

        if (_options.ListenOnIpv6 && Socket.OSSupportsIPv6)
        {
            TryBind(() => udp.Add(new UdpClient(new IPEndPoint(IPAddress.IPv6Loopback, _options.DnsPort))), "udp/[::1]");
            TryBind(() => tcp.Add(Listen(IPAddress.IPv6Loopback)), "tcp/[::1]");
        }
    }

    private TcpListener Listen(IPAddress address)
    {
        var listener = new TcpListener(new IPEndPoint(address, _options.DnsPort));
        listener.Start();

        return listener;
    }

    private void TryBind(Action bind, string description)
    {
        try
        {
            bind();
        }
        catch (SocketException exception)
        {
            logger.LogWarning(exception, "Could not bind {Transport}; that transport is not being filtered", description);
        }
    }

    private static void Dispose(List<UdpClient> udp, List<TcpListener> tcp)
    {
        foreach (var client in udp)
        {
            client.Dispose();
        }

        foreach (var listener in tcp)
        {
            listener.Dispose();
        }
    }

    private async Task ServeUdpAsync(UdpClient listener, CancellationToken cancellationToken)
    {
        while (!cancellationToken.IsCancellationRequested)
        {
            UdpReceiveResult request;

            try
            {
                // Admission happens before the receive, so a burst queues in the
                // socket buffer instead of becoming unbounded tasks.
                await _inFlight.WaitAsync(cancellationToken);
                request = await listener.ReceiveAsync(cancellationToken);
            }
            catch (OperationCanceledException)
            {
                _inFlight.Release();

                return;
            }
            catch (SocketException exception) when (exception.SocketErrorCode == SocketError.ConnectionReset)
            {
                // Windows surfaces the ICMP port-unreachable from an earlier reply
                // on the *next* receive. One client closed its socket; the listener
                // is fine, and treating it as fatal would take DNS down for the
                // whole machine.
                _inFlight.Release();

                continue;
            }
            catch
            {
                _inFlight.Release();

                throw;
            }

            _ = AnswerUdpAsync(listener, request, cancellationToken);
        }
    }

    private async Task AnswerUdpAsync(UdpClient listener, UdpReceiveResult request, CancellationToken cancellationToken)
    {
        try
        {
            var answer = await ResolveAsync(request.Buffer, viaTcp: false, cancellationToken);
            await listener.SendAsync(answer, answer.Length, request.RemoteEndPoint);
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            RecordFailure(exception, "Could not answer a UDP query");
        }
        finally
        {
            _inFlight.Release();
        }
    }

    private async Task ServeTcpAsync(TcpListener listener, CancellationToken cancellationToken)
    {
        while (!cancellationToken.IsCancellationRequested)
        {
            TcpClient connection;

            try
            {
                connection = await listener.AcceptTcpClientAsync(cancellationToken);
            }
            catch (OperationCanceledException)
            {
                return;
            }

            _ = AnswerTcpAsync(connection, cancellationToken);
        }
    }

    /// <summary>
    /// Serves one TCP connection, which a resolver may reuse for several
    /// questions before closing it.
    /// </summary>
    private async Task AnswerTcpAsync(TcpClient connection, CancellationToken cancellationToken)
    {
        using (connection)
        {
            try
            {
                await using var stream = connection.GetStream();

                while (!cancellationToken.IsCancellationRequested)
                {
                    // Socket.ReceiveTimeout governs synchronous receives only and is
                    // ignored by the async reads below, so the idle bound has to be a
                    // cancellation deadline. Without one, a local process could open
                    // connections, send nothing, and hold sockets open indefinitely.
                    using var idle = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
                    idle.CancelAfter(TimeSpan.FromSeconds(_options.TcpIdleSeconds));

                    byte[]? query;

                    try
                    {
                        query = await ReadTcpMessageAsync(stream, idle.Token);
                    }
                    catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
                    {
                        // Idle past its welcome; the resolver may open a new one.
                        return;
                    }

                    if (query is null)
                    {
                        return;
                    }

                    await _inFlight.WaitAsync(cancellationToken);

                    try
                    {
                        var answer = await ResolveAsync(query, viaTcp: true, cancellationToken);
                        await WriteTcpMessageAsync(stream, answer, cancellationToken);
                    }
                    finally
                    {
                        _inFlight.Release();
                    }
                }
            }
            catch (Exception exception) when (exception is not OperationCanceledException)
            {
                RecordFailure(exception, "Could not answer a TCP query");
            }
        }
    }

    /// <summary>
    /// Decides one question. A blocked name is answered NXDOMAIN locally; a
    /// permitted one is forwarded upstream on the transport that suits it.
    /// </summary>
    private async Task<byte[]> ResolveAsync(byte[] query, bool viaTcp, CancellationToken cancellationToken)
    {
        if (!TryReadQuestion(query, out var domain, out var questionEnd))
        {
            return CreateAnswer(query, questionEnd, ResponseCode.FormatError);
        }

        if (policies.IsBlocked(domain))
        {
            _ = api.ReportBlockedDomainAsync(domain, CancellationToken.None).ContinueWith(
                task => logger.LogWarning(task.Exception, "Could not report DNS block for {Domain}", domain),
                TaskContinuationOptions.OnlyOnFaulted);

            return CreateAnswer(query, questionEnd, ResponseCode.NameError);
        }

        try
        {
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
            timeout.CancelAfter(TimeSpan.FromSeconds(_options.UpstreamTimeoutSeconds));

            if (viaTcp)
            {
                return await ForwardOverTcpAsync(query, timeout.Token);
            }

            var answer = await ForwardOverUdpAsync(query, timeout.Token);

            // A truncated answer is retried over TCP, and returned whole when it
            // still fits the requester's advertised UDP budget. Where it does
            // not, the truncated answer stands and the resolver retries over
            // TCP itself — which this filter now serves.
            if (IsTruncated(answer))
            {
                var complete = await ForwardOverTcpAsync(query, timeout.Token);

                return complete.Length <= MaxUdpPayload(query, questionEnd) ? complete : answer;
            }

            return answer;
        }
        catch (Exception exception) when (exception is not OperationCanceledException || cancellationToken.IsCancellationRequested is false)
        {
            // An upstream that never replies is a server failure, and saying so
            // is what lets the client fail fast instead of waiting out its own
            // timeout on a dropped packet.
            RecordFailure(exception, "Upstream resolution failed for {Domain}", domain);

            return CreateAnswer(query, questionEnd, ResponseCode.ServerFailure);
        }
    }

    private async Task<byte[]> ForwardOverUdpAsync(byte[] query, CancellationToken cancellationToken)
    {
        using var upstream = new UdpClient(UpstreamEndpoint().AddressFamily);
        await upstream.SendAsync(query, query.Length, UpstreamEndpoint());

        return (await upstream.ReceiveAsync(cancellationToken)).Buffer;
    }

    private async Task<byte[]> ForwardOverTcpAsync(byte[] query, CancellationToken cancellationToken)
    {
        using var upstream = new TcpClient(UpstreamEndpoint().AddressFamily);
        await upstream.ConnectAsync(UpstreamEndpoint(), cancellationToken);
        await using var stream = upstream.GetStream();

        await WriteTcpMessageAsync(stream, query, cancellationToken);

        return await ReadTcpMessageAsync(stream, cancellationToken)
            ?? throw new IOException("The upstream resolver closed the connection before answering.");
    }

    private IPEndPoint UpstreamEndpoint() => new(IPAddress.Parse(_options.UpstreamDns), 53);

    /// <summary>
    /// Reads one length-prefixed DNS message, or null when the peer closed the
    /// connection cleanly between messages.
    /// </summary>
    private static async Task<byte[]?> ReadTcpMessageAsync(Stream stream, CancellationToken cancellationToken)
    {
        var prefix = new byte[LengthPrefixBytes];

        if (!await ReadExactlyOrEndAsync(stream, prefix, cancellationToken))
        {
            return null;
        }

        var length = BinaryPrimitives.ReadUInt16BigEndian(prefix);

        if (length is 0 or > MaxMessageLength)
        {
            throw new InvalidDataException($"A DNS message length of {length} is not usable.");
        }

        var message = new byte[length];

        return await ReadExactlyOrEndAsync(stream, message, cancellationToken)
            ? message
            : throw new EndOfStreamException("The DNS message ended before its declared length.");
    }

    private static async Task<bool> ReadExactlyOrEndAsync(Stream stream, byte[] destination, CancellationToken cancellationToken)
    {
        var read = 0;

        while (read < destination.Length)
        {
            var count = await stream.ReadAsync(destination.AsMemory(read), cancellationToken);

            if (count == 0)
            {
                return false;
            }

            read += count;
        }

        return true;
    }

    private static async Task WriteTcpMessageAsync(Stream stream, byte[] message, CancellationToken cancellationToken)
    {
        var framed = new byte[LengthPrefixBytes + message.Length];
        BinaryPrimitives.WriteUInt16BigEndian(framed, (ushort)message.Length);
        message.CopyTo(framed, LengthPrefixBytes);

        await stream.WriteAsync(framed, cancellationToken);
        await stream.FlushAsync(cancellationToken);
    }

    private void RecordFailure(Exception exception, string message, params object?[] arguments)
    {
        _lastFailure = exception.Message;
        logger.LogWarning(exception, message, arguments);
    }

    private static bool IsTruncated(byte[] message) => message.Length > 2 && (message[2] & 0x02) != 0;

    /// <summary>
    /// Reads the queried name and the offset the question section ends at.
    /// Returns false for anything malformed, so a bad packet is answered
    /// FORMERR rather than throwing out of a serving loop.
    /// </summary>
    internal static bool TryReadQuestion(ReadOnlySpan<byte> packet, out string domain, out int questionEnd)
    {
        domain = string.Empty;
        questionEnd = Math.Min(packet.Length, HeaderLength);

        if (packet.Length < HeaderLength + 1)
        {
            return false;
        }

        var labels = new List<string>();
        var offset = HeaderLength;

        while (offset < packet.Length && packet[offset] != 0)
        {
            var length = packet[offset++];

            // A question name is never compressed, so a pointer here is malformed.
            if ((length & 0xc0) != 0 || offset + length > packet.Length)
            {
                return false;
            }

            labels.Add(System.Text.Encoding.ASCII.GetString(packet.Slice(offset, length)));
            offset += length;
        }

        // The root label, then the two-byte QTYPE and QCLASS.
        offset += 1 + 4;

        if (labels.Count == 0 || offset > packet.Length)
        {
            return false;
        }

        domain = string.Join('.', labels).ToLowerInvariant();
        questionEnd = offset;

        return true;
    }

    /// <summary>
    /// The largest UDP answer the requester said it can take: its EDNS(0)
    /// advertised size where it sent one, and the protocol floor otherwise.
    /// </summary>
    internal static int MaxUdpPayload(ReadOnlySpan<byte> query, int questionEnd)
    {
        // Only a query with an empty answer and authority section can have its
        // additional section read this cheaply, which is every real query.
        if (BinaryPrimitives.ReadUInt16BigEndian(query[6..]) != 0
            || BinaryPrimitives.ReadUInt16BigEndian(query[8..]) != 0
            || BinaryPrimitives.ReadUInt16BigEndian(query[10..]) == 0)
        {
            return MinimumUdpPayload;
        }

        // An OPT record is owned by the root, so it starts with a zero byte,
        // and carries the advertised payload size in place of its class.
        var offset = questionEnd;

        while (offset + 11 <= query.Length)
        {
            if (query[offset] != 0)
            {
                return MinimumUdpPayload;
            }

            if (BinaryPrimitives.ReadUInt16BigEndian(query[(offset + 1)..]) == OptRecordType)
            {
                return Math.Max(MinimumUdpPayload, (int)BinaryPrimitives.ReadUInt16BigEndian(query[(offset + 3)..]));
            }

            var dataLength = BinaryPrimitives.ReadUInt16BigEndian(query[(offset + 9)..]);
            offset += 11 + dataLength;
        }

        return MinimumUdpPayload;
    }

    private enum ResponseCode
    {
        NoError = 0,
        FormatError = 1,
        ServerFailure = 2,
        NameError = 3,
    }

    /// <summary>
    /// Builds an answer carrying only the original question. The record counts
    /// are zeroed and the message is cut at the end of the question, so the
    /// counts and the body agree — a header claiming no records while trailing
    /// bytes remain is malformed, and some resolvers reject it.
    /// </summary>
    private static byte[] CreateAnswer(byte[] request, int questionEnd, ResponseCode code)
    {
        var length = Math.Clamp(questionEnd, Math.Min(HeaderLength, request.Length), request.Length);
        var response = request[..length];

        if (response.Length < 4)
        {
            return response;
        }

        // QR set, TC and RA-adjacent flag bits left alone, recursion desired kept.
        response[2] = (byte)((response[2] | 0x80) & 0xfb);
        response[3] = (byte)((response[3] & 0xf0) | (byte)code);

        if (response.Length >= HeaderLength)
        {
            // One question, no answer, authority or additional records.
            BinaryPrimitives.WriteUInt16BigEndian(response.AsSpan(4), 1);
            Array.Clear(response, 6, 6);
        }

        return response;
    }
}
