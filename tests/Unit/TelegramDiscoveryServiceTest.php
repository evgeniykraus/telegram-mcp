<?php

namespace Tests\Unit;

use App\DTOs\Analytics\DiscoveredChannelDto;
use App\DTOs\Analytics\GlobalPostDto;
use App\Factories\CustomMadelineProtoFactory;
use App\Models\Post;
use App\Models\Source;
use App\Repositories\Post\PostRepository;
use App\Repositories\Source\SourceRepository;
use App\Services\Collection\TelegramDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramDiscoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_discover_channels_from_local_database(): void
    {
        Source::factory()->create([
            'username' => '@ai_insider',
            'title' => 'AI Insider Channel',
            'description' => 'Все свежие новости про искусственный интеллект',
            'subscribers_count' => 15000,
        ]);

        Source::factory()->create([
            'username' => '@neural_trends',
            'title' => 'Neural Trends',
            'description' => 'Обзоры нейросетей',
            'subscribers_count' => 25000,
        ]);

        Source::factory()->create([
            'username' => '@cooking_hub',
            'title' => 'Рецепты и кулинария',
            'subscribers_count' => 50000,
        ]);

        $factory = $this->createMock(CustomMadelineProtoFactory::class);
        $factory->method('get')->willThrowException(new \RuntimeException('No active Telegram session'));

        $service = new TelegramDiscoveryService(
            madelineProtoFactory: $factory,
            sourceRepository: new SourceRepository,
            postRepository: new PostRepository,
        );

        $results = $service->discoverChannels('нейросетей', limit: 10, minSubscribers: 5000);

        $this->assertCount(2, $results);
        $this->assertInstanceOf(DiscoveredChannelDto::class, $results[0]);
        $this->assertEquals('@neural_trends', $results[0]->username);
        $this->assertEquals(25000, $results[0]->subscribersCount);
        $this->assertTrue($results[0]->isAlreadyTracked);

        $this->assertEquals('@ai_insider', $results[1]->username);
        $this->assertEquals(15000, $results[1]->subscribersCount);
    }

    public function test_search_global_news_from_local_database(): void
    {
        $source = Source::factory()->create([
            'username' => '@tech_daily',
            'title' => 'Tech Daily',
        ]);

        Post::factory()->create([
            'source_id' => $source->id,
            'text' => 'Релиз новой модели нейросети: все подробности и тесты',
            'views' => 12000,
            'forwards' => 150,
            'reactions' => 300,
            'url' => 'https://t.me/tech_daily/101',
            'published_at' => now()->subHours(5),
        ]);

        Post::factory()->create([
            'source_id' => $source->id,
            'text' => 'Обычный кулинарный пост',
            'views' => 500,
            'url' => 'https://t.me/tech_daily/102',
            'published_at' => now()->subHours(2),
        ]);

        $factory = $this->createMock(CustomMadelineProtoFactory::class);
        $factory->method('get')->willThrowException(new \RuntimeException('No active Telegram session'));

        $service = new TelegramDiscoveryService(
            madelineProtoFactory: $factory,
            sourceRepository: new SourceRepository,
            postRepository: new PostRepository,
        );

        $results = $service->searchGlobalNews('нейросети', limit: 10, sinceHours: 24, minViews: 5000);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(GlobalPostDto::class, $results[0]);
        $this->assertEquals(12000, $results[0]->views);
        $this->assertEquals('@tech_daily', $results[0]->channelUsername);
    }

    public function test_discovered_channel_dto_serialization(): void
    {
        $dto = DiscoveredChannelDto::fromArray([
            'username' => 'testchannel',
            'title' => 'Test Channel Title',
            'description' => 'Test Description',
            'subscribers_count' => 10000,
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
        $this->assertTrue($dto->isVerified);
        $this->assertTrue($dto->isAlreadyTracked);

        $array = $dto->toArray();
        $this->assertIsArray($array);
        $this->assertEquals('@testchannel', $array['username']);
        $this->assertEquals(10000, $array['subscribers_count']);
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
