using System.Net;
using System.Net.Sockets;
using Microsoft.Extensions.Options;

namespace SaferNet.Agent;

public sealed class DnsFilter(PolicyStore policies, SaferNetApiClient api, IOptions<AgentOptions> options, ILogger<DnsFilter> logger)
{
    private readonly AgentOptions _options = options.Value;

    public async Task RunAsync(CancellationToken cancellationToken)
    {
        using var listener = new UdpClient(new IPEndPoint(IPAddress.Loopback, _options.DnsPort));
        logger.LogInformation("DNS filter listening on 127.0.0.1:{Port}", _options.DnsPort);

        while (!cancellationToken.IsCancellationRequested)
        {
            var request = await listener.ReceiveAsync(cancellationToken);
            _ = HandleAsync(listener, request, cancellationToken);
        }
    }

    private async Task HandleAsync(UdpClient listener, UdpReceiveResult request, CancellationToken cancellationToken)
    {
        try
        {
            var domain = ReadQuestionName(request.Buffer);
            byte[] answer;
            if (policies.IsBlocked(domain))
            {
                answer = CreateNxDomain(request.Buffer);
                _ = api.ReportBlockedDomainAsync(domain, CancellationToken.None).ContinueWith(
                    task => logger.LogWarning(task.Exception, "Could not report DNS block for {Domain}", domain),
                    TaskContinuationOptions.OnlyOnFaulted);
            }
            else
            {
                using var upstream = new UdpClient();
                using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
                timeout.CancelAfter(TimeSpan.FromSeconds(4));
                await upstream.SendAsync(request.Buffer, new IPEndPoint(IPAddress.Parse(_options.UpstreamDns), 53), timeout.Token);
                answer = (await upstream.ReceiveAsync(timeout.Token)).Buffer;
            }
            await listener.SendAsync(answer, request.RemoteEndPoint, cancellationToken);
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            logger.LogWarning(exception, "DNS request failed");
        }
    }

    internal static string ReadQuestionName(ReadOnlySpan<byte> packet)
    {
        if (packet.Length < 13) throw new InvalidDataException("DNS packet is truncated.");
        var labels = new List<string>();
        var offset = 12;
        while (offset < packet.Length && packet[offset] != 0)
        {
            var length = packet[offset++];
            if ((length & 0xc0) != 0 || offset + length > packet.Length) throw new InvalidDataException("Invalid DNS question name.");
            labels.Add(System.Text.Encoding.ASCII.GetString(packet.Slice(offset, length)));
            offset += length;
        }
        return string.Join('.', labels).ToLowerInvariant();
    }

    private static byte[] CreateNxDomain(byte[] request)
    {
        var response = (byte[])request.Clone();
        response[2] = (byte)((response[2] | 0x80) & 0xfb);
        response[3] = (byte)((response[3] & 0xf0) | 0x03);
        Array.Clear(response, 6, 6);
        return response;
    }
}
