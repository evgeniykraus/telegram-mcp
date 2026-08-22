<?php

namespace App\Mcp\Tools;

use App\Services\Analytics\AnalyticsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Compare performance of own channels vs competitors in a niche category (average ER, average views, delta percentages).')]
class BenchmarkTool extends Tool
{
    public function handle(Request $request, AnalyticsService $analyticsService): Response|ResponseFactory
    {
        $category = (string) ($request->get('category') ?? 'all');
        $days = (int) ($request->get('days') ?? $request->get('since_days') ?? 30);

        $benchmark = $analyticsService->getBenchmark($days, $category);

        return Response::structured($benchmark->toArray());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()
                ->description('Niche category (e.g. "tech", "crypto", "marketing") or "all" (default: "all")'),
            'days' => $schema->integer()
                ->description('Period in days to analyze (default: 30)'),
        ];
    }
}
