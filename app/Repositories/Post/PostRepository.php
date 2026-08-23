<?php

namespace App\Repositories\Post;

use App\Models\Post;
use App\Repositories\Post\Contracts\PostRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PostRepository implements PostRepositoryInterface
{
    public function findById(int $id): ?Post
    {
        return Post::query()->with('source')->find($id);
    }

    public function findByExternalId(int $sourceId, string $externalId): ?Post
    {
        return Post::query()
            ->where('source_id', $sourceId)
            ->where('external_id', $externalId)
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $postsData
     */
    public function bulkUpsert(array $postsData): int
    {
        if (empty($postsData)) {
            return 0;
        }

        $now = now();
        $formatted = array_map(function (array $item) use ($now): array {
            if (isset($item['reactions_breakdown']) && is_array($item['reactions_breakdown'])) {
                $item['reactions_breakdown'] = json_encode($item['reactions_breakdown']);
            }
            if (isset($item['raw_metadata']) && is_array($item['raw_metadata'])) {
                $item['raw_metadata'] = json_encode($item['raw_metadata']);
            }
            $item['created_at'] = $item['created_at'] ?? $now;
            $item['updated_at'] = $now;

            return $item;
        }, $postsData);

        return Post::query()->upsert(
            $formatted,
            ['source_id', 'external_id'],
            [
                'text',
                'format_type',
                'url',
                'views',
                'forwards',
                'reactions',
                'comments',
                'reactions_breakdown',
                'er',
                'content_score',
                'is_ad',
                'forward_from_chat_id',
                'raw_metadata',
                'updated_at',
            ]
        );
    }

    /**
     * @return Collection<int, Post>
     */
    public function getTopPosts(
        int $limit = 20,
        ?int $sourceId = null,
        ?int $sinceDays = null,
        ?string $format = null,
        string $sortBy = 'content_score'
    ): Collection {
        $query = Post::query()->with('source');

        if ($sourceId !== null) {
            $query->where('source_id', $sourceId);
        }

        if ($sinceDays !== null) {
            $query->where('published_at', '>=', now()->subDays($sinceDays));
        }

        if ($format !== null) {
            $query->where('format_type', $format);
        }

        $sortColumn = match ($sortBy) {
            'er' => 'er',
            'views' => 'views',
            'forwards' => 'forwards',
            'reactions' => 'reactions',
            default => 'content_score',
        };

        return $query->orderByDesc($sortColumn)->limit($limit)->get();
    }

    /**
     * @return Collection<int, Post>
     */
    public function getRecentBySource(int $sourceId, int $limit = 100): Collection
    {
        return Post::query()
            ->where('source_id', $sourceId)
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Post>
     */
    public function getRecentAcrossSources(?Carbon $since = null, int $limit = 200): Collection
    {
        $query = Post::query()
            ->with('source')
            ->whereNotNull('text');

        if ($since !== null) {
            $query->where('published_at', '>=', $since);
        }

        return $query->orderByDesc('published_at')->limit($limit)->get();
    }

    /**
     * @return Collection<int, Post>
     */
    public function getPostsForMetricsRefresh(int $sinceDays, ?int $sourceId = null): Collection
    {
        $query = Post::query()
            ->where('published_at', '>=', now()->subDays($sinceDays));

        if ($sourceId !== null) {
            $query->where('source_id', $sourceId);
        }

        return $query->orderByDesc('published_at')
            ->get(['id', 'source_id', 'external_id']);
    }

    /**
     * @param  list<float>  $embedding
     * @return Collection<int, Post>
     */
    public function searchSimilarByVector(
        array $embedding,
        int $limit = 10,
        ?int $excludePostId = null,
        ?int $sourceId = null
    ): Collection {
        $driver = DB::getDriverName();

        $query = Post::query()->with('source')->whereNotNull('embedding');

        if ($excludePostId !== null) {
            $query->where('id', '!=', $excludePostId);
        }

        if ($sourceId !== null) {
            $query->where('source_id', $sourceId);
        }

        if ($driver === 'pgsql') {
            $vectorStr = '['.implode(',', $embedding).']';

            return $query
                ->selectRaw('*, (embedding <=> ?) as distance', [$vectorStr])
                ->orderByRaw('(embedding <=> ?) ASC', [$vectorStr])
                ->limit($limit)
                ->get();
        }

        // Fallback for SQLite in tests
        return $query->limit($limit)->get();
    }

    /**
     * @return Collection<int, Post>
     */
    public function getPostsWithoutEmbeddings(int $limit = 100): Collection
    {
        return Post::query()
            ->whereNull('embedding')
            ->whereNotNull('text')
            ->where('text', '!=', '')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Post>
     */
    public function searchByText(
        string $query,
        int $limit = 20,
        ?Carbon $since = null,
        int $minViews = 0,
        ?int $sourceId = null
    ): Collection {
        $trimmed = trim($query);
        $fullTerm = '%'.mb_strtolower($trimmed).'%';
        $words = array_filter(
            preg_split('/[\s,\.\-_]+/u', mb_strtolower($trimmed)) ?: [],
            fn (string $w) => mb_strlen($w) >= 3
        );

        $q = Post::query()
            ->with('source')
            ->whereNotNull('text')
            ->where(function ($sub) use ($fullTerm, $words) {
                $sub->whereRaw('LOWER(text) LIKE ?', [$fullTerm]);
                foreach ($words as $w) {
                    $sub->orWhereRaw('LOWER(text) LIKE ?', ["%{$w}%"]);
                }
            });

        if ($sourceId !== null) {
            $q->where('source_id', $sourceId);
        }

        if ($since !== null) {
            $q->where('published_at', '>=', $since);
        }

        if ($minViews > 0) {
            $q->where('views', '>=', $minViews);
        }

        return $q->orderByDesc('views')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  list<float>  $embedding
     */
    public function updateEmbedding(Post $post, array $embedding): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $vectorStr = '['.implode(',', $embedding).']';

            return DB::table('posts')
                ->where('id', $post->id)
                ->update(['embedding' => $vectorStr, 'updated_at' => now()]) > 0;
        }

        return $post->update(['embedding' => $embedding]);
    }
}
