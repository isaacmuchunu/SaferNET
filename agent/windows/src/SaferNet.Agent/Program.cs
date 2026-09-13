using Microsoft.Extensions.Options;
using SaferNet.Agent;

var builder = Host.CreateApplicationBuilder(args);
builder.Services.AddWindowsService(options => options.ServiceName = "SAFERNET Endpoint Agent");
builder.Services.AddOptions<AgentOptions>()
    .Bind(builder.Configuration.GetSection("Agent"))
    .ValidateDataAnnotations()
    .ValidateOnStart();
builder.Services.AddSingleton<PolicyStore>();
builder.Services.AddSingleton<DnsFilter>();
builder.Services.AddHttpClient<SaferNetApiClient>((services, client) =>
{
    var options = services.GetRequiredService<IOptions<AgentOptions>>().Value;
    client.BaseAddress = new Uri(options.ApiBaseUrl.TrimEnd('/') + "/");
    client.Timeout = TimeSpan.FromSeconds(15);
    client.DefaultRequestHeaders.Authorization = new("Bearer", options.ServiceToken);
    client.DefaultRequestHeaders.UserAgent.ParseAdd("SaferNET-Windows-Agent/1.0.0");
});
builder.Services.AddHostedService<AgentWorker>();

await builder.Build().RunAsync();
