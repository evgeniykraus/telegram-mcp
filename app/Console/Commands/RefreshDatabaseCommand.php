<?php

namespace App\Console\Commands;

use App\Repositories\Source\Contracts\SourceRepositoryInterface;
use App\Services\Analytics\AnalyticsService;
use App\Services\Collection\TelegramCollectionService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RefreshDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postiz:refresh
                            {--limit= : Number of posts to fetch per channel (default from config)}
                            {--days= : Number of days of history to fetch (default from config)}
                            {--ttl= : Source TTL days (default from config)}
                            {--dry-run : Only list active tracked channels without collecting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Hourly refresh of active tracked Telegram channels and vector embeddings';

    public function handle(
        SourceRepositoryInterface $sourceRepository,
        TelegramCollectionService $collectionService,
        AnalyticsService $analyticsService
    ): int {
        $ttlDays = (int) ($this->option('ttl') ?? config('analytics.cache.ttl_days', 14));
        $limit = (int) ($this->option('limit') ?? config('analytics.cache.refresh_limit', 50));
        $days = (int) ($this->option('days') ?? config('analytics.cache.refresh_since_days', 2));
        $isDryRun = (bool) $this->option('dry-run');

        $activeSources = $sourceRepository->getActiveTracked($ttlDays);

        if ($activeSources->isEmpty()) {
            $this->info('No active tracked channels found within TTL window ('.$ttlDays.' days).');

            return self::SUCCESS;
        }

        $this->info("Found {$activeSources->count()} active tracked channel(s) within {$ttlDays}-day TTL window.");

        if ($isDryRun) {
            $this->table(
                ['ID', 'Username', 'Title', 'Is Own', 'Last Queried', 'Last Collected'],
                $activeSources->map(fn ($s) => [
                    $s->id,
                    $s->username,
                    $s->title,
                    $s->is_own ? 'Yes' : 'No',
                    $s->last_queried_at?->toDateTimeString() ?? 'Never',
                    $s->last_collected_at?->toDateTimeString() ?? 'Never',
                ])
            );

            return self::SUCCESS;
        }

        $since = Carbon::now()->subDays($days);
        $successCount = 0;
        $failedCount = 0;

        foreach ($activeSources as $source) {
            $this->line("Refreshing {$source->username}...");
            $run = $collectionService->collectChannel($source->username, $limit, $since);

            if ($run->status->value === 'success') {
                $this->info("✓ {$source->username}: fetched {$run->posts_fetched}, saved {$run->posts_saved}");
                $successCount++;
            } else {
                $this->error("✗ {$source->username}: failed - {$run->error_message}");
                $failedCount++;
            }
        }

        // Generate vector embeddings for newly collected posts
        $this->line('Generating vector embeddings for pending posts...');
        $embeddedCount = $analyticsService->embedPendingPosts(100);
        if ($embeddedCount > 0) {
            $this->info("✓ Embedded {$embeddedCount} new post(s).");
        }

        $this->info("Refresh complete. Success: {$successCount}, Failed: {$failedCount}, Embedded: {$embeddedCount}.");

        return self::SUCCESS;
    }
}
