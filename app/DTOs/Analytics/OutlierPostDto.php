<?php

namespace App\DTOs\Analytics;

readonly class OutlierPostDto
{
    /**
     * @param  array<string, int>|null  $reactionsBreakdown
     */
    public function __construct(
        public int $id,
        public int $sourceId,
        public string $sourceUsername,
        public ?string $url,
        public ?string $publishedAt,
        public int $views,
        public int $reactions,
        public int $comments,
        public int $forwards,
        public float $channelMedianViews,
        public float $multiplier,
        public float $peakMultiplier,
        public float $viewsMultiplier,
        public float $engagementMultiplier,
        public float $erMultiplier,
        public float $er,
        public string $outlierType,
        public ?string $formatType,
        public string $hook,
        public string $textPreview,
        public ?array $reactionsBreakdown = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            sourceId: (int) ($data['source_id'] ?? 0),
            sourceUsername: (string) ($data['source_username'] ?? ''),
            url: $data['url'] ?? null,
            publishedAt: $data['published_at'] ?? null,
            views: (int) ($data['views'] ?? 0),
            reactions: (int) ($data['reactions'] ?? 0),
            comments: (int) ($data['comments'] ?? 0),
            forwards: (int) ($data['forwards'] ?? 0),
            channelMedianViews: (float) ($data['channel_median_views'] ?? 0.0),
            multiplier: (float) ($data['multiplier'] ?? 0.0),
            peakMultiplier: (float) ($data['peak_multiplier'] ?? 0.0),
            viewsMultiplier: (float) ($data['views_multiplier'] ?? 0.0),
            engagementMultiplier: (float) ($data['engagement_multiplier'] ?? 0.0),
            erMultiplier: (float) ($data['er_multiplier'] ?? 0.0),
            er: (float) ($data['er'] ?? 0.0),
            outlierType: (string) ($data['outlier_type'] ?? 'viral_views'),
            formatType: $data['format_type'] ?? null,
            hook: (string) ($data['hook'] ?? ''),
            textPreview: (string) ($data['text_preview'] ?? ''),
            reactionsBreakdown: $data['reactions_breakdown'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source_id' => $this->sourceId,
            'source_username' => $this->sourceUsername,
            'url' => $this->url,
            'published_at' => $this->publishedAt,
            'views' => $this->views,
            'reactions' => $this->reactions,
            'comments' => $this->comments,
            'forwards' => $this->forwards,
            'channel_median_views' => $this->channelMedianViews,
            'multiplier' => $this->multiplier,
            'peak_multiplier' => $this->peakMultiplier,
            'views_multiplier' => $this->viewsMultiplier,
            'engagement_multiplier' => $this->engagementMultiplier,
            'er_multiplier' => $this->erMultiplier,
            'er' => $this->er,
            'outlier_type' => $this->outlierType,
            'reactions_breakdown' => $this->reactionsBreakdown,
            'format_type' => $this->formatType,
            'hook' => $this->hook,
            'text_preview' => $this->textPreview,
        ];
    }
}
