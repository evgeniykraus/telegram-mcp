<?php

namespace App\Console\Commands;

use App\Services\Collection\TelegramCollectionService;
use App\Support\TelegramHelper;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CollectChannelsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postiz:collect
                            {--channels= : Comma-separated list of channel usernames or URLs}
                            {--limit=100 : Number of posts to fetch per channel}
                            {--days= : Only collect posts published within this number of days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Collect Telegram channel posts and metrics synchronously';

    public function handle(TelegramCollectionService $collectionService): int
    {
        $channelsInput = (string) $this->option('channels');
        if ($channelsInput === '') {
            $this->error('Please specify channels using --channels=@channel1,@channel2 or URLs');

            return self::FAILURE;
        }

        $channels = TelegramHelper::normalizeChannels($channelsInput);
        if (empty($channels)) {
            $this->error('No valid channels found in input.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $days = $this->option('days');
        $since = $days !== null ? Carbon::now()->subDays((int) $days) : null;

        $this->info('Starting synchronous collection for '.count($channels).' channel(s)...');

        foreach ($channels as $channel) {
            $this->line("Collecting {$channel}...");
            $run = $collectionService->collectChannel($channel, $limit, $since);

            if ($run->status->value === 'success') {
                $this->info("✓ {$channel}: fetched {$run->posts_fetched}, saved {$run->posts_saved}");
            } else {
                $this->error("✗ {$channel}: failed - {$run->error_message}");
            }
        }

        return self::SUCCESS;
    }
}
