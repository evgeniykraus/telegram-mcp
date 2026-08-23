<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Repositories\Source\Contracts\SourceRepositoryInterface;
use App\Services\Analytics\AnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceTouchTest extends TestCase
{
    use RefreshDatabase;

    public function test_touch_queried_updates_last_queried_at_timestamp(): void
    {
        $oldDate = Carbon::now()->subDays(10);
        $source = Source::factory()->create([
            'username' => '@test_touch',
            'last_queried_at' => $oldDate,
        ]);

        $repository = app(SourceRepositoryInterface::class);
        $repository->touchQueried('@test_touch');

        $source->refresh();
        $this->assertTrue($source->last_queried_at->gt($oldDate));
    }

    public function test_analytics_service_touches_source_on_channel_report(): void
    {
        $oldDate = Carbon::now()->subDays(5);
        $source = Source::factory()->create([
            'username' => '@tracked_channel',
            'last_queried_at' => $oldDate,
        ]);

        $analyticsService = app(AnalyticsService::class);
        $analyticsService->getChannelReport('@tracked_channel', 30, false);

        $source->refresh();
        $this->assertTrue($source->last_queried_at->gt($oldDate));
    }
}
