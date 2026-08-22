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
}
