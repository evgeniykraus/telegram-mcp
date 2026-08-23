<?php

namespace Tests\Unit;

use App\DTOs\Analytics\DiscoveredChannelDto;
use App\DTOs\Analytics\GlobalPostDto;
use App\Factories\CustomMadelineProtoFactory;
use App\Repositories\Post\PostRepository;
use App\Repositories\Source\SourceRepository;
use App\Services\Collection\TelegramDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramDiscoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_handles_empty_or_failed_api(): void
    {
        $factory = $this->createMock(CustomMadelineProtoFactory::class);
        $factory->method('get')->willThrowException(new \RuntimeException('No active Telegram session'));

        $service = new TelegramDiscoveryService(
            madelineProtoFactory: $factory,
            sourceRepository: new SourceRepository,
            postRepository: new PostRepository,
        );

        $channels = $service->discoverChannels('нейросети', limit: 10);
        $this->assertIsArray($channels);
        $this->assertEmpty($channels);

        $posts = $service->searchGlobalNews('нейросети', limit: 10);
        $this->assertIsArray($posts);
        $this->assertEmpty($posts);
    }

    public function test_discovered_channel_dto_serialization(): void
    {
        $dto = DiscoveredChannelDto::fromArray([
            'username' => 'testchannel',
            'title' => 'Test Channel Title',
            'description' => 'Test Description',
            'subscribers_count' => 10000,
            'recent_avg_views' => 2500,
            'recent_er' => 0.045,
            'last_post_at' => '2026-08-23 15:30:00',
            'is_verified' => true,
            'is_scam' => false,
            'is_fake' => false,
            'url' => 'https://t.me/testchannel',
            'is_already_tracked' => true,
            'last_collected_at' => '2026-08-23 12:00:00',
            'posts_count' => 50,
        ]);

        $this->assertEquals('@testchannel', $dto->username);
        $this->assertEquals('https://t.me/testchannel', $dto->url);
        $this->assertEquals(2500, $dto->recentAvgViews);
        $this->assertEquals(0.045, $dto->recentEr);
        $this->assertEquals('2026-08-23 15:30:00', $dto->lastPostAt);
        $this->assertTrue($dto->isVerified);
        $this->assertTrue($dto->isAlreadyTracked);

        $array = $dto->toArray();
        $this->assertIsArray($array);
        $this->assertEquals('@testchannel', $array['username']);
        $this->assertEquals(10000, $array['subscribers_count']);
        $this->assertEquals(2500, $array['recent_avg_views']);
        $this->assertEquals(0.045, $array['recent_er']);
    }

    public function test_global_post_dto_serialization(): void
    {
        $dto = GlobalPostDto::fromArray([
            'id' => 999,
            'channel_username' => 'testchannel',
            'channel_title' => 'Test Channel',
            'url' => 'https://t.me/testchannel/999',
            'published_at' => '2026-08-23 12:00:00',
            'text' => str_repeat('Текст поста для проверки превью. ', 15),
            'views' => 5000,
            'forwards' => 100,
            'reactions' => 200,
            'er' => 0.06,
            'content_score' => 45.0,
        ]);

        $this->assertEquals('@testchannel', $dto->channelUsername);
        $this->assertEquals(5000, $dto->views);

        $preview = $dto->toPreviewArray(50);
        $this->assertTrue($preview['is_truncated']);
        $this->assertLessThanOrEqual(53, mb_strlen($preview['text_preview']));
    }
}
