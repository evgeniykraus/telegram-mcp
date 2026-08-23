<?php

namespace Tests\Feature;

use App\Enums\CollectionRunStatus;
use App\Models\CollectionRun;
use App\Models\Source;
use App\Services\Analytics\AnalyticsService;
use App\Services\Collection\TelegramCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefreshDatabaseCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_command_runs_for_active_tracked_channels_within_ttl(): void
    {
        // 1 active channel queried 2 days ago (within 14d TTL)
        $activeSource = Source::factory()->create([
            'username' => '@active_chan',
            'is_active' => true,
            'last_queried_at' => now()->subDays(2),
        ]);

        // 1 expired channel queried 20 days ago (outside 14d TTL)
        Source::factory()->create([
            'username' => '@expired_chan',
            'is_active' => true,
            'is_own' => false,
            'last_queried_at' => now()->subDays(20),
        ]);

        $mockCollectionService = $this->createMock(TelegramCollectionService::class);
        $mockAnalyticsService = $this->createMock(AnalyticsService::class);

        $run = new CollectionRun([
            'id' => (string) Str::uuid(),
            'source_id' => $activeSource->id,
            'started_at' => now(),
            'completed_at' => now(),
            'posts_fetched' => 10,
            'posts_saved' => 10,
            'status' => CollectionRunStatus::Success,
        ]);

        // Should only collect for @active_chan, not @expired_chan
        $mockCollectionService->expects($this->once())
            ->method('collectChannel')
            ->with('@active_chan', 50, $this->anything())
            ->willReturn($run);

        $mockAnalyticsService->expects($this->once())
            ->method('embedPendingPosts')
            ->with(100)
            ->willReturn(5);

        $this->app->instance(TelegramCollectionService::class, $mockCollectionService);
        $this->app->instance(AnalyticsService::class, $mockAnalyticsService);

        $this->artisan('postiz:refresh', [
            '--limit' => 50,
            '--days' => 2,
            '--ttl' => 14,
        ])
            ->expectsOutput('Found 1 active tracked channel(s) within 14-day TTL window.')
            ->expectsOutput('✓ @active_chan: fetched 10, saved 10')
            ->expectsOutput('✓ Embedded 5 new post(s).')
            ->assertExitCode(0);
    }

    public function test_refresh_command_dry_run(): void
    {
        Source::factory()->create([
            'username' => '@dry_chan',
            'is_active' => true,
            'last_queried_at' => now(),
        ]);

        $this->artisan('postiz:refresh', ['--dry-run' => true])
            ->expectsOutput('Found 1 active tracked channel(s) within 14-day TTL window.')
            ->assertExitCode(0);
    }
}
