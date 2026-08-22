<?php

namespace App\Repositories\Analytics\Contracts;

use App\Models\Post;
use Illuminate\Support\Collection;

interface AnalyticsRepositoryInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getChannelStats(int $sourceId, int $sinceDays = 30): array;

    /**
     * @return array<string, int>
     */
    public function getReactionsSummary(int $sourceId, int $sinceDays = 30): array;

    /**
     * @return Collection<int, \stdClass>
     */
    public function getFormatBreakdown(int $sinceDays = 30, ?int $sourceId = null): Collection;

    /**
     * @return Collection<int, \stdClass>
     */
    public function getTimeSlots(int $sinceDays = 30, ?int $sourceId = null): Collection;

    /**
     * @return array<string, mixed>
     */
    public function getCategoryBenchmark(string $category, int $sinceDays = 30): array;

    /**
     * @return Collection<int, Post>
     */
    public function getOutlierPosts(float $minMultiplier = 2.0, int $sinceDays = 14, ?int $sourceId = null, int $limit = 20): Collection;
}
