<?php

namespace App\Repositories\Source;

use App\Models\Source;
use App\Repositories\Source\Contracts\SourceRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class SourceRepository implements SourceRepositoryInterface
{
    public function findById(int $id): ?Source
    {
        return Source::query()->find($id);
    }

    public function findByUsername(string $username, string $sourceType = 'telegram'): ?Source
    {
        $normalized = str_starts_with($username, '@') ? $username : '@'.$username;

        return Source::query()
            ->where('source_type', $sourceType)
            ->where('username', $normalized)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public function firstOrCreate(array $attributes, array $values = []): Source
    {
        if (isset($attributes['username'])) {
            $attributes['username'] = str_starts_with($attributes['username'], '@')
                ? $attributes['username']
                : '@'.$attributes['username'];
        }

        return Source::query()->firstOrCreate($attributes, $values);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Source $source, array $data): bool
    {
        return $source->update($data);
    }

    /**
     * @return Collection<int, Source>
     */
    public function all(): Collection
    {
        return Source::query()->get();
    }

    public function touchQueried(Source|string $sourceOrUsername): void
    {
        if ($sourceOrUsername instanceof Source) {
            $sourceOrUsername->update([
                'last_queried_at' => now(),
                'is_active' => true,
            ]);

            return;
        }

        $normalized = str_starts_with($sourceOrUsername, '@') ? $sourceOrUsername : '@'.$sourceOrUsername;
        Source::query()
            ->where('username', $normalized)
            ->update([
                'last_queried_at' => now(),
                'is_active' => true,
            ]);
    }

    /**
     * @return Collection<int, Source>
     */
    public function getActiveTracked(int $ttlDays = 14): Collection
    {
        return Source::query()
            ->activeTracked($ttlDays)
            ->get();
    }

    /**
     * @return Collection<int, Source>
     */
    public function getExpired(int $ttlDays = 14): Collection
    {
        return Source::query()
            ->expired($ttlDays)
            ->get();
    }

    public function deleteSourceWithData(Source $source): void
    {
        $source->posts()->delete();
        $source->collectionRuns()->delete();
        $source->delete();
    }
}
