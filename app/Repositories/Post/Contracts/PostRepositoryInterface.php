<?php

namespace App\Repositories\Post\Contracts;

use App\Models\Post;
use Illuminate\Database\Eloquent\Collection;

interface PostRepositoryInterface
{
    public function findById(int $id): ?Post;

    public function findByExternalId(int $sourceId, string $externalId): ?Post;

    /**
     * @param  list<array<string, mixed>>  $postsData
     */
    public function bulkUpsert(array $postsData): int;

    /**
     * @return Collection<int, Post>
     */
    public function getTopPosts(
        int $limit = 20,
        ?int $sourceId = null,
        ?int $sinceDays = null,
        ?string $format = null,
        string $sortBy = 'content_score'
    ): Collection;

    /**
     * @return Collection<int, Post>
     */
    public function getRecentBySource(int $sourceId, int $limit = 100): Collection;

    /**
     * @return Collection<int, Post>
     */
    public function getPostsForMetricsRefresh(int $sinceDays, ?int $sourceId = null): Collection;

    /**
     * @param  list<float>  $embedding
     * @return Collection<int, Post>
     */
    public function searchSimilarByVector(
        array $embedding,
        int $limit = 10,
        ?int $excludePostId = null,
        ?int $sourceId = null
    ): Collection;

    /**
     * @return Collection<int, Post>
     */
    public function getPostsWithoutEmbeddings(int $limit = 100): Collection;

    /**
     * @return Collection<int, Post>
     */
    public function searchByText(
        string $query,
        int $limit = 20,
        ?int $sinceDays = null,
        int $minViews = 0,
        ?int $sourceId = null
    ): Collection;

    /**
     * @param  list<float>  $embedding
     */
    public function updateEmbedding(Post $post, array $embedding): bool;
}
