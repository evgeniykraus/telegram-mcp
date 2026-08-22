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

#[Description('Detect viral anomaly outlier posts that achieved 2x-10x+ views relative to their channel median. Essential for identifying high-performing hooks and breakout angles.')]
class FindOutliersTool extends Tool
{
    public function handle(Request $request, AnalyticsService $analyticsService): Response|ResponseFactory
    {
        $minMultiplier = (float) ($request->get('min_multiplier') ?? 2.0);
        $days = (int) ($request->get('days') ?? $request->get('since_days') ?? 14);
        $channel = $request->get('channel') ? TelegramHelper::normalizeUsername((string) $request->get('channel')) : null;
        $limit = (int) ($request->get('limit') ?? 10);

        $outliers = $analyticsService->getViralOutliers($minMultiplier, $days, $channel, $limit);

        return Response::structured([
            'count' => count($outliers),
            'min_multiplier' => $minMultiplier,
            'period_days' => $days,
            'channel_filter' => $channel,
            'outliers' => array_map(fn ($o) => $o->toArray(), $outliers),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'min_multiplier' => $schema->number()
                ->description('Minimum performance multiplier vs channel median views (e.g. 2.0 = post had 2x channel median, 3.5 = 3.5x). Default: 2.0'),
            'days' => $schema->integer()
                ->description('Lookback period in days to analyze (default: 14)'),
            'channel' => $schema->string()
                ->description('Optional: restrict anomaly search to a specific channel username or URL (e.g. "@alexmerzliakov"). If omitted, scans across all active channels.'),
            'limit' => $schema->integer()
                ->description('Maximum number of outlier posts to return (default: 10)'),
        ];
    }
}
