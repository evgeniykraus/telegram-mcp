<?php

namespace App\DTOs\Analytics;

readonly class BenchmarkDto
{
    /**
     * @param  array<int, array<string, mixed>>  $ownChannels
     * @param  array<int, array<string, mixed>>  $competitorChannels
     */
    public function __construct(
        public string $category,
        public int $periodDays,
        public float $ownAvgEr,
        public float $competitorAvgEr,
        public float $erDifferencePercent,
        public float $ownAvgViews,
        public float $competitorAvgViews,
        public float $viewsDifferencePercent,
        public array $ownChannels,
        public array $competitorChannels,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            category: (string) ($data['category'] ?? 'all'),
            periodDays: (int) ($data['period_days'] ?? 30),
            ownAvgEr: (float) ($data['own_avg_er'] ?? 0.0),
            competitorAvgEr: (float) ($data['competitor_avg_er'] ?? 0.0),
            erDifferencePercent: (float) ($data['er_difference_percent'] ?? 0.0),
            ownAvgViews: (float) ($data['own_avg_views'] ?? 0.0),
            competitorAvgViews: (float) ($data['competitor_avg_views'] ?? 0.0),
            viewsDifferencePercent: (float) ($data['views_difference_percent'] ?? 0.0),
            ownChannels: (array) ($data['own_channels'] ?? []),
            competitorChannels: (array) ($data['competitor_channels'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'period_days' => $this->periodDays,
            'own_avg_er' => $this->ownAvgEr,
            'competitor_avg_er' => $this->competitorAvgEr,
            'er_difference_percent' => $this->erDifferencePercent,
            'own_avg_views' => $this->ownAvgViews,
            'competitor_avg_views' => $this->competitorAvgViews,
            'views_difference_percent' => $this->viewsDifferencePercent,
            'own_channels' => $this->ownChannels,
            'competitor_channels' => $this->competitorChannels,
        ];
    }
}
