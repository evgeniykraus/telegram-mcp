<?php

namespace App\DTOs\Analytics;

readonly class SimilarPostDto
{
    public function __construct(
        public int $id,
        public string $externalId,
        public string $sourceUsername,
        public ?string $url,
        public ?string $publishedAt,
        public ?string $text,
        public int $views,
        public int $forwards,
        public int $reactions,
        public float $er,
        public float $contentScore,
        public ?float $similarityScore = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            externalId: (string) ($data['external_id'] ?? ''),
            sourceUsername: (string) ($data['source_username'] ?? ''),
            url: $data['url'] ?? null,
            publishedAt: $data['published_at'] ?? null,
            text: $data['text'] ?? null,
            views: (int) ($data['views'] ?? 0),
            forwards: (int) ($data['forwards'] ?? 0),
            reactions: (int) ($data['reactions'] ?? 0),
            er: (float) ($data['er'] ?? 0.0),
            contentScore: (float) ($data['content_score'] ?? 0.0),
            similarityScore: isset($data['similarity_score']) ? (float) $data['similarity_score'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->externalId,
            'source_username' => $this->sourceUsername,
            'url' => $this->url,
            'published_at' => $this->publishedAt,
            'text' => $this->text,
            'views' => $this->views,
            'forwards' => $this->forwards,
            'reactions' => $this->reactions,
            'er' => $this->er,
            'content_score' => $this->contentScore,
            'similarity_score' => $this->similarityScore,
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
            'external_id' => $this->externalId,
            'source_username' => $this->sourceUsername,
            'url' => $this->url,
            'published_at' => $this->publishedAt,
            'text_preview' => $previewText,
            'is_truncated' => $isTruncated,
            'views' => $this->views,
            'forwards' => $this->forwards,
            'reactions' => $this->reactions,
            'er' => $this->er,
            'content_score' => $this->contentScore,
            'similarity_score' => $this->similarityScore,
        ];
    }
}
