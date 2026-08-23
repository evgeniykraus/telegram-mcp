<?php

namespace App\Mcp\Tools;

use App\Services\Analytics\AnalyticsService;
use App\Support\TelegramHelper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Discover the best days and hours to publish content based on historical engagement rate and view performance heatmap.')]
class BestTimeToPostTool extends Tool
{
    public function handle(Request $request, AnalyticsService $analyticsService): Response|ResponseFactory
    {
        $channel = $request->get('channel') ? TelegramHelper::normalizeUsername((string) $request->get('channel')) : null;
        $days = (int) ($request->get('days') ?? $request->get('since_days') ?? 30);
        $limit = (int) ($request->get('limit') ?? 5);

        $slots = $analyticsService->getBestTimeToPost($days, $channel);

        return Response::structured([
            'period_days' => $days,
            'channel' => $channel,
            'top_recommendations' => array_slice(array_map(fn ($s) => $s->toArray(), $slots), 0, $limit),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channel' => $schema->string()
                ->description('Optional: channel username or URL (e.g. "@durov" or "https://t.me/durov"). If omitted, aggregates across all channels.'),
            'days' => $schema->integer()
                ->description('Period in days to analyze (default: 30)'),
            'limit' => $schema->integer()
                ->description('Number of top time slots to return (default: 5). Pass a large value (e.g. 168) to get the full 7x24 heatmap.'),
        ];
    }
}
