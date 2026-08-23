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

#[Description('Compare performance of your own channel vs competitors in a niche category (average ER, average views, delta percentages). Pass your channel explicitly via "channel"; competitors are the other active channels in the same category.')]
class BenchmarkTool extends Tool
{
    public function handle(Request $request, AnalyticsService $analyticsService): Response|ResponseFactory
    {
        $category = (string) ($request->get('category') ?? 'all');
        $days = (int) ($request->get('days') ?? $request->get('since_days') ?? 30);
        $includeChannels = (bool) ($request->get('include_channels') ?? false);
        $channel = $request->get('channel') ? TelegramHelper::normalizeUsername((string) $request->get('channel')) : null;

        $benchmark = $analyticsService->getBenchmark($days, $category, $channel)->toArray();

        if (! $includeChannels) {
            unset($benchmark['own_channels'], $benchmark['competitor_channels']);
        }

        return Response::structured($benchmark);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channel' => $schema->string()
                ->description('Your own channel username or URL (e.g. "@my_channel" or "https://t.me/my_channel"). Benchmarked against other active channels in the category. If omitted, own_* metrics are empty.'),
            'category' => $schema->string()
                ->description('Niche category (e.g. "tech", "crypto", "marketing") or "all" (default: "all"). If "all" and the own channel has a category, that category is used.'),
            'days' => $schema->integer()
                ->description('Period in days to analyze (default: 30)'),
            'include_channels' => $schema->boolean()
                ->description('Optional: if true, include compact own/competitor channel lists (username + subscribers_count). Default is false (aggregates only).'),
        ];
    }
}
