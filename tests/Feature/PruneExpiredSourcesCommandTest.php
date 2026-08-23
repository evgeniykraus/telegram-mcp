<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneExpiredSourcesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_command_deletes_expired_sources_and_their_posts(): void
    {
        // 1 active channel (queried recently)
        $activeSource = Source::factory()->create([
            'username' => '@active_chan',
            'last_queried_at' => now()->subDays(2),
        ]);
        Post::factory()->count(3)->create(['source_id' => $activeSource->id]);

        // 1 own channel (queried 30 days ago, but is_own = true so it should be preserved)
        $ownSource = Source::factory()->own()->create([
            'username' => '@my_own_chan',
            'last_queried_at' => now()->subDays(30),
        ]);
        Post::factory()->count(2)->create(['source_id' => $ownSource->id]);

        // 1 expired channel (idle > 14 days, is_own = false)
        $expiredSource = Source::factory()->competitor()->create([
            'username' => '@expired_competitor',
            'last_queried_at' => now()->subDays(20),
        ]);
        Post::factory()->count(4)->create(['source_id' => $expiredSource->id]);

        $this->assertDatabaseCount('sources', 3);
        $this->assertDatabaseCount('posts', 9);

        $this->artisan('postiz:prune', ['--ttl' => 14])
            ->expectsOutput('Found 1 expired channel(s) (idle > 14 days).')
            ->expectsOutput('Pruning expired source @expired_competitor (4 posts)...')
            ->expectsOutput('Pruning complete. Removed 1 source(s) and 4 post(s).')
            ->assertExitCode(0);

        // Expired channel and its posts should be removed
        $this->assertDatabaseMissing('sources', ['id' => $expiredSource->id]);
        $this->assertDatabaseCount('sources', 2);
        $this->assertDatabaseCount('posts', 5);

        // Active and Own sources should still exist
        $this->assertDatabaseHas('sources', ['id' => $activeSource->id]);
        $this->assertDatabaseHas('sources', ['id' => $ownSource->id]);
    }

    public function test_prune_command_dry_run(): void
    {
        Source::factory()->competitor()->create([
            'username' => '@expired_dry',
            'last_queried_at' => now()->subDays(25),
        ]);

        $this->artisan('postiz:prune', ['--dry-run' => true, '--ttl' => 14])
            ->expectsOutput('Found 1 expired channel(s) (idle > 14 days).')
            ->assertExitCode(0);

        $this->assertDatabaseCount('sources', 1);
    }
}
