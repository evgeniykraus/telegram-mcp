<?php

namespace App\DTOs\Analytics;

readonly class FormatBreakdownDto
{
    public function __construct(
        public string $formatType,
        public int $postsCount,
        public int $totalViews,
        public float $avgViews,
        public float $avgEr,
        public int $totalForwards,
        public float $avgForwards,
        public float $shareOfPosts,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            formatType: (string) ($data['format_type'] ?? 'other'),
            postsCount: (int) ($data['posts_count'] ?? 0),
            totalViews: (int) ($data['total_views'] ?? 0),
            avgViews: (float) ($data['avg_views'] ?? 0.0),
            avgEr: (float) ($data['avg_er'] ?? 0.0),
            totalForwards: (int) ($data['total_forwards'] ?? 0),
            avgForwards: (float) ($data['avg_forwards'] ?? 0.0),
            shareOfPosts: (float) ($data['share_of_posts'] ?? 0.0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format_type' => $this->formatType,
            'posts_count' => $this->postsCount,
            'total_views' => $this->totalViews,
            'avg_views' => $this->avgViews,
            'avg_er' => $this->avgEr,
            'total_forwards' => $this->totalForwards,
            'avg_forwards' => $this->avgForwards,
            'share_of_posts' => $this->shareOfPosts,
        ];
    }
}
