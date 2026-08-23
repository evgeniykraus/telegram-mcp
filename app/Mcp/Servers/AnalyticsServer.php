<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\BestPublishingTimesResource;
use App\Mcp\Resources\ChannelsResource;
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
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Social Analytics Server')]
#[Version('1.0.0')]
#[Instructions('This server provides social media and Telegram competitive intelligence, channel discovery, news search, engagement metrics, format benchmarks, timing heatmaps, semantic vector search, viral anomaly detection, topic saturation, author style extraction, and content performance analytics.')]
class AnalyticsServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        DiscoverChannelsTool::class,
        SearchGlobalNewsTool::class,
        ClusterStorylinesTool::class,
        CollectChannelsTool::class,
        LatestPostsTool::class,
        ChannelReportTool::class,
        GetPostTool::class,
        GetPostCommentsTool::class,
        CommentIntelligenceTool::class,
        TopPostsTool::class,
        FindOutliersTool::class,
        TopicSaturationTool::class,
        ChannelStyleDnaTool::class,
        FormatBreakdownTool::class,
        BestTimeToPostTool::class,
        BenchmarkTool::class,
        ReportTool::class,
        SearchSimilarPostsTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        ChannelsResource::class,
        BestPublishingTimesResource::class,
    ];
}
