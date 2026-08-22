<?php

namespace Database\Factories;

use App\Enums\ContentFormat;
use App\Models\Post;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $views = fake()->numberBetween(50, 10000);
        $forwards = fake()->numberBetween(0, (int) ($views * 0.1));
        $reactions = fake()->numberBetween(0, (int) ($views * 0.2));
        $comments = fake()->numberBetween(0, (int) ($views * 0.05));
        $er = $views > 0 ? round(($reactions + $forwards + $comments) / $views, 4) : 0.0;
        $contentScore = round(($er * 100) + ($forwards * 0.5) + ($views * 0.001), 2);

        return [
            'source_id' => Source::factory(),
            'external_id' => (string) fake()->unique()->numberBetween(1, 9999999),
            'published_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'text' => fake()->paragraphs(2, true),
            'format_type' => fake()->randomElement(array_column(ContentFormat::cases(), 'value')),
            'url' => 'https://t.me/channel/'.fake()->numberBetween(1, 9999),
            'views' => $views,
            'forwards' => $forwards,
            'reactions' => $reactions,
            'comments' => $comments,
            'reactions_breakdown' => [
                '👍' => (int) ($reactions * 0.6),
                '🔥' => (int) ($reactions * 0.3),
                '❤️' => (int) ($reactions * 0.1),
            ],
            'er' => $er,
            'content_score' => $contentScore,
            'is_ad' => fake()->boolean(10),
            'forward_from_chat_id' => null,
            'raw_metadata' => [],
        ];
    }

    public function ad(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_ad' => true,
        ]);
    }
}
