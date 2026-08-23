<?php

namespace App\Mcp\Tools;

use App\Services\Storyline\StorylineService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Group recent posts into storylines (clusters of related posts across channels) with chronology and cross-channel corroboration. Essential for deduplicating the same story reported by multiple channels.')]
class ClusterStorylinesTool extends Tool
{
    public function handle(Request $request, StorylineService $storylineService): Response|ResponseFactory
    {
        $query = $request->get('query') !== null ? (string) $request->get('query') : null;
        if ($query === '') {
            $query = null;
        }

        $days = max(1, min((int) ($request->get('days') ?? 3), 30));
        $limit = max(10, min((int) ($request->get('limit') ?? 200), 500));
        $minClusterSize = max(2, (int) ($request->get('min_cluster_size') ?? 2));

        return Response::structured($storylineService->clusterStorylines($query, $days, $limit, $minClusterSize));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Optional: focus storylines on a topic/event (e.g. "нейросети", "криптовалюта"). Omit to cluster all recent posts.'),
            'days' => $schema->integer()
                ->description('Lookback period in days (default: 3, max: 30)')
                ->default(3),
            'limit' => $schema->integer()
                ->description('Maximum number of recent posts to scan (default: 200, max: 500)')
                ->default(200),
            'min_cluster_size' => $schema->integer()
                ->description('Minimum posts per storyline (default: 2 — a story needs corroboration; set 1 for a full timeline)')
                ->default(2),
        ];
    }
}
