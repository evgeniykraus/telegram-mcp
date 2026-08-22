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

#[Description('Get comprehensive analytics report for a specific channel (subscribers, total/average views, ER, best post, forwards, reactions).')]
class ChannelReportTool extends Tool
{
    public function handle(Request $request, AnalyticsService $analyticsService): Response|ResponseFactory
    {
        $rawChannel = (string) $request->get('channel');
        $channel = TelegramHelper::normalizeUsername($rawChannel) ?? $rawChannel;
        $days = (int) ($request->get('days') ?? $request->get('since_days') ?? 30);
        $autoCollect = $request->has('auto_collect') ? (bool) $request->get('auto_collect') : true;

        try {
            $report = $analyticsService->getChannelReport($channel, $days, $autoCollect);

            return Response::structured($report->toArray());
        } catch (\InvalidArgumentException $e) {
            return Response::text('Error: '.$e->getMessage());
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channel' => $schema->string()
                ->description('Channel username or URL (e.g. "@durov" or "https://t.me/durov")')
                ->required(),
            'days' => $schema->integer()
                ->description('Period in days to analyze (default: 30)'),
            'auto_collect' => $schema->boolean()
                ->description('Optional: automatically collect channel posts if channel is not found in database (default: true)'),
        ];
    }
}
