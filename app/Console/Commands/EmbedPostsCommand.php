<?php

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsService;
use Illuminate\Console\Command;

class EmbedPostsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postiz:embed
                            {--limit=100 : Number of posts to process per run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate vector embeddings (bge-m3) for posts without embeddings';

    public function handle(AnalyticsService $analyticsService): int
    {
        $limit = (int) $this->option('limit');
        $this->info("Generating embeddings for up to {$limit} posts...");

        $count = $analyticsService->embedPendingPosts($limit);

        $this->info("Successfully generated embeddings for {$count} posts.");

        return self::SUCCESS;
    }
}
