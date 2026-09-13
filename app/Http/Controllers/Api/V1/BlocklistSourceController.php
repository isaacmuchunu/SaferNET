<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\BlocklistSource;
use App\Services\Blocklists\BlocklistSynchroniser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The upstream domain blocklists the county synchronises.
 *
 * Every officer may read the catalogue — it explains why a site was blocked —
 * but only the County Director changes what the county enforces.
 */
class BlocklistSourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sources = BlocklistSource::query()
            ->with('category:id,name,slug')
            ->orderByDesc('is_enabled')
            ->orderBy('name')
            ->get()
            ->map(fn (BlocklistSource $source): array => $this->present($source));

        return response()->json(['data' => $sources]);
    }

    public function update(Request $request, BlocklistSource $blocklistSource): JsonResponse
    {
        abort_unless($request->user()->hasRole(UserRole::Cde), 403);

        $validated = $request->validate(['is_enabled' => ['required', 'boolean']]);
        $blocklistSource->update($validated);

        return response()->json(['data' => $this->present($blocklistSource->fresh()->load('category'))]);
    }

    public function sync(Request $request, BlocklistSource $blocklistSource, BlocklistSynchroniser $synchroniser): JsonResponse
    {
        abort_unless($request->user()->hasRole(UserRole::Cde), 403);

        try {
            $result = $synchroniser->sync($blocklistSource);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'The source could not be synchronised: '.$exception->getMessage(),
                'data' => $this->present($blocklistSource->fresh()->load('category')),
            ], 422);
        }

        return response()->json([
            'message' => $result['unchanged']
                ? 'The upstream list is unchanged since the last synchronisation.'
                : sprintf('%s domains were refreshed from the upstream list.', number_format($result['domains'])),
            'data' => $this->present($blocklistSource->fresh()->load('category')),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(BlocklistSource $source): array
    {
        return [
            'id' => $source->id,
            'slug' => $source->slug,
            'name' => $source->name,
            'url' => $source->url,
            'description' => $source->description,
            'provenance' => $source->provenance,
            'is_enabled' => $source->is_enabled,
            'domains_count' => $source->domains_count,
            'bytes_fetched' => $source->bytes_fetched,
            'last_synced_at' => $source->last_synced_at,
            'last_status' => $source->last_status,
            'last_error' => $source->last_error,
            'category' => $source->category === null ? null : [
                'id' => $source->category->id,
                'name' => $source->category->name,
            ],
        ];
    }
}
