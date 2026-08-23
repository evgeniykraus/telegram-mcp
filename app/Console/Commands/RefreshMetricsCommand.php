<?php

namespace App\Console\Commands;

use App\Services\Collection\TelegramCollectionService;
use Illuminate\Console\Command;

class RefreshMetricsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postiz:refresh-metrics
                            {--days= : Number of days of posts to refresh metrics for (default from config)}
                            {--batch= : Number of posts per batch API request (default from config)}
                            {--channel= : Only refresh a specific channel username or URL}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh views/forwards/reactions metrics for posts from the last N days';

    public function handle(TelegramCollectionService $collectionService): int
    {
        $days = (int) ($this->option('days') ?? config('analytics.cache.metrics_refresh_days', 7));
        $batch = (int) ($this->option('batch') ?? config('analytics.cache.metrics_batch_size', 100));
        $channel = $this->option('channel') ?: null;

        $updated = $collectionService->refreshMetrics($days, $batch, $channel);

        $this->info("Metrics refresh complete. Updated {$updated} post(s).");

        return self::SUCCESS;
    }
}
