<?php

namespace App\DTOs\Analytics;

readonly class DiscoveredChannelDto
{
    public function __construct(
        public string $username,
        public ?string $title,
        public ?string $description,
        public int $subscribersCount,
        public bool $isVerified,
        public bool $isScam,
        public bool $isFake,
        public string $url,
        public bool $isAlreadyTracked = false,
        public ?string $lastCollectedAt = null,
        public int $postsCount = 0,
        public ?int $recentAvgViews = null,
        public ?float $recentEr = null,
        public ?string $lastPostAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawUsername = (string) ($data['username'] ?? '');
        $username = str_starts_with($rawUsername, '@') ? $rawUsername : '@'.$rawUsername;
        $cleanUsername = ltrim($username, '@');

        return new self(
            username: $username,
            title: $data['title'] ?? null,
            description: $data['description'] ?? null,
            subscribersCount: (int) ($data['subscribers_count'] ?? 0),
            isVerified: (bool) ($data['is_verified'] ?? false),
            isScam: (bool) ($data['is_scam'] ?? false),
            isFake: (bool) ($data['is_fake'] ?? false),
            url: (string) ($data['url'] ?? "https://t.me/{$cleanUsername}"),
            isAlreadyTracked: (bool) ($data['is_already_tracked'] ?? false),
            lastCollectedAt: $data['last_collected_at'] ?? null,
            postsCount: (int) ($data['posts_count'] ?? 0),
            recentAvgViews: isset($data['recent_avg_views']) ? (int) $data['recent_avg_views'] : null,
            recentEr: isset($data['recent_er']) ? (float) $data['recent_er'] : null,
            lastPostAt: $data['last_post_at'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'title' => $this->title,
            'description' => $this->description,
            'subscribers_count' => $this->subscribersCount,
            'recent_avg_views' => $this->recentAvgViews,
            'recent_er' => $this->recentEr,
            'last_post_at' => $this->lastPostAt,
            'is_verified' => $this->isVerified,
            'is_scam' => $this->isScam,
            'is_fake' => $this->isFake,
            'url' => $this->url,
            'is_already_tracked' => $this->isAlreadyTracked,
            'last_collected_at' => $this->lastCollectedAt,
            'posts_count' => $this->postsCount,
        ];
    }
}
