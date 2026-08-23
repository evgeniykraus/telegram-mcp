<?php

namespace Tests\Unit;

use App\Enums\ContentFormat;
use App\Factories\CustomMadelineProtoFactory;
use App\Repositories\Post\Contracts\PostRepositoryInterface;
use App\Repositories\Source\Contracts\SourceRepositoryInterface;
use App\Services\Collection\TelegramCollectionService;
use Carbon\Carbon;
use Tests\TestCase;

class TelegramCollectionServiceTest extends TestCase
{
    private TelegramCollectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = $this->createMock(CustomMadelineProtoFactory::class);
        $sourceRepo = $this->createMock(SourceRepositoryInterface::class);
        $postRepo = $this->createMock(PostRepositoryInterface::class);

        $this->service = new TelegramCollectionService($factory, $sourceRepo, $postRepo);
    }

    public function test_detect_ad_patterns(): void
    {
        $this->assertTrue($this->service->detectAd('Купите наш курс erid: 2Vtzqw123'));
        $this->assertTrue($this->service->detectAd('Скидка 50% по промокоду #реклама'));
        $this->assertTrue($this->service->detectAd('Информация о товаре. ЕРИД: 123456'));
        $this->assertTrue($this->service->detectAd('Реклама. ООО "Ромашка" ИНН 1234567890'));
        $this->assertTrue($this->service->detectAd('Спецпредложение токен: 998877'));

        $this->assertFalse($this->service->detectAd('Просто интересный пост об архитектуре Laravel.'));
        $this->assertFalse($this->service->detectAd(''));
    }

    public function test_detect_format(): void
    {
        $this->assertSame(
            ContentFormat::Text->value,
            $this->service->detectFormat(['message' => 'Hello'])
        );

        $this->assertSame(
            ContentFormat::Photo->value,
            $this->service->detectFormat(['media' => ['_' => 'messageMediaPhoto']])
        );

        $this->assertSame(
            ContentFormat::Poll->value,
            $this->service->detectFormat(['media' => ['_' => 'messageMediaPoll']])
        );

        $this->assertSame(
            ContentFormat::Video->value,
            $this->service->detectFormat([
                'media' => [
                    '_' => 'messageMediaDocument',
                    'document' => ['mime_type' => 'video/mp4'],
                ],
            ])
        );

        $this->assertSame(
            ContentFormat::Document->value,
            $this->service->detectFormat([
                'media' => [
                    '_' => 'messageMediaDocument',
                    'document' => ['mime_type' => 'application/pdf'],
                ],
            ])
        );

        $this->assertSame(
            ContentFormat::Link->value,
            $this->service->detectFormat(['media' => ['_' => 'messageMediaWebPage']])
        );
    }

    public function test_normalize_message(): void
    {
        $rawMessage = [
            'id' => 12345,
            'date' => 1700000000,
            'message' => 'Great news today! Check this out. erid: abc123',
            'views' => 1000,
            'forwards' => 50,
            'replies' => ['replies' => 20],
            'reactions' => [
                'results' => [
                    ['reaction' => ['emoticon' => '👍'], 'count' => 80],
                    ['reaction' => ['emoticon' => '🔥'], 'count' => 20],
                ],
            ],
            'media' => ['_' => 'messageMediaPhoto'],
        ];

        $normalized = $this->service->normalizeMessage($rawMessage, 1, '@testchannel');

        $this->assertNotNull($normalized);
        $this->assertSame(1, $normalized['source_id']);
        $this->assertSame('12345', $normalized['external_id']);
        $this->assertSame(1000, $normalized['views']);
        $this->assertSame(50, $normalized['forwards']);
        $this->assertSame(20, $normalized['comments']);
        $this->assertSame(100, $normalized['reactions']);
        $this->assertEquals(['👍' => 80, '🔥' => 20], $normalized['reactions_breakdown']);
        $this->assertSame(ContentFormat::Photo->value, $normalized['format_type']);
        $this->assertTrue($normalized['is_ad']);
        $this->assertEquals(0.17, $normalized['er']);
        $this->assertGreaterThan(0, $normalized['content_score']);
    }

    public function test_paginate_history_collects_more_than_100_posts(): void
    {
        $result = $this->service->paginateHistory($this->historyFetcher($this->makeMessages(150, time())), 150);

        $this->assertCount(150, $result);
    }

    public function test_paginate_history_stops_at_since(): void
    {
        $now = time();
        $since = Carbon::createFromTimestamp($now - (10 * 60));

        $result = $this->service->paginateHistory($this->historyFetcher($this->makeMessages(100, $now, 60)), 1000, $since);

        $this->assertCount(11, $result);
    }

    public function test_paginate_history_stops_at_limit(): void
    {
        $result = $this->service->paginateHistory($this->historyFetcher($this->makeMessages(300, time(), 60)), 50);

        $this->assertCount(50, $result);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function makeMessages(int $count, int $startTs, int $step = 60): array
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $messages[] = [
                '_' => 'message',
                'id' => $count - $i,
                'date' => $startTs - ($i * $step),
                'message' => "Post {$i}",
                'views' => 100 + $i,
            ];
        }

        return $messages;
    }

    /**
     * @param  list<array<string, mixed>>  $messages  newest-first
     */
    private function historyFetcher(array $messages): callable
    {
        return function (int $offsetId, int $limit) use ($messages): array {
            $batch = $messages;
            if ($offsetId > 0) {
                $batch = array_values(array_filter($batch, fn (array $m): bool => (int) ($m['id'] ?? 0) < $offsetId));
            }

            return array_slice($batch, 0, $limit);
        };
    }
}
