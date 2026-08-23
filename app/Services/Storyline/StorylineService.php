<?php

namespace App\Services\Storyline;

use App\Models\Post;
use App\Repositories\Post\Contracts\PostRepositoryInterface;
use App\Services\Pipeline\EmbeddingService;
use Carbon\Carbon;

class StorylineService
{
    public function __construct(
        private readonly PostRepositoryInterface $postRepository,
        private readonly EmbeddingService $embeddingService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function clusterStorylines(
        ?string $query = null,
        int $days = 3,
        int $limit = 200,
        int $minClusterSize = 2,
        float $similarityThreshold = 0.75,
    ): array {
        $posts = $this->postRepository->getRecentAcrossSources(Carbon::now()->subDays($days), $limit);

        if ($query !== null) {
            $posts = $posts->filter(fn (Post $post) => $this->matchesQuery($query, (string) $post->text));
        }

        // Ensure every post has an embedding (batch the missing ones on the fly).
        $missing = $posts->filter(fn (Post $post) => empty($post->embedding));
        if ($missing->isNotEmpty()) {
            $texts = $missing->map(fn (Post $post) => (string) $post->text)->values()->all();
            $embeddings = $this->embeddingService->embedBatch($texts);

            foreach ($missing->values() as $i => $post) {
                if (isset($embeddings[$i])) {
                    $post->embedding = $embeddings[$i];
                }
            }
        }

        $embedded = $posts->filter(fn (Post $post) => ! empty($post->embedding));

        // Greedy clustering. ponytail: O(n·k) pairwise scan — switch to a
        // vector index if the pool grows past a few thousand posts.
        $clusters = [];
        foreach ($embedded->sortBy(fn (Post $post) => $post->published_at?->getTimestamp() ?? 0) as $post) {
            $bestClusterIndex = null;
            $bestSim = 0.0;

            foreach ($clusters as $index => $cluster) {
                $sim = $this->cosineSimilarity($cluster['representative'], $post->embedding);
                if ($sim > $bestSim) {
                    $bestSim = $sim;
                    $bestClusterIndex = $index;
                }
            }

            if ($bestClusterIndex !== null && $bestSim >= $similarityThreshold) {
                $clusters[$bestClusterIndex]['posts'][] = $post;
            } else {
                $clusters[] = [
                    'representative' => $post->embedding,
                    'posts' => [$post],
                ];
            }
        }

        $storylines = [];
        foreach ($clusters as $cluster) {
            if (count($cluster['posts']) >= $minClusterSize) {
                $storylines[] = $this->buildStoryline($cluster['posts']);
            }
        }

        usort($storylines, function (array $a, array $b): int {
            if ($a['corroborated'] !== $b['corroborated']) {
                return $b['corroborated'] <=> $a['corroborated'];
            }

            if ($a['post_count'] !== $b['post_count']) {
                return $b['post_count'] <=> $a['post_count'];
            }

            return strcmp((string) $b['last_seen_at'], (string) $a['last_seen_at']);
        });

        return [
            'days' => $days,
            'posts_scanned' => count($embedded),
            'storylines_count' => count($storylines),
            'storylines' => array_values($storylines),
        ];
    }

    /**
     * @param  list<Post>  $posts
     * @return array<string, mixed>
     */
    private function buildStoryline(array $posts): array
    {
        usort($posts, fn (Post $a, Post $b): int => strcmp(
            (string) ($a->published_at?->toDateTimeString() ?? ''),
            (string) ($b->published_at?->toDateTimeString() ?? '')
        ));

        $channels = [];
        $items = [];

        foreach ($posts as $post) {
            $username = (string) ($post->source?->username ?? '');
            $username = $username !== '' && ! str_starts_with($username, '@') ? '@'.$username : $username;
            $channels[$username] = true;

            $items[] = [
                'channel_username' => $username,
                'url' => $post->url,
                'published_at' => $post->published_at?->toDateTimeString(),
                'text_preview' => mb_substr((string) $post->text, 0, 160),
                'views' => (int) $post->views,
                'forwards' => (int) $post->forwards,
            ];
        }

        $headlinePost = $posts[0];
        foreach ($posts as $post) {
            if ((int) $post->views > (int) $headlinePost->views) {
                $headlinePost = $post;
            }
        }

        return [
            'headline' => mb_substr((string) $headlinePost->text, 0, 200),
            'channels' => array_keys($channels),
            'corroborated' => count($channels) >= 2,
            'post_count' => count($posts),
            'first_seen_at' => $posts[0]->published_at?->toDateTimeString(),
            'last_seen_at' => $posts[count($posts) - 1]->published_at?->toDateTimeString(),
            'posts' => $items,
        ];
    }

    private function matchesQuery(string $query, string $text): bool
    {
        $tokens = preg_split('/[\s,\.\-_]+/u', mb_strtolower(trim($query))) ?: [];
        $lower = mb_strtolower($text);

        foreach ($tokens as $token) {
            if (mb_strlen($token) >= 4 && str_contains($lower, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $n = min(count($a), count($b));

        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
