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

#[Description('Get the full content, text, and engagement metrics for a specific post by its numeric ID or Telegram URL.')]
class GetPostTool extends Tool
{
    public function handle(Request $request, AnalyticsService $analyticsService): Response|ResponseFactory
    {
        $idOrUrl = $request->get('post_id_or_url');
        if (empty($idOrUrl)) {
            return Response::text('Error: "post_id_or_url" is required (numeric database ID or URL e.g. "https://t.me/channel/123").');
        }

        $post = $analyticsService->getPostById($idOrUrl);

        if ($post === null) {
            return Response::text("Error: Post '{$idOrUrl}' not found.");
        }

        return Response::structured($post->toArray());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id_or_url' => $schema->string()
                ->description('Numeric database ID of the post or Telegram post URL (e.g. 101 or "https://t.me/channel/123")')
                ->required(),
        ];
    }
}
