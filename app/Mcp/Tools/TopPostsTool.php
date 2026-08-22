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

#[Description('Get top performing posts ranked by content score, ER, views, or forwards with optional format and channel filtering.')]
class TopPostsTool extends Tool
{
    public function handle(Request $request, AnalyticsService $analyticsService): Response|ResponseFactory
    {
        $limit = (int) ($request->get('limit') ?? 20);
        $channel = $request->get('channel') ? TelegramHelper::normalizeUsername((string) $request->get('channel')) : null;
        $days = (int) ($request->get('days') ?? $request->get('since_days') ?? 30);
        $format = $request->get('format') ? (string) $request->get('format') : null;
        $sortBy = (string) ($request->get('sort_by') ?? 'content_score');
        $fullText = (bool) ($request->get('full_text') ?? false);
        $previewLength = (int) ($request->get('preview_length') ?? 200);

        $posts = $analyticsService->getTopPosts($limit, $channel, $days, $format, $sortBy);

        $postsData = $fullText
            ? array_map(fn ($p) => $p->toArray(), $posts)
            : array_map(fn ($p) => $p->toPreviewArray($previewLength), $posts);

        return Response::structured([
            'count' => count($posts),
            'sort_by' => $sortBy,
            'full_text' => $fullText,
            'posts' => $postsData,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channel' => $schema->string()
                ->description('Optional: filter by channel username or URL (e.g. "@durov" or "https://t.me/durov")'),
            'limit' => $schema->integer()
                ->description('Number of top posts to return (default: 20)'),
            'days' => $schema->integer()
                ->description('Optional: only analyze posts from the last N days (default: 30)'),
            'full_text' => $schema->boolean()
                ->description('Optional: if true, returns full post text. Default is false (returns compact 200-char preview to save tokens).'),
            'preview_length' => $schema->integer()
                ->description('Optional: maximum character length of text preview when full_text is false (default: 200)'),
            'format' => $schema->string()
                ->description('Optional: filter by format type ("text", "photo", "video", "poll", "album", "link")'),
            'sort_by' => $schema->string()
                ->enum(['content_score', 'er', 'views', 'forwards', 'reactions'])
                ->description('Sort criteria: "content_score", "er", "views", "forwards", "reactions" (default: "content_score")'),
        ];
    }
}
