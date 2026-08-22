<?php

namespace App\DTOs\Analytics;

readonly class TopicSaturationDto
{
    /**
     * @param  list<array<string, mixed>>  $recentAngles
     */
    public function __construct(
        public string $query,
        public int $periodDays,
        public int $postsCount,
        public float $avgSimilarity,
        public string $saturationLevel,
        public string $recommendation,
        public array $recentAngles = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            query: (string) ($data['query'] ?? ''),
            periodDays: (int) ($data['period_days'] ?? 0),
            postsCount: (int) ($data['posts_count'] ?? 0),
            avgSimilarity: (float) ($data['avg_similarity'] ?? 0.0),
            saturationLevel: (string) ($data['saturation_level'] ?? 'emerging'),
            recommendation: (string) ($data['recommendation'] ?? ''),
            recentAngles: $data['recent_angles'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'period_days' => $this->periodDays,
            'posts_count' => $this->postsCount,
            'avg_similarity' => $this->avgSimilarity,
            'saturation_level' => $this->saturationLevel,
            'recommendation' => $this->recommendation,
            'recent_angles' => $this->recentAngles,
        ];
    }
}
