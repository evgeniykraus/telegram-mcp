<?php

namespace Tests\Unit;

use App\Services\Pipeline\EmbeddingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmbeddingServiceTest extends TestCase
{
    public function test_embed_text_via_mocked_api(): void
    {
        $fakeVector = array_fill(0, 1024, 0.05);

        Http::fake([
            '*' => Http::response([
                'data' => [
                    [
                        'embedding' => $fakeVector,
                    ],
                ],
            ], 200),
        ]);

        $service = new EmbeddingService;
        $embedding = $service->embedText('Искусственный интеллект и аналитика');

        $this->assertNotNull($embedding);
        $this->assertCount(1024, $embedding);
        $this->assertSame(0.05, $embedding[0]);
    }

    public function test_embed_batch(): void
    {
        $fakeVector1 = array_fill(0, 1024, 0.1);
        $fakeVector2 = array_fill(0, 1024, 0.2);

        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['embedding' => $fakeVector1],
                    ['embedding' => $fakeVector2],
                ],
            ], 200),
        ]);

        $service = new EmbeddingService;
        $results = $service->embedBatch(['Пост 1', 'Пост 2']);

        $this->assertCount(2, $results);
        $this->assertSame(0.1, $results[0][0]);
        $this->assertSame(0.2, $results[1][0]);
    }

    public function test_embed_empty_text_returns_null(): void
    {
        $service = new EmbeddingService;
        $this->assertNull($service->embedText('   '));
    }
}
