<?php

namespace Database\Factories;

use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    protected $model = Source::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_type' => 'telegram',
            'username' => '@'.fake()->unique()->userName(),
            'title' => fake()->company(),
            'description' => fake()->paragraph(),
            'subscribers_count' => fake()->numberBetween(100, 500000),
            'is_active' => true,
            'is_own' => false,
            'category' => fake()->randomElement(['tech', 'crypto', 'marketing', 'news', 'business']),
            'last_collected_at' => now(),
            'last_queried_at' => now(),
        ];
    }

    public function own(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_own' => true,
        ]);
    }

    public function competitor(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_own' => false,
        ]);
    }
}
