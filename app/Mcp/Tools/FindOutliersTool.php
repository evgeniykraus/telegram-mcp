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

#[Description('Detect viral anomaly outlier posts whose performance on a chosen dimension (views by default) exceeds their channel median. Returns the chosen-dimension multiplier plus peak/views/engagement/ER multipliers for context. Essential for identifying high-performing hooks and breakout angles.')]
class FindOutliersTool extends Tool
{
    public function handle(Request $request, AnalyticsService $analyticsService): Response|ResponseFactory
    {
        $minMultiplier = (float) ($request->get('min_multiplier') ?? 2.0);
        $days = (int) ($request->get('days') ?? $request->get('since_days') ?? 14);
        $channel = $request->get('channel') ? TelegramHelper::normalizeUsername((string) $request->get('channel')) : null;
        $limit = (int) ($request->get('limit') ?? 10);
        $dimension = (string) ($request->get('dimension') ?? 'views');

        $outliers = $analyticsService->getViralOutliers($minMultiplier, $days, $channel, $limit, $dimension);

        return Response::structured([
            'count' => count($outliers),
            'min_multiplier' => $minMultiplier,
            'dimension' => $dimension,
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
                ->description('Minimum performance multiplier vs channel median on the chosen dimension (e.g. 2.0 = 2x median). Default: 2.0'),
            'dimension' => $schema->string()
                ->description('Dimension used for the multiplier threshold and ranking: "views", "engagement", "er", or "any" (max of the three). Default: "views"')
                ->enum(['views', 'engagement', 'er', 'any'])
                ->default('views'),
            'days' => $schema->integer()
                ->description('Lookback period in days to analyze (default: 14)'),
            'channel' => $schema->string()
                ->description('Optional: restrict anomaly search to a specific channel username or URL (e.g. "@alexmerzliakov"). If omitted, scans across all active channels.'),
            'limit' => $schema->integer()
                ->description('Maximum number of outlier posts to return (default: 10)'),
        ];
    }
}
