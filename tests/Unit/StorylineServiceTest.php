<?php

namespace Tests\Unit;

use App\Models\Post;
use App\Models\Source;
use App\Repositories\Post\PostRepository;
use App\Services\Pipeline\EmbeddingService;
use App\Services\Storyline\StorylineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorylineServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cluster_storylines_groups_similar_posts_and_corroborates_channels(): void
    {
        $channelA = Source::factory()->create(['username' => '@channelA']);
        $channelB = Source::factory()->create(['username' => '@channelB']);

        Post::factory()->create([
            'source_id' => $channelA->id,
            'external_id' => '1',
            'text' => 'Запущена новая модель ИИ',
            'published_at' => now()->subHours(3),
            'embedding' => [1.0, 0.0],
        ]);
        Post::factory()->create([
            'source_id' => $channelB->id,
            'external_id' => '2',
            'text' => 'Вышла новая модель ИИ',
            'published_at' => now()->subHours(2),
            'embedding' => [0.9, 0.1],
        ]);
        Post::factory()->create([
            'source_id' => $channelB->id,
            'external_id' => '3',
            'text' => 'Курс биткоина резко упал',
            'published_at' => now()->subHour(),
            'embedding' => [0.0, 1.0],
        ]);

        $service = new StorylineService(new PostRepository, $this->createMock(EmbeddingService::class));

        $result = $service->clusterStorylines(days: 3, limit: 200, minClusterSize: 2);

        $this->assertSame(1, $result['storylines_count']);
        $storyline = $result['storylines'][0];
        $this->assertTrue($storyline['corroborated']);
        $this->assertSame(2, $storyline['post_count']);
        $this->assertContains('@channelA', $storyline['channels']);
        $this->assertContains('@channelB', $storyline['channels']);
    }

    public function test_cluster_storylines_filters_by_query(): void
    {
        $channelA = Source::factory()->create(['username' => '@channelA']);

        Post::factory()->create([
            'source_id' => $channelA->id,
            'external_id' => '1',
            'text' => 'Запущена новая модель ИИ',
            'published_at' => now()->subHour(),
            'embedding' => [1.0, 0.0],
        ]);
        Post::factory()->create([
            'source_id' => $channelA->id,
            'external_id' => '2',
            'text' => 'Курс биткоина резко упал',
            'published_at' => now()->subHour(),
            'embedding' => [0.0, 1.0],
        ]);

        $service = new StorylineService(new PostRepository, $this->createMock(EmbeddingService::class));

        $result = $service->clusterStorylines(query: 'биткоин', days: 3, limit: 200, minClusterSize: 1);

        $this->assertSame(1, $result['storylines_count']);
        $this->assertStringContainsString('биткоин', $result['storylines'][0]['headline']);
    }
}
