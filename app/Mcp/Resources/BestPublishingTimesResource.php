<?php

namespace App\Mcp\Resources;

use App\Services\Analytics\AnalyticsService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Uri('analytics://publishing/best-times')]
#[Description('Best publishing time slots aggregated across all tracked channels for the last 30 days')]
#[MimeType('application/json')]
class BestPublishingTimesResource extends Resource
{
    public function handle(Request $request, AnalyticsService $analyticsService): Response|ResponseFactory
    {
        $timeSlots = $analyticsService->getBestTimeToPost(30);

        return Response::structured([
            'period_days' => 30,
            'top_recommendations' => array_slice(array_map(fn ($s) => $s->toArray(), $timeSlots), 0, 5),
            'time_slots' => array_map(fn ($s) => $s->toArray(), $timeSlots),
        ]);
    }
}
