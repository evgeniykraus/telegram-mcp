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

#[Description('Extract the author Style DNA and structural blueprint of a target Telegram channel (hook types, formatting dividers, emoji density, signature CTAs, and exemplar posts for few-shot prompt adaptation).')]
class ChannelStyleDnaTool extends Tool
{
    public function handle(Request $request, AnalyticsService $analyticsService): Response|ResponseFactory
    {
        $channel = (string) $request->get('channel');
        if (empty($channel)) {
            return Response::text('Error: "channel" is required.');
        }

        $cleanChannel = TelegramHelper::normalizeUsername($channel) ?? $channel;
        $sampleCount = (int) ($request->get('sample_posts') ?? 30);

        try {
            $dna = $analyticsService->getChannelStyleDna($cleanChannel, $sampleCount);

            return Response::structured($dna->toArray());
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
                ->description('Target channel username or URL (e.g. "@alexmerzliakov" or "https://t.me/alexmerzliakov")')
                ->required(),
            'sample_posts' => $schema->integer()
                ->description('Number of recent posts to analyze for style extraction (default: 30)'),
        ];
    }
}
