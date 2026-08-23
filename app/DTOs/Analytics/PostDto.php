<?php

namespace App\DTOs\Analytics;

readonly class PostDto
{
    /**
     * @param  array<string, int>|null  $reactionsBreakdown
     */
    public function __construct(
        public int $id,
        public int $sourceId,
        public string $sourceUsername,
        public string $externalId,
        public ?string $url,
        public ?string $publishedAt,
        public ?string $text,
        public ?string $formatType,
        public int $views,
        public int $forwards,
        public int $reactions,
        public int $comments,
        public float $er,
        public float $contentScore,
        public float $viralFactor,
        public float $discussionIndex,
        public bool $isAd,
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
            externalId: (string) ($data['external_id'] ?? ''),
            url: $data['url'] ?? null,
            publishedAt: $data['published_at'] ?? null,
            text: $data['text'] ?? null,
            formatType: $data['format_type'] ?? null,
            views: (int) ($data['views'] ?? 0),
            forwards: (int) ($data['forwards'] ?? 0),
            reactions: (int) ($data['reactions'] ?? 0),
            comments: (int) ($data['comments'] ?? 0),
            er: round((float) ($data['er'] ?? 0.0), 4),
            contentScore: round((float) ($data['content_score'] ?? 0.0), 2),
            viralFactor: round((float) ($data['viral_factor'] ?? 0.0), 2),
            discussionIndex: round((float) ($data['discussion_index'] ?? 0.0), 2),
            isAd: (bool) ($data['is_ad'] ?? false),
            reactionsBreakdown: ! empty($data['reactions_breakdown']) ? (array) $data['reactions_breakdown'] : null,
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
            'external_id' => $this->externalId,
            'url' => $this->url,
            'published_at' => $this->publishedAt,
            'text' => $this->text,
            'format_type' => $this->formatType,
            'views' => $this->views,
            'forwards' => $this->forwards,
            'reactions' => $this->reactions,
            'comments' => $this->comments,
            'er' => round($this->er, 4),
            'content_score' => round($this->contentScore, 2),
            'viral_factor' => round($this->viralFactor, 2),
            'discussion_index' => round($this->discussionIndex, 2),
            'is_ad' => $this->isAd,
            'reactions_breakdown' => $this->reactionsBreakdown ?? [],
        ];
    }

    /**
     * Return compact preview array with truncated text for token-efficient MCP responses.
     *
     * @return array<string, mixed>
     */
    public function toPreviewArray(int $maxLength = 200): array
    {
        $rawText = $this->text ?? '';
        $isTruncated = mb_strlen($rawText) > $maxLength;
        $previewText = $isTruncated ? mb_substr($rawText, 0, $maxLength).'...' : $rawText;

        $preview = [
            'id' => $this->id,
            'source_username' => $this->sourceUsername,
            'url' => $this->url,
            'published_at' => $this->publishedAt,
            'text_preview' => $previewText,
            'is_truncated' => $isTruncated,
            'format_type' => $this->formatType,
            'views' => $this->views,
            'forwards' => $this->forwards,
            'reactions' => $this->reactions,
            'comments' => $this->comments,
            'er' => round($this->er, 4),
            'content_score' => round($this->contentScore, 2),
            'is_ad' => $this->isAd,
        ];

        if (! empty($this->reactionsBreakdown)) {
            $preview['reactions_breakdown'] = $this->reactionsBreakdown;
        }

        return $preview;
    }
}
