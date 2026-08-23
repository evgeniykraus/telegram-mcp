<?php

namespace App\Mcp\Tools;

use App\Services\Analytics\AnalyticsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Fetch user comments and discussion thread for a specific Telegram post by URL or database ID via native MTProto.')]
class GetPostCommentsTool extends Tool
{
    public function handle(Request $request, AnalyticsService $analyticsService): Response|ResponseFactory
    {
        $idOrUrl = $request->get('post_id_or_url');
        if (empty($idOrUrl)) {
            return Response::text('Error: "post_id_or_url" is required (numeric database ID or URL e.g. "https://t.me/channel/123").');
        }

        $limit = max(1, min((int) ($request->get('limit') ?? 20), 200));
        $offsetId = $request->get('offset_id') !== null ? (int) $request->get('offset_id') : null;
        $sinceId = $request->get('since_id') !== null ? (int) $request->get('since_id') : null;
        $format = (string) ($request->get('format') ?? 'compact_text');

        $result = $analyticsService->getPostComments($idOrUrl, $limit, $offsetId, $sinceId);

        if ($format === 'compact_text') {
            if (! empty($result['error'])) {
                return Response::text("Error: {$result['error']}");
            }

            $header = '### Comments for '.($result['channel'] ?? '').' (Post #'.($result['post_id'] ?? '').")\n";
            $header .= 'Total comments in channel: '.($result['total_comments'] ?? 0).' | Showing: '.($result['fetched_count'] ?? 0)."\n";
            if (! empty($result['has_more'])) {
                $header .= 'More comments available — continue with offset_id='.($result['next_offset_id'] ?? '')."\n";
            }
            if (! empty($result['note'])) {
                $header .= 'Note: '.$result['note']."\n";
            }
            $header .= "\n";

            return Response::text($header.($result['thread_dialogue'] ?? ''));
        }

        return Response::structured($result);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id_or_url' => $schema->string()
                ->description('Numeric database ID of the post or Telegram post URL (e.g. "https://t.me/channel/123")')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of comments to fetch (default: 20, max: 200)')
                ->default(20),
            'offset_id' => $schema->integer()
                ->description('Optional: cursor for pagination — pass the next_offset_id from a previous response to fetch the next (older) page'),
            'since_id' => $schema->integer()
                ->description('Optional: only return comments with message ID greater than this (delta — what changed since the last check)'),
            'format' => $schema->string()
                ->description('Output format: "compact_text" (token-efficient readable Markdown dialogue thread) or "raw_json" (structured JSON array)')
                ->enum(['compact_text', 'raw_json'])
                ->default('compact_text'),
        ];
    }
}
