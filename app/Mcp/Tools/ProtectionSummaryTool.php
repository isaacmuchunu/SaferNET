<?php

namespace App\Mcp\Tools;

use App\Models\Device;
use App\Models\Incident;
use App\Models\Institution;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Returns aggregate SAFERNET deployment, attribution, and incident health without exposing learner browsing details.')]
class ProtectionSummaryTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['institution_id' => ['nullable', 'integer', 'exists:institutions,id']]);
        $institutionId = $validated['institution_id'] ?? null;
        $devices = Device::query()->when($institutionId, fn ($query) => $query->where('institution_id', $institutionId));
        $incidents = Incident::query()->when($institutionId, fn ($query) => $query->where('institution_id', $institutionId));

        return Response::json([
            'scope' => $institutionId === null ? 'county' : 'institution',
            'institution_id' => $institutionId,
            'institutions' => $institutionId === null ? Institution::count() : 1,
            'devices' => $devices->count(),
            'unattributed_learner_devices' => (clone $devices)
                ->where('usage_type', 'learner')
                ->whereDoesntHave('activeAssignments')
                ->count(),
            'devices_requiring_attention' => (clone $devices)
                ->whereIn('status', ['attention_required', 'offline'])
                ->count(),
            'open_incidents' => $incidents->whereIn('status', ['open', 'under_review'])->count(),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'institution_id' => $schema->integer()->description('Optional institution database ID; omit for county-wide totals.'),
        ];
    }
}
