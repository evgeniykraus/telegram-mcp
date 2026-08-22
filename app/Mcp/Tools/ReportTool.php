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

#[Description('Generate a complete executive intelligence report combining channel KPIs, top posts, format winners, and best publishing times.')]
class ReportTool extends Tool
{
    public function handle(Request $request, AnalyticsService $analyticsService): Response|ResponseFactory
    {
        $channel = $request->get('channel') ? TelegramHelper::normalizeUsername((string) $request->get('channel')) : null;
        $days = (int) ($request->get('days') ?? $request->get('since_days') ?? 30);

        $report = $analyticsService->getCompositeReport($days, $channel);

        return Response::structured($report->toArray());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channel' => $schema->string()
                ->description('Optional: channel username or URL (e.g. "@durov" or "https://t.me/durov") for single-channel report, or omit for all channels.'),
            'days' => $schema->integer()
                ->description('Period in days to analyze (default: 30)'),
        ];
    }
}
