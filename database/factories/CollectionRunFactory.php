<?php

namespace Database\Factories;

use App\Enums\CollectionRunStatus;
use App\Models\CollectionRun;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CollectionRun>
 */
class CollectionRunFactory extends Factory
{
    protected $model = CollectionRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'source_id' => Source::factory(),
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'posts_fetched' => 50,
            'posts_saved' => 45,
            'status' => CollectionRunStatus::Success,
            'error_message' => null,
        ];
    }
}
