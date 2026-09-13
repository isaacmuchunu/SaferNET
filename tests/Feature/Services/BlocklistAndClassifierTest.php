<?php

namespace Tests\Feature\Services;

use App\Models\BlockedDomain;
use App\Models\BlocklistSource;
use App\Models\ContentCategory;
use App\Models\Institution;
use App\Models\User;
use App\Services\Ai\ContentClassifier;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Blocklists\BlocklistSynchroniser;
use App\Services\Blocklists\SafeFetcher;
use App\Services\Filtering\DomainAdvisor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class BlocklistAndClassifierTest extends TestCase
{
    use LazilyRefreshDatabase;

    /* ------------------------------------------------------- SSRF containment */

    public function test_only_https_sources_on_the_allowlist_may_be_fetched(): void
    {
        $fetcher = new SafeFetcher(['raw.githubusercontent.com'], 1024, 5);

        $this->assertFetchRefused($fetcher, 'http://raw.githubusercontent.com/list.txt', 'https');
        $this->assertFetchRefused($fetcher, 'https://example.com/list.txt', 'allowlist');
    }

    public function test_private_and_metadata_addresses_are_never_reachable(): void
    {
        $fetcher = new SafeFetcher(['raw.githubusercontent.com'], 1024, 5);

        foreach (['169.254.169.254', '127.0.0.1', '10.0.0.5', '192.168.1.1', '172.16.4.2', '::1', 'fd00::1'] as $address) {
            $this->assertTrue($fetcher->isBlockedAddress($address), "{$address} should be refused.");
        }

        $this->assertFalse($fetcher->isBlockedAddress('185.199.108.133'));
    }

    private function assertFetchRefused(SafeFetcher $fetcher, string $url, string $expected): void
    {
        try {
            $fetcher->assertFetchable($url);
            $this->fail("Expected {$url} to be refused.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($expected, $exception->getMessage());
        }
    }

    /* ------------------------------------------------------------- parsing */

    public function test_both_published_list_formats_are_parsed(): void
    {
        $synchroniser = app(BlocklistSynchroniser::class);

        $domains = $synchroniser->parse(<<<'LIST'
        # Title of the list
        0.0.0.0 bad-example.com
        0.0.0.0 localhost
        127.0.0.1 another-bad.example.org

        plain-domain.net
        not a domain
        UPPER-CASE.COM
        bad-example.com
        LIST);

        sort($domains);

        $this->assertSame(
            ['another-bad.example.org', 'bad-example.com', 'plain-domain.net', 'upper-case.com'],
            $domains,
        );
    }

    /* ------------------------------------------------------------- advisor */

    public function test_a_listed_domain_is_answered_from_the_county_policy(): void
    {
        $category = ContentCategory::factory()->create(['name' => 'Gambling', 'default_severity' => 'high']);
        $source = BlocklistSource::query()->create([
            'slug' => 'test-gambling',
            'name' => 'Test — Gambling',
            'url' => 'https://raw.githubusercontent.com/example/list.txt',
            'content_category_id' => $category->id,
            'provenance' => 'test',
            'is_enabled' => true,
        ]);
        BlockedDomain::query()->create(['blocklist_source_id' => $source->id, 'domain' => 'bet-example.co.ke']);

        $advisor = app(DomainAdvisor::class);

        $direct = $advisor->assess('https://bet-example.co.ke/live');
        $this->assertSame('blocklist', $direct['source']);
        $this->assertSame('block', $direct['action']);
        $this->assertSame('Gambling', $direct['category']);

        // A block on the registrable domain covers everything beneath it.
        $subdomain = $advisor->assess('https://promo.sports.bet-example.co.ke/');
        $this->assertSame('blocklist', $subdomain['source']);
    }

    public function test_a_disabled_source_stops_blocking(): void
    {
        $source = BlocklistSource::query()->create([
            'slug' => 'test-social',
            'name' => 'Test — Social',
            'url' => 'https://raw.githubusercontent.com/example/social.txt',
            'provenance' => 'test',
            'is_enabled' => false,
        ]);
        BlockedDomain::query()->create(['blocklist_source_id' => $source->id, 'domain' => 'social-example.com']);

        $this->assertSame('unknown', app(DomainAdvisor::class)->assess('https://social-example.com/feed')['source']);
    }

    /* ---------------------------------------------------------- classifier */

    public function test_the_classifier_selects_the_first_configured_provider(): void
    {
        $classifier = new ContentClassifier([
            new GeminiProvider(null, 'https://gemini.test/models', ['gemini-flash-latest'], 5),
            new AnthropicProvider('key', 'https://anthropic.test/v1/messages', '2023-06-01', ['claude-haiku-4-5-20251001'], 5),
            new OpenAiProvider('key', 'https://openai.test/v1/chat', ['gpt-4o-mini'], 5),
        ]);

        $this->assertTrue($classifier->isEnabled());
        $this->assertSame('anthropic', $classifier->activeProvider()->name());
    }

    public function test_a_classifier_answer_is_coerced_into_the_filtering_contract(): void
    {
        Cache::flush();
        Http::fake([
            'anthropic.test/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => '{"category":"Circumvention/VPN","riskScore":91,"riskSeverity":"critical","action":"block","rationale":"Known proxy service."}',
                ]],
            ]),
        ]);

        $classifier = new ContentClassifier([
            new AnthropicProvider('key', 'https://anthropic.test/v1/messages', '2023-06-01', ['claude-haiku-4-5-20251001'], 5),
        ]);

        $assessment = $classifier->classify('https://unknown-proxy.example/start');

        $this->assertNotNull($assessment);
        $this->assertSame('Circumvention/VPN', $assessment->category);
        $this->assertSame(91, $assessment->riskScore);
        $this->assertSame('critical', $assessment->severity->value);
        $this->assertSame('block', $assessment->action->value);
        $this->assertSame('anthropic', $assessment->provider);
    }

    public function test_an_unusable_answer_falls_back_to_the_deterministic_rules(): void
    {
        Cache::flush();
        Http::fake(['anthropic.test/*' => Http::response(['content' => [['type' => 'text', 'text' => 'I cannot help with that.']]])]);

        $classifier = new ContentClassifier([
            new AnthropicProvider('key', 'https://anthropic.test/v1/messages', '2023-06-01', ['claude-haiku-4-5-20251001'], 5),
        ]);

        $this->assertNull($classifier->classify('https://unknown-example.test/page'));
    }

    public function test_the_classifier_is_disabled_when_nothing_is_configured(): void
    {
        $classifier = new ContentClassifier([
            new GeminiProvider(null, 'https://gemini.test/models', ['gemini-flash-latest'], 5),
        ]);

        $this->assertFalse($classifier->isEnabled());
        $this->assertNull($classifier->activeProvider());
        $this->assertNull($classifier->classify('https://anything.test/page'));
    }

    /* ------------------------------------------------------------------ API */

    public function test_only_the_county_director_may_change_what_the_county_blocks(): void
    {
        $source = BlocklistSource::query()->create([
            'slug' => 'test-source',
            'name' => 'Test source',
            'url' => 'https://raw.githubusercontent.com/example/list.txt',
            'provenance' => 'test',
            'is_enabled' => true,
        ]);

        $institution = Institution::factory()->create();
        Sanctum::actingAs(User::factory()->hoi($institution)->create(), ['portal:access']);

        // Every officer may read the catalogue: it explains why a site was blocked.
        $this->getJson(route('api.v1.blocklist-sources.index'))->assertOk();

        $this->putJson(route('api.v1.blocklist-sources.update', $source), ['is_enabled' => false])->assertForbidden();
        $this->postJson(route('api.v1.blocklist-sources.sync', $source))->assertForbidden();

        Sanctum::actingAs(User::factory()->cde()->create(), ['portal:access']);
        $this->putJson(route('api.v1.blocklist-sources.update', $source), ['is_enabled' => false])->assertOk();
        $this->assertDatabaseHas('blocklist_sources', ['id' => $source->id, 'is_enabled' => false]);
    }

    public function test_the_integrations_board_reports_what_is_actually_connected(): void
    {
        config()->set('notifications.sms.provider', 'log');
        Sanctum::actingAs(User::factory()->cde()->create(), ['portal:access']);

        $this->getJson(route('api.v1.integrations.index'))
            ->assertOk()
            ->assertJsonPath('data.sms.provider', 'log')
            ->assertJsonPath('data.ai.enabled', false)
            ->assertJsonStructure(['data' => ['ai' => ['providers'], 'sms', 'mail', 'blocklists']]);
    }
}
