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

#[Description('Search recent news and posts globally across Telegram and the local database by keyword or topic.')]
class SearchGlobalNewsTool extends Tool
{
    public function handle(Request $request, TelegramDiscoveryService $discoveryService): Response|ResponseFactory
    {
        $query = (string) $request->get('query');
        if (trim($query) === '') {
            return Response::text('Error: "query" is required (e.g. "Claude 3.7", "OpenAI", "ставка ЦБ").');
        }

        $limit = max(1, min((int) ($request->get('limit') ?? 15), 50));
        $sinceHours = $request->get('since_hours') !== null ? (int) $request->get('since_hours') : 24;
        $minViews = max(0, (int) ($request->get('min_views') ?? 0));
        $fullText = (bool) ($request->get('full_text') ?? false);
        $previewLength = max(50, (int) ($request->get('preview_length') ?? 200));

        $posts = $discoveryService->searchGlobalNews(
            query: $query,
            limit: $limit,
            sinceHours: $sinceHours,
            minViews: $minViews
        );

        $resultsData = $fullText
            ? array_map(fn ($p) => $p->toArray(), $posts)
            : array_map(fn ($p) => $p->toPreviewArray($previewLength), $posts);

        return Response::structured([
            'query' => $query,
            'count' => count($resultsData),
            'limit' => $limit,
            'since_hours' => $sinceHours,
            'min_views' => $minViews,
            'full_text' => $fullText,
            'posts' => $resultsData,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search query or news topic (e.g. "Claude 3.7", "новости ии", "криптовалюта")')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of posts to return (default: 15, max: 50)'),
            'since_hours' => $schema->integer()
                ->description('Optional: search posts published in the last N hours (default: 24)'),
            'min_views' => $schema->integer()
                ->description('Optional: filter posts by minimum view count (default: 0)'),
            'full_text' => $schema->boolean()
                ->description('Optional: if true, returns full post text. Default is false (compact preview).'),
            'preview_length' => $schema->integer()
                ->description('Optional: character limit for text preview when full_text is false (default: 200)'),
        ];
    }
}
