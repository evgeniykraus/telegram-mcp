<?php

namespace App\DTOs\Analytics;

readonly class ReportDto
{
    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $topPosts
     * @param  array<int, array<string, mixed>>  $formats
     * @param  array<int, array<string, mixed>>  $bestTimeSlots
     * @param  array<string, mixed>|null  $benchmark
     */
    public function __construct(
        public int $periodDays,
        public ?string $channelUsername,
        public array $summary,
        public array $topPosts,
        public array $formats,
        public array $bestTimeSlots,
        public ?array $benchmark = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            periodDays: (int) ($data['period_days'] ?? 30),
            channelUsername: $data['channel_username'] ?? null,
            summary: (array) ($data['summary'] ?? []),
            topPosts: (array) ($data['top_posts'] ?? []),
            formats: (array) ($data['formats'] ?? []),
            bestTimeSlots: (array) ($data['best_time_slots'] ?? []),
            benchmark: isset($data['benchmark']) ? (array) $data['benchmark'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period_days' => $this->periodDays,
            'channel_username' => $this->channelUsername,
            'summary' => $this->summary,
            'top_posts' => $this->topPosts,
            'formats' => $this->formats,
            'best_time_slots' => $this->bestTimeSlots,
            'benchmark' => $this->benchmark,
        ];
    }
}
