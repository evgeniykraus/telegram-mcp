<?php

namespace Tests\Feature;

use App\Enums\CollectionRunStatus;
use App\Models\CollectionRun;
use App\Models\Source;
use App\Services\Collection\TelegramCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CollectChannelsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_requires_channels_option(): void
    {
        $this->artisan('postiz:collect')
            ->expectsOutput('Please specify channels using --channels=@channel1,@channel2 or URLs')
            ->assertExitCode(1);
    }

    public function test_command_runs_synchronously(): void
    {
        $mockService = $this->createMock(TelegramCollectionService::class);
        $source = Source::factory()->create(['username' => '@testchan']);

        $run = new CollectionRun([
            'id' => (string) Str::uuid(),
            'source_id' => $source->id,
            'started_at' => now(),
            'completed_at' => now(),
            'posts_fetched' => 20,
            'posts_saved' => 18,
            'status' => CollectionRunStatus::Success,
        ]);

        $mockService->expects($this->once())
            ->method('collectChannel')
            ->with('@testchan', 100, null)
            ->willReturn($run);

        $this->app->instance(TelegramCollectionService::class, $mockService);

        $this->artisan('postiz:collect', [
            '--channels' => 'https://t.me/testchan',
        ])
            ->expectsOutput('Starting synchronous collection for 1 channel(s)...')
            ->expectsOutput('✓ @testchan: fetched 20, saved 18')
            ->assertExitCode(0);
    }
}
