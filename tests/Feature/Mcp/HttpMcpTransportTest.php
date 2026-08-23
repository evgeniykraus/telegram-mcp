<?php

namespace Tests\Feature\Mcp;

use App\Models\Post;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HttpMcpTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_and_delete_return_405(): void
    {
        $getResponse = $this->get('/mcp/analytics');
        $getResponse->assertStatus(405);
        $getResponse->assertHeader('Allow', 'POST');

        $deleteResponse = $this->delete('/mcp/analytics');
        $deleteResponse->assertStatus(405);
        $deleteResponse->assertHeader('Allow', 'POST');
    }

    public function test_http_mcp_initialize(): void
    {
        $response = $this->postJson('/mcp/analytics', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => [
                    'name' => 'test-client',
                    'version' => '1.0.0',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('jsonrpc', '2.0');
        $response->assertJsonPath('id', 1);
        $response->assertJsonPath('result.serverInfo.name', 'Social Analytics Server');
    }

    public function test_http_mcp_list_tools(): void
    {
        $response = $this->postJson('/mcp/analytics', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('jsonrpc', '2.0');
        $response->assertJsonPath('id', 2);
        $response->assertSee('channel-report-tool');
        $response->assertSee('top-posts-tool');
        $response->assertSee('channel-style-dna-tool');
        $response->assertSee('best-time-to-post-tool');
    }

    public function test_http_mcp_call_tool(): void
    {
        $source = Source::factory()->create([
            'username' => '@httpchan',
            'title' => 'HTTP Test Channel',
            'subscribers_count' => 1000,
        ]);

        Post::factory()->count(3)->create([
            'source_id' => $source->id,
            'views' => 500,
            'forwards' => 10,
            'reactions' => 20,
            'comments' => 5,
            'er' => 0.07,
        ]);

        $response = $this->postJson('/mcp/analytics', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'channel-report-tool',
                'arguments' => [
                    'channel' => '@httpchan',
                    'days' => 30,
                    'auto_collect' => false,
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('jsonrpc', '2.0');
        $response->assertJsonPath('id', 3);
        $response->assertJsonPath('result.structuredContent.username', '@httpchan');
        $response->assertJsonPath('result.structuredContent.avg_er', 0.07);
    }

    public function test_http_mcp_top_posts_omits_empty_reactions_and_rounds_floats(): void
    {
        $source = Source::factory()->create(['username' => '@postchan']);

        Post::factory()->create([
            'source_id' => $source->id,
            'external_id' => '101',
            'text' => 'Тестовый пост без реакций',
            'views' => 1234,
            'forwards' => 10,
            'reactions' => 0,
            'reactions_breakdown' => [],
            'er' => 0.0081,
            'content_score' => 12.3456,
        ]);

        $response = $this->postJson('/mcp/analytics', [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'top-posts-tool',
                'arguments' => [
                    'channel' => '@postchan',
                    'limit' => 5,
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('jsonrpc', '2.0');
        $response->assertJsonPath('result.structuredContent.posts.0.content_score', 12.35);
        $response->assertJsonPath('result.structuredContent.posts.0.er', 0.0081);
        $response->assertDontSee('"reactions_breakdown":[]', false);
    }

    public function test_http_mcp_style_dna_detects_signature_cta_and_dividers(): void
    {
        $source = Source::factory()->create(['username' => '@stylednachan']);

        Post::factory()->create([
            'source_id' => $source->id,
            'external_id' => '102',
            'text' => "🎸 Чиним UI баги через yo-bug\n\nТекст описания технологии.\n\n🪙 Цены снижены:\n - GPT Terra дешевле на 20%\n\nУстановка:\nnpx yo-bug install\n\n🐈 Помните, один ключ — все модели!",
            'views' => 2000,
        ]);

        $response = $this->postJson('/mcp/analytics', [
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'channel-style-dna-tool',
                'arguments' => [
                    'channel' => '@stylednachan',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('jsonrpc', '2.0');
        $response->assertJsonPath('result.structuredContent.signature_cta_types.0', 'Фирменный слоган / подвал канала («Помните, один ключ — все модели!»)');
        $response->assertJsonPath('result.structuredContent.dividers_used.0', 'Использует тематические эмодзи-маркеры для смысловых блоков');
        $response->assertJsonPath('result.structuredContent.detected_hook_types.0', 'Эмодзи-плашка в начале заголовка (🔖 / ⚡️ / 🎸 / 💡)');
    }

    public function test_http_mcp_best_time_to_post_and_format_breakdown(): void
    {
        $source = Source::factory()->create(['username' => '@timeformatchan']);

        Post::factory()->create([
            'source_id' => $source->id,
            'format_type' => 'photo',
            'views' => 1159,
            'er' => 0.05,
            'published_at' => now(),
        ]);

        // Best time to post
        $timeResponse = $this->postJson('/mcp/analytics', [
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => [
                'name' => 'best-time-to-post-tool',
                'arguments' => [
                    'channel' => '@timeformatchan',
                    'days' => 30,
                ],
            ],
        ]);
        $timeResponse->assertOk();
        $timeResponse->assertJsonPath('jsonrpc', '2.0');
        $timeResponse->assertSee('top_recommendations', false);

        // Format breakdown
        $formatResponse = $this->postJson('/mcp/analytics', [
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => [
                'name' => 'format-breakdown-tool',
                'arguments' => [
                    'channel' => '@timeformatchan',
                    'days' => 30,
                ],
            ],
        ]);
        $formatResponse->assertOk();
        $formatResponse->assertJsonPath('jsonrpc', '2.0');
        $formatResponse->assertJsonPath('result.structuredContent.formats.0.format_type', 'photo');
        $formatResponse->assertJsonPath('result.structuredContent.formats.0.avg_views', 1159);
    }
}
