<?php

namespace Tests\Feature\Mcp;

use App\DTOs\Analytics\DiscoveredChannelDto;
use App\DTOs\Analytics\GlobalPostDto;
use App\Enums\CollectionRunStatus;
use App\Mcp\Resources\BestPublishingTimesResource;
use App\Mcp\Resources\ChannelsResource;
use App\Mcp\Servers\AnalyticsServer;
use App\Mcp\Tools\BenchmarkTool;
use App\Mcp\Tools\BestTimeToPostTool;
use App\Mcp\Tools\ChannelReportTool;
use App\Mcp\Tools\ChannelStyleDnaTool;
use App\Mcp\Tools\ClusterStorylinesTool;
use App\Mcp\Tools\CollectChannelsTool;
use App\Mcp\Tools\CommentIntelligenceTool;
use App\Mcp\Tools\DiscoverChannelsTool;
use App\Mcp\Tools\FindOutliersTool;
use App\Mcp\Tools\FormatBreakdownTool;
use App\Mcp\Tools\GetPostCommentsTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\LatestPostsTool;
use App\Mcp\Tools\ReportTool;
use App\Mcp\Tools\SearchGlobalNewsTool;
use App\Mcp\Tools\SearchSimilarPostsTool;
use App\Mcp\Tools\TopicSaturationTool;
use App\Mcp\Tools\TopPostsTool;
use App\Models\CollectionRun;
use App\Models\Post;
use App\Models\Source;
use App\Services\Analytics\AnalyticsService;
use App\Services\Collection\TelegramCollectionService;
use App\Services\Collection\TelegramDiscoveryService;
use App\Services\Storyline\StorylineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;
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
        $response->assertDontSee('all_slots');
    }

    public function test_best_time_to_post_tool_respects_limit(): void
    {
        $source = Source::factory()->create(['username' => '@timechannel']);
        for ($i = 0; $i < 6; $i++) {
            Post::factory()->create([
                'source_id' => $source->id,
                'published_at' => now()->subHours($i),
            ]);
        }

        $response = AnalyticsServer::tool(BestTimeToPostTool::class, [
            'channel' => '@timechannel',
            'days' => 30,
            'limit' => 2,
        ]);

        $response->assertOk();
        $response->assertStructuredContent(fn (AssertableJson $json) => $json->count('top_recommendations', 2)->etc());
    }

    public function test_benchmark_tool(): void
    {
        $own = Source::factory()->create(['username' => '@ownchan', 'category' => 'tech']);
        $comp = Source::factory()->create(['username' => '@compchan', 'category' => 'tech']);

        Post::factory()->count(3)->create(['source_id' => $own->id, 'views' => 5000, 'er' => 0.08]);
        Post::factory()->count(3)->create(['source_id' => $comp->id, 'views' => 3000, 'er' => 0.04]);

        $response = AnalyticsServer::tool(BenchmarkTool::class, [
            'channel' => '@ownchan',
            'category' => 'tech',
            'days' => 30,
        ]);

        $response->assertOk();
        $response->assertSee('own_avg_er');
        $response->assertSee('competitor_avg_er');
        $response->assertDontSee('own_channels');
        $response->assertDontSee('competitor_channels');
    }

    public function test_benchmark_tool_includes_compact_channels_when_requested(): void
    {
        $own = Source::factory()->create(['username' => '@ownchan', 'category' => 'tech']);
        $comp = Source::factory()->create(['username' => '@compchan', 'category' => 'tech']);

        Post::factory()->count(3)->create(['source_id' => $own->id]);
        Post::factory()->count(3)->create(['source_id' => $comp->id]);

        $response = AnalyticsServer::tool(BenchmarkTool::class, [
            'channel' => '@ownchan',
            'category' => 'tech',
            'days' => 30,
            'include_channels' => true,
        ]);

        $response->assertOk();
        $response->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('own_channels.0.username', '@ownchan')
            ->has('competitor_channels', 1)
            ->where('competitor_channels.0.username', '@compchan')
            ->etc());
        $response->assertSee('subscribers_count');
        $response->assertDontSee('"title"');
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
        $response->assertSee('peak_multiplier');
        $response->assertSee('er_multiplier');
    }

    public function test_find_outliers_tool_dimension_views_excludes_high_engagement_low_views(): void
    {
        $source = Source::factory()->create(['username' => '@outlierdim']);
        Post::factory()->count(4)->create([
            'source_id' => $source->id,
            'views' => 1000,
            'reactions' => 0,
            'comments' => 0,
            'forwards' => 0,
            'er' => 0.0,
            'published_at' => now()->subDays(2),
        ]);
        // Views far BELOW median, but a burst of comments: must NOT pass dimension=views
        Post::factory()->create([
            'source_id' => $source->id,
            'views' => 100,
            'reactions' => 0,
            'comments' => 50,
            'forwards' => 0,
            'er' => 0.5,
            'published_at' => now()->subDays(1),
            'text' => 'Обсуждение без просмотров',
        ]);

        $responseViews = AnalyticsServer::tool(FindOutliersTool::class, [
            'channel' => '@outlierdim',
            'min_multiplier' => 2.0,
            'dimension' => 'views',
        ]);
        $responseViews->assertOk();
        $responseViews->assertDontSee('Обсуждение без просмотров');

        $responseAny = AnalyticsServer::tool(FindOutliersTool::class, [
            'channel' => '@outlierdim',
            'min_multiplier' => 2.0,
            'dimension' => 'any',
        ]);
        $responseAny->assertOk();
        $responseAny->assertSee('Обсуждение без просмотров');
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

    public function test_channel_style_dna_tool_structural_fallback_non_empty_hooks(): void
    {
        $source = Source::factory()->create(['username' => '@stylednafallback']);
        Post::factory()->create([
            'source_id' => $source->id,
            'text' => 'Выгорание авторов неизбежно?',
            'views' => 1200,
        ]);

        $response = AnalyticsServer::tool(ChannelStyleDnaTool::class, [
            'channel' => '@stylednafallback',
        ]);

        $response->assertOk();
        $response->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('detected_hook_types', 1)
            ->where('detected_hook_types.0', 'Вопрос в заголовке (вовлечение)')
            ->etc());
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

    public function test_get_post_comments_tool_numeric_id_not_found_returns_actionable_error(): void
    {
        $response = AnalyticsServer::tool(GetPostCommentsTool::class, [
            'post_id_or_url' => '999999',
            'format' => 'raw_json',
        ]);

        $response->assertOk();
        $response->assertSee('Post #999999 not found locally');
        $response->assertSee('https://t.me/channel/999999');
    }

    public function test_get_post_comments_tool_compact_text_no_discussion(): void
    {
        $mockService = $this->createMock(TelegramCollectionService::class);
        $mockService->method('fetchPostComments')->willReturn([
            'channel' => '@commentchan',
            'post_id' => 100,
            'total_comments' => 0,
            'fetched_count' => 0,
            'thread_dialogue' => '',
            'comments' => [],
            'note' => 'No linked discussion group found for this channel',
        ]);
        $this->app->instance(TelegramCollectionService::class, $mockService);

        $response = AnalyticsServer::tool(GetPostCommentsTool::class, [
            'post_id_or_url' => 'https://t.me/commentchan/100',
            'format' => 'compact_text',
        ]);

        $response->assertOk();
        $response->assertSee('No linked discussion group found');
        $response->assertSee('Showing: 0');
    }

    public function test_discover_channels_tool(): void
    {
        $mockDiscoveryService = $this->createMock(TelegramDiscoveryService::class);
        $mockDiscoveryService->method('discoverChannels')->willReturn([
            DiscoveredChannelDto::fromArray([
                'username' => '@ai_news_daily',
                'title' => 'AI News Daily',
                'description' => 'Главные новости искусственного интеллекта',
                'subscribers_count' => 45000,
                'is_verified' => true,
                'url' => 'https://t.me/ai_news_daily',
            ]),
        ]);
        $this->app->instance(TelegramDiscoveryService::class, $mockDiscoveryService);

        $response = AnalyticsServer::tool(DiscoverChannelsTool::class, [
            'query' => 'искусственный интеллект',
            'limit' => 5,
            'min_subscribers' => 10000,
        ]);

        $response->assertOk();
        $response->assertSee('@ai_news_daily');
        $response->assertSee('AI News Daily');
        $response->assertSee('45000');
    }

    public function test_search_global_news_tool(): void
    {
        $mockDiscoveryService = $this->createMock(TelegramDiscoveryService::class);
        $mockDiscoveryService->method('searchGlobalNews')->willReturn([
            GlobalPostDto::fromArray([
                'id' => '777',
                'channel_username' => '@tech_breaking',
                'channel_title' => 'Tech Breaking News',
                'url' => 'https://t.me/tech_breaking/777',
                'published_at' => now()->toDateTimeString(),
                'text' => 'Анонсирован прорыв в архитектуре языковых моделей',
                'views' => 15000,
                'forwards' => 250,
                'reactions' => 400,
                'er' => 0.0433,
                'content_score' => 75.2,
            ]),
        ]);
        $this->app->instance(TelegramDiscoveryService::class, $mockDiscoveryService);

        $response = AnalyticsServer::tool(SearchGlobalNewsTool::class, [
            'query' => 'языковые модели',
            'limit' => 5,
            'since_hours' => 24,
        ]);

        $response->assertOk();
        $response->assertSee('@tech_breaking');
        $response->assertSee('языковых моделей');
    }

    public function test_latest_posts_tool(): void
    {
        $mockService = $this->createMock(TelegramCollectionService::class);
        $mockService->method('fetchRecentPosts')->willReturn([
            [
                'id' => '101',
                'external_id' => '101',
                'channel_username' => '@newschan',
                'url' => 'https://t.me/newschan/101',
                'published_at' => now()->toDateTimeString(),
                'text' => 'Свежая новость о запуске',
                'views' => 1200,
                'forwards' => 30,
                'reactions' => 50,
                'er' => 0.02,
                'content_score' => 10.5,
            ],
        ]);
        $this->app->instance(TelegramCollectionService::class, $mockService);

        $response = AnalyticsServer::tool(LatestPostsTool::class, [
            'channels' => '@newschan',
        ]);

        $response->assertOk();
        $response->assertSee('Свежая новость');
        $response->assertSee('@newschan');
    }

    public function test_latest_posts_tool_since_id_filter(): void
    {
        $mockService = $this->createMock(TelegramCollectionService::class);
        $mockService->method('fetchRecentPosts')->willReturn([
            [
                'id' => '101',
                'external_id' => '101',
                'channel_username' => '@newschan',
                'url' => 'https://t.me/newschan/101',
                'published_at' => now()->subMinutes(10)->toDateTimeString(),
                'text' => 'Старый пост',
                'views' => 100,
                'forwards' => 0,
                'reactions' => 0,
                'er' => 0.0,
                'content_score' => 0.0,
            ],
            [
                'id' => '102',
                'external_id' => '102',
                'channel_username' => '@newschan',
                'url' => 'https://t.me/newschan/102',
                'published_at' => now()->subMinutes(5)->toDateTimeString(),
                'text' => 'Новый пост',
                'views' => 200,
                'forwards' => 0,
                'reactions' => 0,
                'er' => 0.0,
                'content_score' => 0.0,
            ],
        ]);
        $this->app->instance(TelegramCollectionService::class, $mockService);

        $response = AnalyticsServer::tool(LatestPostsTool::class, [
            'channels' => '@newschan',
            'since_id' => 101,
        ]);

        $response->assertOk();
        $response->assertSee('Новый пост');
        $response->assertDontSee('Старый пост');
    }

    public function test_get_post_comments_tool_shows_pagination_hint(): void
    {
        $mockService = $this->createMock(TelegramCollectionService::class);
        $mockService->method('fetchPostComments')->willReturn([
            'channel' => '@commentchan',
            'post_id' => 100,
            'total_comments' => 300,
            'fetched_count' => 200,
            'next_offset_id' => 45,
            'has_more' => true,
            'last_seen_id' => 200,
            'thread_dialogue' => '• [12:00] Комментарий',
            'comments' => [],
        ]);
        $this->app->instance(TelegramCollectionService::class, $mockService);

        $response = AnalyticsServer::tool(GetPostCommentsTool::class, [
            'post_id_or_url' => 'https://t.me/commentchan/100',
            'format' => 'compact_text',
            'limit' => 200,
        ]);

        $response->assertOk();
        $response->assertSee('More comments available');
        $response->assertSee('offset_id=45');
        $response->assertSee('last_seen_id: 200');
    }

    public function test_cluster_storylines_tool(): void
    {
        $mockService = $this->createMock(StorylineService::class);
        $mockService->method('clusterStorylines')->willReturn([
            'days' => 3,
            'since_hours' => 2,
            'posts_scanned' => 4,
            'storylines_count' => 1,
            'storylines' => [[
                'headline' => 'Один и тот же анонс на четырёх каналах',
                'channels' => ['@a', '@b', '@c', '@d'],
                'corroborated' => true,
                'post_count' => 4,
                'first_seen_at' => now()->toDateTimeString(),
                'last_seen_at' => now()->toDateTimeString(),
                'representative_post' => [
                    'channel_username' => '@a',
                    'url' => 'https://t.me/a/1',
                    'published_at' => now()->toDateTimeString(),
                    'text_preview' => 'Самый просматриваемый анонс',
                    'views' => 50000,
                    'forwards' => 1200,
                ],
            ]],
        ]);
        $this->app->instance(StorylineService::class, $mockService);

        $response = AnalyticsServer::tool(ClusterStorylinesTool::class, [
            'since_hours' => 2,
        ]);

        $response->assertOk();
        $response->assertSee('Один и тот же анонс');
        $response->assertSee('corroborated');
        $response->assertSee('representative_post');
        $response->assertSee('Самый просматриваемый анонс');
    }

    public function test_comment_intelligence_tool(): void
    {
        $mockService = $this->createMock(AnalyticsService::class);
        $mockService->method('getCommentIntelligence')->willReturn([
            'channel' => '@chan',
            'post_id' => 100,
            'total_comments' => 10,
            'analyzed_comments' => 8,
            'top_districts' => [['district' => 'центр', 'mentions' => 5]],
            'dominant_complaints' => [['complaint' => 'не работает', 'mentions' => 3, 'example' => 'у меня не работает приложение']],
        ]);
        $this->app->instance(AnalyticsService::class, $mockService);

        $response = AnalyticsServer::tool(CommentIntelligenceTool::class, [
            'post_id_or_url' => 'https://t.me/chan/100',
        ]);

        $response->assertOk();
        $response->assertSee('top_districts');
        $response->assertSee('центр');
        $response->assertSee('dominant_complaints');
    }
}
