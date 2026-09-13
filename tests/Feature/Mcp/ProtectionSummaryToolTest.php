<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\ProtectionSummaryTool;
use App\Models\Device;
use App\Models\Institution;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

class ProtectionSummaryToolTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_aggregate_protection_summary_without_learner_details(): void
    {
        $institution = Institution::factory()->create();
        Device::factory()->count(2)->for($institution)->create();

        $response = app(ProtectionSummaryTool::class)->handle(new Request(['institution_id' => $institution->id]));
        $summary = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('institution', $summary['scope']);
        $this->assertSame(2, $summary['devices']);
        $this->assertSame(2, $summary['unattributed_learner_devices']);
        $this->assertArrayNotHasKey('learners', $summary);
    }
}
