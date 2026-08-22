<?php

namespace App\Mcp\Tools;

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

#[Description('Collect Telegram channel data (fetches messages, views, forwards, reactions, and comments).')]
class CollectChannelsTool extends Tool
{
    public function handle(Request $request, TelegramCollectionService $collectionService): Response|ResponseFactory
    {
        $channelsInput = $request->get('channels');
        $channels = TelegramHelper::normalizeChannels($channelsInput);

        if (empty($channels)) {
            return Response::text('Error: Please provide at least one channel username or URL (e.g. @channel or https://t.me/channel).');
        }

        $limit = (int) ($request->get('limit') ?? 100);
        $sinceDays = $request->get('days') ?? $request->get('since_days');
        $sinceDaysInt = $sinceDays !== null ? (int) $sinceDays : null;
        $since = $sinceDaysInt !== null ? Carbon::now()->subDays($sinceDaysInt) : null;

        $results = [];
        foreach ($channels as $channel) {
            $run = $collectionService->collectChannel($channel, $limit, $since);
            $results[] = [
                'channel' => $channel,
                'status' => $run->status->value,
                'posts_saved' => $run->posts_saved,
                'posts_fetched' => $run->posts_fetched,
                'error' => $run->error_message,
            ];
        }

        return Response::structured([
            'status' => 'completed',
            'channels' => $results,
            'limit' => $limit,
            'days' => $sinceDaysInt,
            'message' => 'Collection completed for '.count($results).' channel(s).',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channels' => $schema->string()
                ->description('Channel username, URL, or comma-separated list (e.g. "@durov" or "https://t.me/durov, @news")')
                ->required(),
            'limit' => $schema->integer()
                ->description('Number of recent posts to fetch per channel (default: 100, max: 500)'),
            'days' => $schema->integer()
                ->description('Optional: only collect posts published within this number of days'),
        ];
    }
}
