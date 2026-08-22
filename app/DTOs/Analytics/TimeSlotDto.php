<?php

namespace App\DTOs\Analytics;

readonly class TimeSlotDto
{
    public function __construct(
        public int $dayOfWeek,
        public string $dayName,
        public int $hour,
        public int $postsCount,
        public float $avgEr,
        public float $avgViews,
        public float $performanceScore,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            dayOfWeek: (int) ($data['day_of_week'] ?? 0),
            dayName: (string) ($data['day_name'] ?? ''),
            hour: (int) ($data['hour'] ?? 0),
            postsCount: (int) ($data['posts_count'] ?? 0),
            avgEr: (float) ($data['avg_er'] ?? 0.0),
            avgViews: (float) ($data['avg_views'] ?? 0.0),
            performanceScore: (float) ($data['performance_score'] ?? 0.0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'day_of_week' => $this->dayOfWeek,
            'day_name' => $this->dayName,
            'hour' => $this->hour,
            'posts_count' => $this->postsCount,
            'avg_er' => $this->avgEr,
            'avg_views' => $this->avgViews,
            'performance_score' => $this->performanceScore,
        ];
    }
}
