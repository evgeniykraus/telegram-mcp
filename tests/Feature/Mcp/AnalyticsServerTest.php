<?php

namespace Tests\Feature\Mcp;

use App\Enums\CollectionRunStatus;
use App\Mcp\Resources\BestPublishingTimesResource;
use App\Mcp\Resources\ChannelsResource;
use App\Mcp\Servers\AnalyticsServer;
use App\Mcp\Tools\BenchmarkTool;
use App\Mcp\Tools\BestTimeToPostTool;
use App\Mcp\Tools\ChannelReportTool;
use App\Mcp\Tools\ChannelStyleDnaTool;
use App\Mcp\Tools\CollectChannelsTool;
use App\Mcp\Tools\FindOutliersTool;
use App\Mcp\Tools\FormatBreakdownTool;
use App\Mcp\Tools\GetPostCommentsTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\ReportTool;
use App\Mcp\Tools\SearchSimilarPostsTool;
use App\Mcp\Tools\TopicSaturationTool;
use App\Mcp\Tools\TopPostsTool;
use App\Models\CollectionRun;
use App\Models\Post;
use App\Models\Source;
use App\Services\Collection\TelegramCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnalyticsServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_collect_channels_tool(): void
    {
        $mockService = $this->createMock(TelegramCollectionService::class);
        $run = new CollectionRun([
            'status' => CollectionRunStatus::Success,
            'posts_saved' => 10,
            'posts_fetched' => 10,
        ]);
        $mockService->method('collectChannel')->willReturn($run);
        $this->app->instance(TelegramCollectionService::class, $mockService);

        $response = AnalyticsServer::tool(CollectChannelsTool::class, [
            'channels' => 'https://t.me/channel1, @channel2',
            'limit' => 50,
        ]);

        $response->assertOk();
        $response->assertSee('completed');
        $response->assertSee('@channel1');
        $response->assertSee('@channel2');
    }

    public function test_channel_report_tool(): void
    {
        $source = Source::factory()->create([
            'username' => '@testreport',
            'title' => 'Test Channel',
            'subscribers_count' => 5000,
        ]);

        Post::factory()->count(5)->create([
            'source_id' => $source->id,
            'views' => 1000,
            'forwards' => 50,
            'reactions' => 100,
            'comments' => 10,
            'er' => 0.16,
        ]);

        $response = AnalyticsServer::tool(ChannelReportTool::class, [
            'channel' => 'https://t.me/testreport',
            'days' => 30,
        ]);

        $response->assertOk();
        $response->assertSee('@testreport');
    }

    public function test_channel_report_tool_with_auto_collect(): void
    {
        $mockService = $this->createMock(TelegramCollectionService::class);
        $mockService->expects($this->once())
            ->method('collectChannel')
            ->willReturnCallback(function ($username) {
                Source::factory()->create(['username' => $username, 'subscribers_count' => 1200]);

                return new CollectionRun([
                    'status' => CollectionRunStatus::Success,
                    'posts_saved' => 5,
                    'posts_fetched' => 5,
                ]);
            });
        $this->app->instance(TelegramCollectionService::class, $mockService);

        $response = AnalyticsServer::tool(ChannelReportTool::class, [
            'channel' => '@newautochannel',
            'days' => 30,
            'auto_collect' => true,
        ]);

        $response->assertOk();
        $response->assertSee('@newautochannel');
    }

    public function test_get_post_tool(): void
    {
        $source = Source::factory()->create(['username' => '@getpostchan']);
        $post = Post::factory()->create([
            'source_id' => $source->id,
            'external_id' => '999',
            'url' => 'https://t.me/getpostchan/999',
            'text' => 'Полный подробный текст уникального поста для проверки GetPostTool',
            'views' => 1500,
        ]);

        // By ID
        $responseById = AnalyticsServer::tool(GetPostTool::class, [
            'post_id_or_url' => (string) $post->id,
        ]);
        $responseById->assertOk();
        $responseById->assertSee('Полный подробный текст уникального поста');

        // By URL
        $responseByUrl = AnalyticsServer::tool(GetPostTool::class, [
            'post_id_or_url' => 'https://t.me/getpostchan/999',
        ]);
        $responseByUrl->assertOk();
        $responseByUrl->assertSee('Полный подробный текст уникального поста');
    }

    public function test_top_posts_tool(): void
    {
        $source = Source::factory()->create(['username' => '@topchannel']);
        Post::factory()->count(5)->create([
            'source_id' => $source->id,
            'content_score' => 88.5,
            'text' => str_repeat('Текст поста для проверки превью. ', 20),
        ]);

        // Preview mode by default (full_text: false)
        $response = AnalyticsServer::tool(TopPostsTool::class, [
            'channel' => 'https://t.me/topchannel',
            'limit' => 5,
            'sort_by' => 'content_score',
        ]);

        $response->assertOk();
        $response->assertSee('text_preview');
        $response->assertSee('is_truncated');

        // Full text mode
        $responseFull = AnalyticsServer::tool(TopPostsTool::class, [
            'channel' => '@topchannel',
            'limit' => 5,
            'full_text' => true,
        ]);
        $responseFull->assertOk();
        $responseFull->assertDontSee('text_preview');
    }

    public function test_format_breakdown_tool(): void
    {
        $source = Source::factory()->create(['username' => '@formatchannel']);
        Post::factory()->create([
            'source_id' => $source->id,
            'format_type' => 'photo',
            'views' => 2000,
        ]);
        Post::factory()->create([
            'source_id' => $source->id,
            'format_type' => 'video',
            'views' => 4000,
        ]);

        $response = AnalyticsServer::tool(FormatBreakdownTool::class, [
            'channel' => '@formatchannel',
            'days' => 30,
        ]);

        $response->assertOk();
        $response->assertSee('photo');
        $response->assertSee('video');
    }

    public function test_best_time_to_post_tool(): void
    {
        $source = Source::factory()->create(['username' => '@timechannel']);
        Post::factory()->count(5)->create([
            'source_id' => $source->id,
            'published_at' => now(),
        ]);

        $response = AnalyticsServer::tool(BestTimeToPostTool::class, [
            'channel' => '@timechannel',
            'days' => 30,
        ]);

        $response->assertOk();
        $response->assertSee('top_recommendations');
    }

    public function test_benchmark_tool(): void
    {
        $own = Source::factory()->own()->create(['category' => 'tech']);
        $comp = Source::factory()->competitor()->create(['category' => 'tech']);

        Post::factory()->count(3)->create(['source_id' => $own->id, 'views' => 5000, 'er' => 0.08]);
        Post::factory()->count(3)->create(['source_id' => $comp->id, 'views' => 3000, 'er' => 0.04]);

        $response = AnalyticsServer::tool(BenchmarkTool::class, [
            'category' => 'tech',
            'days' => 30,
        ]);

        $response->assertOk();
        $response->assertSee('own_avg_er');
        $response->assertSee('competitor_avg_er');
    }

    public function test_report_tool(): void
    {
        $source = Source::factory()->create(['username' => '@fullreport']);
        Post::factory()->count(5)->create(['source_id' => $source->id]);

        $response = AnalyticsServer::tool(ReportTool::class, [
            'channel' => '@fullreport',
            'days' => 30,
        ]);

        $response->assertOk();
        $response->assertSee('top_posts');
        $response->assertSee('formats');
    }

    public function test_channels_resource(): void
    {
        Source::factory()->create(['username' => '@channel_res1']);
        Source::factory()->create(['username' => '@channel_res2']);

        $response = AnalyticsServer::resource(ChannelsResource::class);

        $response->assertOk();
        $response->assertSee('@channel_res1');
        $response->assertSee('@channel_res2');
    }

    public function test_best_publishing_times_resource(): void
    {
        $source = Source::factory()->create();
        Post::factory()->count(3)->create(['source_id' => $source->id]);

        $response = AnalyticsServer::resource(BestPublishingTimesResource::class);

        $response->assertOk();
        $response->assertSee('top_recommendations');
    }

    public function test_search_similar_posts_tool(): void
    {
        $fakeVector = array_fill(0, 1024, 0.05);

        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => $fakeVector],
                ],
            ], 200),
        ]);

        $source = Source::factory()->create(['username' => '@searchchan']);
        Post::factory()->create([
            'source_id' => $source->id,
            'text' => 'Искусственный интеллект и разработка',
            'embedding' => $fakeVector,
        ]);

        $response = AnalyticsServer::tool(SearchSimilarPostsTool::class, [
            'query_or_post_id' => 'новости ИИ',
            'limit' => 5,
        ]);

        $response->assertOk();
        $response->assertSee('results');
    }

    public function test_find_outliers_tool(): void
    {
        $source = Source::factory()->create(['username' => '@outlierchan']);
        // 4 baseline posts with 1000 views
        Post::factory()->count(4)->create([
            'source_id' => $source->id,
            'views' => 1000,
            'published_at' => now()->subDays(2),
            'text' => 'Обычный регулярный пост канала',
        ]);
        // 1 viral outlier with 5000 views (5x median)
        Post::factory()->create([
            'source_id' => $source->id,
            'views' => 5000,
            'published_at' => now()->subDays(1),
            'text' => '🔥 Сенсационный хук: как вырасти в 5 раз за вечер',
        ]);

        $response = AnalyticsServer::tool(FindOutliersTool::class, [
            'channel' => '@outlierchan',
            'min_multiplier' => 2.0,
            'days' => 14,
        ]);

        $response->assertOk();
        $response->assertSee('outliers');
        $response->assertSee('Сенсационный хук');
    }

    public function test_topic_saturation_tool(): void
    {
        $fakeVector = array_fill(0, 1024, 0.08);

        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => $fakeVector],
                ],
            ], 200),
        ]);

        $source = Source::factory()->create(['username' => '@saturationchan']);
        Post::factory()->count(3)->create([
            'source_id' => $source->id,
            'text' => 'Нейросети и лид-магниты для воронки',
            'embedding' => $fakeVector,
            'published_at' => now()->subDays(2),
        ]);

        $response = AnalyticsServer::tool(TopicSaturationTool::class, [
            'topic_query' => 'лид-магниты в воронках',
            'days' => 14,
        ]);

        $response->assertOk();
        $response->assertSee('saturation_level');
        $response->assertSee('recommendation');
    }

    public function test_channel_style_dna_tool(): void
    {
        $source = Source::factory()->create(['username' => '@stylednachan']);
        Post::factory()->create([
            'source_id' => $source->id,
            'text' => "🔖🔖 Кейс: как я заработал 500 000 ₽ на ботах\n\nПервый абзац с пользой.\n\n━━━━━━━━━━━━━━\n\n— Первый пункт списка\n— Второй пункт списка\n\nЖми на кнопку 👇",
            'views' => 4500,
        ]);

        $response = AnalyticsServer::tool(ChannelStyleDnaTool::class, [
            'channel' => '@stylednachan',
        ]);

        $response->assertOk();
        $response->assertSee('avg_post_length_chars');
        $response->assertSee('emoji_density_per_100_words');
    }

    public function test_get_post_comments_tool(): void
    {
        $mockService = $this->createMock(TelegramCollectionService::class);
        $mockService->method('fetchPostComments')->willReturn([
            'channel' => '@commentchan',
            'post_id' => 100,
            'total_comments' => 5,
            'fetched_count' => 1,
            'thread_dialogue' => '• [12:00] Отличный пост!',
            'comments' => [
                ['id' => 1, 'text' => 'Отличный пост!'],
            ],
        ]);
        $this->app->instance(TelegramCollectionService::class, $mockService);

        $response = AnalyticsServer::tool(GetPostCommentsTool::class, [
            'post_id_or_url' => 'https://t.me/commentchan/100',
            'format' => 'raw_json',
        ]);

        $response->assertOk();
        $response->assertSee('total_comments');
        $response->assertSee('Отличный пост!');
    }
}
