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

#[Description('Analyze engagement and performance metrics grouped by media and content format (text, photo, video, poll, link, etc.).')]
class FormatBreakdownTool extends Tool
{
    public function handle(Request $request, AnalyticsService $analyticsService): Response|ResponseFactory
    {
        $channel = $request->get('channel') ? TelegramHelper::normalizeUsername((string) $request->get('channel')) : null;
        $days = (int) ($request->get('days') ?? $request->get('since_days') ?? 30);

        $formats = $analyticsService->getFormatBreakdown($days, $channel);

        return Response::structured([
            'period_days' => $days,
            'channel' => $channel,
            'formats' => array_map(fn ($f) => $f->toArray(), $formats),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channel' => $schema->string()
                ->description('Optional: channel username or URL (e.g. "@durov" or "https://t.me/durov"). If omitted, analyzes all channels.'),
            'days' => $schema->integer()
                ->description('Period in days to analyze (default: 30)'),
        ];
    }
}
