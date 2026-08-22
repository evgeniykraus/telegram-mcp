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

#[Description('Semantic vector search for posts similar to a text query or an existing post ID using bge-m3 embeddings and pgvector.')]
class SearchSimilarPostsTool extends Tool
{
    public function handle(Request $request, AnalyticsService $analyticsService): Response|ResponseFactory
    {
        $queryOrId = $request->get('query_or_post_id');
        if (empty($queryOrId)) {
            return Response::text('Error: "query_or_post_id" is required (text search query or numeric post ID).');
        }

        $limit = (int) ($request->get('limit') ?? 10);
        $channel = $request->get('channel') ? TelegramHelper::normalizeUsername((string) $request->get('channel')) : null;
        $fullText = (bool) ($request->get('full_text') ?? false);
        $previewLength = (int) ($request->get('preview_length') ?? 200);

        $similarPosts = $analyticsService->searchSimilarPosts($queryOrId, $limit, $channel);

        $resultsData = $fullText
            ? array_map(fn ($p) => $p->toArray(), $similarPosts)
            : array_map(fn ($p) => $p->toPreviewArray($previewLength), $similarPosts);

        return Response::structured([
            'query' => $queryOrId,
            'count' => count($similarPosts),
            'full_text' => $fullText,
            'results' => $resultsData,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query_or_post_id' => $schema->string()
                ->description('Search query string (e.g. "новости искусственного интеллекта") or numeric post ID')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of similar posts to return (default: 10)'),
            'channel' => $schema->string()
                ->description('Optional: limit search to a specific channel username or URL (e.g. "@durov")'),
            'full_text' => $schema->boolean()
                ->description('Optional: if true, returns full post text. Default is false (compact preview).'),
            'preview_length' => $schema->integer()
                ->description('Optional: maximum character length of text preview when full_text is false (default: 200)'),
        ];
    }
}
