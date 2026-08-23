<?php

namespace Tests\Feature;

use App\Factories\CustomMadelineProtoFactory;
use App\Models\Post;
use App\Models\Source;
use App\Repositories\Post\Contracts\PostRepositoryInterface;
use App\Repositories\Source\Contracts\SourceRepositoryInterface;
use App\Services\Collection\TelegramCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, list<array<string, mixed>>>  $messagesByChannel
     */
    private function makeService(array $messagesByChannel): TelegramCollectionService
    {
        return new class($messagesByChannel, app(CustomMadelineProtoFactory::class), app(SourceRepositoryInterface::class), app(PostRepositoryInterface::class)) extends TelegramCollectionService
        {
            /**
             * @param  array<string, list<array<string, mixed>>>  $messagesByChannel
             */
            public function __construct(
                private array $messagesByChannel,
                CustomMadelineProtoFactory $madelineProtoFactory,
                SourceRepositoryInterface $sourceRepository,
                PostRepositoryInterface $postRepository,
            ) {
                parent::__construct($madelineProtoFactory, $sourceRepository, $postRepository);
            }

            public function fetchMessagesById(string $channelUsername, array $externalIds): array
            {
                $idSet = array_map('intval', $externalIds);

                return array_values(array_filter(
                    $this->messagesByChannel[$channelUsername] ?? [],
                    fn (array $m): bool => in_array((int) ($m['id'] ?? 0), $idSet, true)
                ));
            }
        };
    }

    /**
     * @param  list<array{reaction: array{emoticon: string}, count: int}>  $reactions
     * @return array<string, mixed>
     */
    private function message(int $id, int $views, int $forwards = 0, int $replies = 0, array $reactions = []): array
    {
        return [
            '_' => 'message',
            'id' => $id,
            'date' => time(),
            'message' => "Post {$id}",
            'views' => $views,
            'forwards' => $forwards,
            'replies' => ['replies' => $replies],
            'reactions' => ['results' => $reactions],
        ];
    }

    public function test_command_refreshes_metrics_for_posts_in_last_n_days(): void
    {
        $source = Source::factory()->create(['username' => '@cmdchan']);

        Post::factory()->create([
            'source_id' => $source->id,
            'external_id' => '201',
            'published_at' => now()->subDay(),
            'views' => 10,
            'forwards' => 0,
            'reactions' => 0,
            'comments' => 0,
            'er' => 0.0,
            'content_score' => 0.0,
        ]);
        Post::factory()->create([
            'source_id' => $source->id,
            'external_id' => '202',
            'published_at' => now()->subDays(30),
            'views' => 10,
        ]);

        $this->app->instance(TelegramCollectionService::class, $this->makeService([
            '@cmdchan' => [$this->message(201, 1000, 50, 20, [['reaction' => ['emoticon' => '👍'], 'count' => 100]])],
        ]));

        $this->artisan('postiz:refresh-metrics', ['--days' => 7])
            ->assertExitCode(0);

        $this->assertDatabaseHas('posts', ['source_id' => $source->id, 'external_id' => '201', 'views' => 1000, 'reactions' => 100, 'er' => 0.17]);
        $this->assertDatabaseHas('posts', ['source_id' => $source->id, 'external_id' => '202', 'views' => 10]);
    }

    public function test_command_channel_option_filters_to_one_channel(): void
    {
        $sourceA = Source::factory()->create(['username' => '@chana']);
        $sourceB = Source::factory()->create(['username' => '@chanb']);

        Post::factory()->create([
            'source_id' => $sourceA->id,
            'external_id' => '301',
            'published_at' => now()->subDay(),
            'views' => 10,
            'forwards' => 0,
            'reactions' => 0,
            'comments' => 0,
            'er' => 0.0,
            'content_score' => 0.0,
        ]);
        Post::factory()->create([
            'source_id' => $sourceB->id,
            'external_id' => '302',
            'published_at' => now()->subDay(),
            'views' => 10,
            'forwards' => 0,
            'reactions' => 0,
            'comments' => 0,
            'er' => 0.0,
            'content_score' => 0.0,
        ]);

        $this->app->instance(TelegramCollectionService::class, $this->makeService([
            '@chana' => [$this->message(301, 900)],
            '@chanb' => [$this->message(302, 800)],
        ]));

        $this->artisan('postiz:refresh-metrics', ['--days' => 7, '--channel' => '@chana'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('posts', ['source_id' => $sourceA->id, 'external_id' => '301', 'views' => 900]);
        $this->assertDatabaseHas('posts', ['source_id' => $sourceB->id, 'external_id' => '302', 'views' => 10]);
    }

    public function test_refresh_post_metrics_updates_metrics_by_batch(): void
    {
        $source = Source::factory()->create(['username' => '@refreshchan']);
        Post::factory()->create([
            'source_id' => $source->id,
            'external_id' => '101',
            'views' => 10,
            'forwards' => 0,
            'reactions' => 0,
            'comments' => 0,
            'er' => 0.0,
            'content_score' => 0.0,
        ]);
        Post::factory()->create([
            'source_id' => $source->id,
            'external_id' => '102',
            'views' => 10,
            'forwards' => 0,
            'reactions' => 0,
            'comments' => 0,
            'er' => 0.0,
            'content_score' => 0.0,
        ]);

        $service = $this->makeService([
            '@refreshchan' => [
                $this->message(101, 1000, 50, 20, [['reaction' => ['emoticon' => '👍'], 'count' => 100]]),
                $this->message(102, 500, 10),
            ],
        ]);

        $updated = $service->refreshPostMetrics('@refreshchan', ['101', '102']);

        $this->assertGreaterThan(0, $updated);
        $this->assertDatabaseHas('posts', ['source_id' => $source->id, 'external_id' => '101', 'views' => 1000, 'reactions' => 100, 'er' => 0.17]);
        $this->assertDatabaseHas('posts', ['source_id' => $source->id, 'external_id' => '102', 'views' => 500, 'er' => 0.02]);
    }
}
