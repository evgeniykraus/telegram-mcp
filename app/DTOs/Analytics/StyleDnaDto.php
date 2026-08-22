<?php

namespace App\DTOs\Analytics;

readonly class StyleDnaDto
{
    /**
     * @param  list<string>  $detectedHookTypes
     * @param  list<string>  $dividersUsed
     * @param  list<string>  $signatureCtaTypes
     * @param  list<array<string, mixed>>  $exemplarPosts
     */
    public function __construct(
        public string $channel,
        public int $analyzedPostsCount,
        public int $avgPostLengthChars,
        public int $avgParagraphsCount,
        public float $emojiDensityPer100Words,
        public array $detectedHookTypes = [],
        public array $dividersUsed = [],
        public array $signatureCtaTypes = [],
        public array $exemplarPosts = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            channel: (string) ($data['channel'] ?? ''),
            analyzedPostsCount: (int) ($data['analyzed_posts_count'] ?? 0),
            avgPostLengthChars: (int) ($data['avg_post_length_chars'] ?? 0),
            avgParagraphsCount: (int) ($data['avg_paragraphs_count'] ?? 0),
            emojiDensityPer100Words: (float) ($data['emoji_density_per_100_words'] ?? 0.0),
            detectedHookTypes: $data['detected_hook_types'] ?? [],
            dividersUsed: $data['dividers_used'] ?? [],
            signatureCtaTypes: $data['signature_cta_types'] ?? [],
            exemplarPosts: $data['exemplar_posts'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'analyzed_posts_count' => $this->analyzedPostsCount,
            'avg_post_length_chars' => $this->avgPostLengthChars,
            'avg_paragraphs_count' => $this->avgParagraphsCount,
            'emoji_density_per_100_words' => $this->emojiDensityPer100Words,
            'detected_hook_types' => $this->detectedHookTypes,
            'dividers_used' => $this->dividersUsed,
            'signature_cta_types' => $this->signatureCtaTypes,
            'exemplar_posts' => $this->exemplarPosts,
        ];
    }
}
