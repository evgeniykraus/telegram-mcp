<?php

namespace App\Mcp\Tools;

use App\Services\Collection\TelegramDiscoveryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Discover popular thematic Telegram channels by keyword, topic, or niche with subscriber counts, recent views, and ER.')]
class DiscoverChannelsTool extends Tool
{
    public function handle(Request $request, TelegramDiscoveryService $discoveryService): Response|ResponseFactory
    {
        $query = (string) $request->get('query');
        if (trim($query) === '') {
            return Response::text('Error: "query" is required (e.g. "нейросети", "криптовалюта", "tech news").');
        }

        $limit = max(1, min((int) ($request->get('limit') ?? 10), 50));
        $minSubscribers = max(0, (int) ($request->get('min_subscribers') ?? 0));

        $channels = $discoveryService->discoverChannels(
            query: $query,
            limit: $limit,
            minSubscribers: $minSubscribers
        );

        $channelsData = array_map(fn ($c) => $c->toArray(), $channels);

        return Response::structured([
            'query' => $query,
            'count' => count($channelsData),
            'limit' => $limit,
            'min_subscribers' => $minSubscribers,
            'channels' => $channelsData,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search query, niche, or topic (e.g. "нейросети", "маркетинг", "crypto news")')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of channels to discover (default: 10, max: 50)'),
            'min_subscribers' => $schema->integer()
                ->description('Optional: minimum subscriber count filter (e.g. 5000)'),
        ];
    }
}
