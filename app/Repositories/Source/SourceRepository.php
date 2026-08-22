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
}
