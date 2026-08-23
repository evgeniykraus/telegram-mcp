<?php

namespace App\DTOs\Analytics;

readonly class GlobalPostDto
{
    public function __construct(
        public string|int $id,
        public string $channelUsername,
        public ?string $channelTitle,
        public ?string $url,
        public ?string $publishedAt,
        public ?string $text,
        public int $views,
        public int $forwards,
        public int $reactions,
        public float $er,
        public float $contentScore,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawUsername = (string) ($data['channel_username'] ?? ($data['source_username'] ?? ''));
        $username = str_starts_with($rawUsername, '@') ? $rawUsername : '@'.$rawUsername;

        return new self(
            id: $data['id'] ?? ($data['external_id'] ?? 0),
            channelUsername: $username,
            channelTitle: $data['channel_title'] ?? null,
            url: $data['url'] ?? null,
            publishedAt: $data['published_at'] ?? null,
            text: $data['text'] ?? null,
            views: (int) ($data['views'] ?? 0),
            forwards: (int) ($data['forwards'] ?? 0),
            reactions: (int) ($data['reactions'] ?? 0),
            er: (float) ($data['er'] ?? 0.0),
            contentScore: (float) ($data['content_score'] ?? 0.0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'channel_username' => $this->channelUsername,
            'channel_title' => $this->channelTitle,
            'url' => $this->url,
            'published_at' => $this->publishedAt,
            'text' => $this->text,
            'views' => $this->views,
            'forwards' => $this->forwards,
            'reactions' => $this->reactions,
            'er' => $this->er,
            'content_score' => $this->contentScore,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPreviewArray(int $maxLength = 200): array
    {
        $rawText = $this->text ?? '';
        $isTruncated = mb_strlen($rawText) > $maxLength;
        $previewText = $isTruncated ? mb_substr($rawText, 0, $maxLength).'...' : $rawText;

        return [
            'id' => $this->id,
            'channel_username' => $this->channelUsername,
            'channel_title' => $this->channelTitle,
            'url' => $this->url,
            'published_at' => $this->publishedAt,
            'text_preview' => $previewText,
            'is_truncated' => $isTruncated,
            'views' => $this->views,
            'forwards' => $this->forwards,
            'reactions' => $this->reactions,
            'er' => $this->er,
            'content_score' => $this->contentScore,
        ];
    }
}
