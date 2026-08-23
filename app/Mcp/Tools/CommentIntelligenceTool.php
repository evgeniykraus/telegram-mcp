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

#[Description('Mine the comment thread of a post for rumors and sentiment: top-mentioned districts/areas and dominant complaints.')]
class CommentIntelligenceTool extends Tool
{
    public function handle(Request $request, AnalyticsService $analyticsService): Response|ResponseFactory
    {
        $idOrUrl = $request->get('post_id_or_url');
        if (empty($idOrUrl)) {
            return Response::text('Error: "post_id_or_url" is required (numeric database ID or URL e.g. "https://t.me/channel/123").');
        }

        $maxComments = max(20, min((int) ($request->get('max_comments') ?? 200), 200));

        return Response::structured($analyticsService->getCommentIntelligence($idOrUrl, $maxComments));
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
            'max_comments' => $schema->integer()
                ->description('Maximum number of comments to analyze (default: 200, max: 200)')
                ->default(200),
        ];
    }
}
