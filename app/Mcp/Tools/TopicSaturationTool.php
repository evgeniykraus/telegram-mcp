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

#[Description('Check if a proposed topic/angle is fresh or over-saturated across monitored channels using semantic search. Returns fatigue score and content positioning recommendations.')]
class TopicSaturationTool extends Tool
{
    public function handle(Request $request, AnalyticsService $analyticsService): Response|ResponseFactory
    {
        $query = (string) $request->get('topic_query');
        if (empty($query)) {
            return Response::text('Error: "topic_query" is required.');
        }

        $days = (int) ($request->get('days') ?? $request->get('since_days') ?? 14);
        $channel = $request->get('channel') ? TelegramHelper::normalizeUsername((string) $request->get('channel')) : null;

        $saturation = $analyticsService->checkTopicSaturation($query, $days, $channel);

        return Response::structured($saturation->toArray());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topic_query' => $schema->string()
                ->description('The topic, angle, or thesis you plan to write about (e.g. "нейрокураторы в Telegram", "open source на личном сервере")')
                ->required(),
            'days' => $schema->integer()
                ->description('Time window in days to check for competitor coverage (default: 14)'),
            'channel' => $schema->string()
                ->description('Optional: restrict check to a specific competitor channel (e.g. "@durov")'),
        ];
    }
}
