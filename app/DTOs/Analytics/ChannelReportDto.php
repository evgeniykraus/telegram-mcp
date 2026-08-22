<?php

namespace App\DTOs\Analytics;

readonly class ChannelReportDto
{
    /**
     * @param  array<string, mixed>  $extraMetrics
     */
    public function __construct(
        public int $sourceId,
        public string $username,
        public ?string $title,
        public int $subscribersCount,
        public int $totalPosts,
        public int $totalViews,
        public float $avgViewsPerPost,
        public float $avgEr,
        public float $bestEr,
        public int $totalForwards,
        public int $totalReactions,
        public int $totalComments,
        public ?int $bestPostId = null,
        public ?string $bestPostUrl = null,
        public array $extraMetrics = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceId: (int) ($data['source_id'] ?? 0),
            username: (string) ($data['username'] ?? ''),
            title: $data['title'] ?? null,
            subscribersCount: (int) ($data['subscribers_count'] ?? 0),
            totalPosts: (int) ($data['total_posts'] ?? 0),
            totalViews: (int) ($data['total_views'] ?? 0),
            avgViewsPerPost: (float) ($data['avg_views_per_post'] ?? 0.0),
            avgEr: (float) ($data['avg_er'] ?? 0.0),
            bestEr: (float) ($data['best_er'] ?? 0.0),
            totalForwards: (int) ($data['total_forwards'] ?? 0),
            totalReactions: (int) ($data['total_reactions'] ?? 0),
            totalComments: (int) ($data['total_comments'] ?? 0),
            bestPostId: isset($data['best_post_id']) ? (int) $data['best_post_id'] : null,
            bestPostUrl: $data['best_post_url'] ?? null,
            extraMetrics: (array) ($data['extra_metrics'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'username' => $this->username,
            'title' => $this->title,
            'subscribers_count' => $this->subscribersCount,
            'total_posts' => $this->totalPosts,
            'total_views' => $this->totalViews,
            'avg_views_per_post' => $this->avgViewsPerPost,
            'avg_er' => $this->avgEr,
            'best_er' => $this->bestEr,
            'total_forwards' => $this->totalForwards,
            'total_reactions' => $this->totalReactions,
            'total_comments' => $this->totalComments,
            'best_post_id' => $this->bestPostId,
            'best_post_url' => $this->bestPostUrl,
            'extra_metrics' => $this->extraMetrics,
        ];
    }
}
