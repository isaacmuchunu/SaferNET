using System.Buffers.Binary;
using SaferNet.Agent;
using Xunit;

namespace SaferNet.Agent.Tests;

public sealed class DnsFilterTests
{
    [Fact]
    public void AQuestionIsReadAlongsideTheOffsetItEndsAt()
    {
        var query = Query("www.example.com");

        Assert.True(DnsFilter.TryReadQuestion(query, out var domain, out var questionEnd));
        Assert.Equal("www.example.com", domain);
        Assert.Equal(query.Length, questionEnd);
    }

    [Fact]
    public void AQuestionNameIsLowercased()
    {
        Assert.True(DnsFilter.TryReadQuestion(Query("WWW.Example.COM"), out var domain, out _));
        Assert.Equal("www.example.com", domain);
    }

    [Theory]
    [InlineData(0)]
    [InlineData(11)]
    [InlineData(12)]
    public void APacketTooShortToHoldAQuestionIsRejected(int length)
    {
        Assert.False(DnsFilter.TryReadQuestion(new byte[length], out _, out _));
    }

    [Fact]
    public void ANameRunningPastTheEndOfThePacketIsRejected()
    {
        var query = Query("example.com");

        Assert.False(DnsFilter.TryReadQuestion(query[..^6], out _, out _));
    }

    [Fact]
    public void ACompressionPointerInAQuestionNameIsRejected()
    {
        var query = Query("example.com");
        query[12] = 0xc0;

        Assert.False(DnsFilter.TryReadQuestion(query, out _, out _));
    }

    [Fact]
    public void AQueryWithoutEdnsGetsTheProtocolFloor()
    {
        var query = Query("example.com");

        Assert.True(DnsFilter.TryReadQuestion(query, out _, out var questionEnd));
        Assert.Equal(512, DnsFilter.MaxUdpPayload(query, questionEnd));
    }

    [Fact]
    public void AnEdnsQueryGetsTheSizeItAdvertised()
    {
        var query = Query("example.com", advertisedUdpPayload: 4096);

        Assert.True(DnsFilter.TryReadQuestion(query, out _, out var questionEnd));
        Assert.Equal(4096, DnsFilter.MaxUdpPayload(query, questionEnd));
    }

    [Fact]
    public void AnEdnsQueryAdvertisingLessThanTheFloorStillGetsTheFloor()
    {
        var query = Query("example.com", advertisedUdpPayload: 128);

        Assert.True(DnsFilter.TryReadQuestion(query, out _, out var questionEnd));
        Assert.Equal(512, DnsFilter.MaxUdpPayload(query, questionEnd));
    }

    /// <summary>Builds a single-question query, optionally carrying an EDNS(0) OPT record.</summary>
    private static byte[] Query(string name, int? advertisedUdpPayload = null)
    {
        var message = new List<byte>
        {
            0x12, 0x34,             // transaction id
            0x01, 0x00,             // standard query, recursion desired
            0x00, 0x01,             // one question
            0x00, 0x00,             // no answers
            0x00, 0x00,             // no authority records
            0x00, (byte)(advertisedUdpPayload.HasValue ? 1 : 0),
        };

        foreach (var label in name.Split('.'))
        {
            message.Add((byte)label.Length);
            message.AddRange(System.Text.Encoding.ASCII.GetBytes(label));
        }

        message.Add(0x00);                  // root label
        message.AddRange([0x00, 0x01]);     // QTYPE A
        message.AddRange([0x00, 0x01]);     // QCLASS IN

        if (advertisedUdpPayload.HasValue)
        {
            var size = new byte[2];
            BinaryPrimitives.WriteUInt16BigEndian(size, (ushort)advertisedUdpPayload.Value);

            message.Add(0x00);                              // owned by the root
            message.AddRange([0x00, 0x29]);                 // TYPE OPT (41)
            message.AddRange(size);                         // advertised payload size
            message.AddRange([0x00, 0x00, 0x00, 0x00]);     // extended rcode and flags
            message.AddRange([0x00, 0x00]);                 // no option data
        }

        return [.. message];
    }
}
