<?php

namespace App\Console\Commands;

use App\Repositories\Source\Contracts\SourceRepositoryInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneExpiredSourcesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postiz:prune
                            {--ttl= : Source TTL days without queries (default from config)}
                            {--dry-run : Only show sources and posts that would be pruned}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune expired channels (idle > TTL days) and cascade delete posts/vectors';

    public function handle(SourceRepositoryInterface $sourceRepository): int
    {
        $ttlDays = (int) ($this->option('ttl') ?? config('analytics.cache.ttl_days', 14));
        $isDryRun = (bool) $this->option('dry-run');

        $expiredSources = $sourceRepository->getExpired($ttlDays);

        if ($expiredSources->isEmpty()) {
            $this->info("No expired channels found (all channels queried within the last {$ttlDays} days or marked as own).");

            return self::SUCCESS;
        }

        $this->info("Found {$expiredSources->count()} expired channel(s) (idle > {$ttlDays} days).");

        if ($isDryRun) {
            $this->table(
                ['ID', 'Username', 'Title', 'Posts Count', 'Last Queried', 'Created At'],
                $expiredSources->map(fn ($s) => [
                    $s->id,
                    $s->username,
                    $s->title,
                    $s->posts()->count(),
                    $s->last_queried_at?->toDateTimeString() ?? 'Never',
                    $s->created_at?->toDateTimeString() ?? 'Unknown',
                ])
            );

            return self::SUCCESS;
        }

        $totalDeletedSources = 0;
        $totalDeletedPosts = 0;

        foreach ($expiredSources as $source) {
            $postsCount = $source->posts()->count();
            $this->line("Pruning expired source {$source->username} ({$postsCount} posts)...");

            $sourceRepository->deleteSourceWithData($source);

            $totalDeletedSources++;
            $totalDeletedPosts += $postsCount;
        }

        // Clean up vacuum for postgres if supported
        try {
            if (DB::getDriverName() === 'pgsql') {
                $this->line('Running VACUUM to reclaim storage space...');
                DB::statement('VACUUM');
            }
        } catch (\Throwable $e) {
            // VACUUM might not be allowed in all transaction contexts, skip silently
        }

        $this->info("Pruning complete. Removed {$totalDeletedSources} source(s) and {$totalDeletedPosts} post(s).");

        return self::SUCCESS;
    }
}
