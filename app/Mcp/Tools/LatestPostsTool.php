<?php

namespace App\Mcp\Tools;

use App\DTOs\Analytics\GlobalPostDto;
use App\Services\Collection\TelegramCollectionService;
use App\Support\TelegramHelper;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Fetch the latest posts from one or more Telegram channels (a list, not counters), optionally filtered to a time window. For getting the freshest content without guessing post IDs.')]
class LatestPostsTool extends Tool
{
    public function handle(Request $request, TelegramCollectionService $collectionService): Response|ResponseFactory
    {
        $channels = TelegramHelper::normalizeChannels($request->get('channels'));
        if (empty($channels)) {
            return Response::text('Error: "channels" is required (e.g. "@channel1, @channel2").');
        }

        $limit = max(1, min((int) ($request->get('limit') ?? 100), 500));
        $sinceHours = $request->get('since_hours') !== null ? (int) $request->get('since_hours') : null;
        $sinceId = $request->get('since_id') !== null ? (int) $request->get('since_id') : null;
        $since = $sinceHours !== null ? Carbon::now()->subHours($sinceHours) : null;
        $fullText = (bool) ($request->get('full_text') ?? false);
        $previewLength = max(50, (int) ($request->get('preview_length') ?? 200));

        $posts = $collectionService->fetchRecentPosts($channels, $since, $limit);

        if ($sinceId !== null) {
            $posts = array_values(array_filter($posts, fn (array $p) => (int) ($p['external_id'] ?? 0) > $sinceId));
        }

        $resultsData = $fullText
            ? array_map(fn ($p) => GlobalPostDto::fromArray($p)->toArray(), $posts)
            : array_map(fn ($p) => GlobalPostDto::fromArray($p)->toPreviewArray($previewLength), $posts);

        usort($resultsData, fn (array $a, array $b): int => strcmp((string) ($b['published_at'] ?? ''), (string) ($a['published_at'] ?? '')));

        return Response::structured([
            'channels' => $channels,
            'since_hours' => $sinceHours,
            'since_id' => $sinceId,
            'count' => count($resultsData),
            'posts' => $resultsData,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channels' => $schema->string()
                ->description('Channel username, URL, or comma-separated list (e.g. "@durov, https://t.me/news")')
                ->required(),
            'since_hours' => $schema->integer()
                ->description('Optional: only return posts published in the last N hours (default: no time filter)'),
            'since_id' => $schema->integer()
                ->description('Optional: only return posts with a message ID greater than this (per-channel monotonic increment — for deltas). Best with a single channel.'),
            'limit' => $schema->integer()
                ->description('Maximum number of posts to fetch per channel (default: 100, max: 500)'),
            'full_text' => $schema->boolean()
                ->description('Optional: if true, returns full post text. Default is false (compact preview).'),
            'preview_length' => $schema->integer()
                ->description('Optional: character limit for text preview when full_text is false (default: 200)'),
        ];
    }
}
