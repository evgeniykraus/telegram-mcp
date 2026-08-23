<?php

namespace App\Repositories\Source\Contracts;

use App\Models\Source;
use Illuminate\Database\Eloquent\Collection;

interface SourceRepositoryInterface
{
    public function findById(int $id): ?Source;

    public function findByUsername(string $username, string $sourceType = 'telegram'): ?Source;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public function firstOrCreate(array $attributes, array $values = []): Source;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Source $source, array $data): bool;

    /**
     * @return Collection<int, Source>
     */
    public function all(): Collection;

    public function touchQueried(Source|string $sourceOrUsername): void;

    /**
     * @return Collection<int, Source>
     */
    public function getActiveTracked(int $ttlDays = 14): Collection;

    /**
     * @return Collection<int, Source>
     */
    public function getExpired(int $ttlDays = 14): Collection;

    /**
     * @return Collection<int, Source>
     */
    public function searchByQuery(string $query, int $limit = 20): Collection;

    public function deleteSourceWithData(Source $source): void;
}
