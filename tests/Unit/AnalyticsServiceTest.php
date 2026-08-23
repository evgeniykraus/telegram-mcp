<?php

namespace Tests\Unit;

use App\Models\Post;
use App\Models\Source;
use App\Repositories\Analytics\Contracts\AnalyticsRepositoryInterface;
use App\Repositories\Post\Contracts\PostRepositoryInterface;
use App\Repositories\Source\Contracts\SourceRepositoryInterface;
use App\Services\Analytics\AnalyticsService;
use App\Services\Collection\TelegramCollectionService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private AnalyticsService $service;

    private $analyticsRepo;

    private $sourceRepo;

    private $postRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyticsRepo = $this->createMock(AnalyticsRepositoryInterface::class);
        $this->sourceRepo = $this->createMock(SourceRepositoryInterface::class);
        $this->postRepo = $this->createMock(PostRepositoryInterface::class);

        $this->service = new AnalyticsService(
            $this->analyticsRepo,
            $this->sourceRepo,
            $this->postRepo
        );
    }

    public function test_get_channel_report_success(): void
    {
        $source = Source::factory()->create([
            'username' => '@techchannel',
            'title' => 'Tech Channel',
            'subscribers_count' => 10000,
        ]);

        $this->sourceRepo->method('findByUsername')
            ->with('@techchannel')
            ->willReturn($source);

        $this->analyticsRepo->method('getChannelStats')
            ->with($source->id, 30)
            ->willReturn([
                'total_posts' => 50,
                'total_views' => 100000,
                'avg_views' => 2000.0,
                'avg_er' => 0.05,
                'best_er' => 0.15,
                'total_forwards' => 1500,
                'total_reactions' => 3000,
                'total_comments' => 500,
                'best_post' => null,
            ]);

        $report = $this->service->getChannelReport('@techchannel', 30);

        $this->assertSame($source->id, $report->sourceId);
        $this->assertSame('@techchannel', $report->username);
        $this->assertSame(50, $report->totalPosts);
        $this->assertSame(100000, $report->totalViews);
        $this->assertSame(0.05, $report->avgEr);
    }

    public function test_get_channel_report_throws_on_missing_source(): void
    {
        $this->sourceRepo->method('findByUsername')
            ->with('@nonexistent')
            ->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->getChannelReport('@nonexistent');
    }

    public function test_get_top_posts(): void
    {
        $source = Source::factory()->create(['username' => '@postchan']);
        $post = Post::factory()->create([
            'source_id' => $source->id,
            'external_id' => '999',
            'views' => 5000,
            'forwards' => 200,
            'reactions' => 300,
            'comments' => 50,
            'er' => 0.11,
            'content_score' => 45.5,
            'is_ad' => false,
        ]);

        $this->postRepo->method('getTopPosts')
            ->willReturn(new EloquentCollection([$post]));

        $topPosts = $this->service->getTopPosts(5);

        $this->assertCount(1, $topPosts);
        $this->assertSame($post->id, $topPosts[0]->id);
        $this->assertSame(5000, $topPosts[0]->views);
        $this->assertSame(0.11, $topPosts[0]->er);
    }

    public function test_get_comment_intelligence_extracts_districts_and_complaints(): void
    {
        $collectionService = $this->createMock(TelegramCollectionService::class);
        $collectionService->method('fetchPostComments')->willReturn([
            'channel' => '@chan',
            'post_id' => 100,
            'total_comments' => 2,
            'fetched_count' => 2,
            'thread_dialogue' => '',
            'comments' => [
                ['text' => 'В центре города не работает интернет'],
                ['text' => 'У нас тоже сбой, жалобы по всему району'],
            ],
        ]);

        $service = new AnalyticsService(
            $this->analyticsRepo,
            $this->sourceRepo,
            $this->postRepo,
            null,
            $collectionService,
        );

        $result = $service->getCommentIntelligence('https://t.me/chan/100', 200);

        $this->assertSame(2, $result['analyzed_comments']);
        $this->assertSame(1, $result['top_districts'][0]['mentions'] ?? null);
        $this->assertSame('центр', $result['top_districts'][0]['district'] ?? null);
        $this->assertNotEmpty($result['dominant_complaints']);
    }
}
