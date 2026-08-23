<?php

namespace App\Repositories\Analytics;

use App\Models\Post;
use App\Models\Source;
use App\Repositories\Analytics\Contracts\AnalyticsRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyticsRepository implements AnalyticsRepositoryInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getChannelStats(int $sourceId, int $sinceDays = 30): array
    {
        $since = now()->subDays($sinceDays);

        $stats = DB::table('posts')
            ->where('source_id', $sourceId)
            ->where('published_at', '>=', $since)
            ->selectRaw('
                COUNT(*) as total_posts,
                COALESCE(SUM(views), 0) as total_views,
                COALESCE(AVG(views), 0) as avg_views,
                COALESCE(AVG(er), 0) as avg_er,
                COALESCE(MAX(er), 0) as best_er,
                COALESCE(SUM(forwards), 0) as total_forwards,
                COALESCE(SUM(reactions), 0) as total_reactions,
                COALESCE(SUM(comments), 0) as total_comments
            ')
            ->first();

        $bestPost = Post::query()
            ->where('source_id', $sourceId)
            ->where('published_at', '>=', $since)
            ->orderByDesc('er')
            ->first();

        return [
            'total_posts' => (int) ($stats->total_posts ?? 0),
            'total_views' => (int) ($stats->total_views ?? 0),
            'avg_views' => round((float) ($stats->avg_views ?? 0.0), 2),
            'avg_er' => round((float) ($stats->avg_er ?? 0.0), 4),
            'best_er' => round((float) ($stats->best_er ?? 0.0), 4),
            'total_forwards' => (int) ($stats->total_forwards ?? 0),
            'total_reactions' => (int) ($stats->total_reactions ?? 0),
            'total_comments' => (int) ($stats->total_comments ?? 0),
            'best_post' => $bestPost,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getReactionsSummary(int $sourceId, int $sinceDays = 30): array
    {
        $since = now()->subDays($sinceDays);
        $summary = [];

        Post::query()
            ->where('source_id', $sourceId)
            ->where('published_at', '>=', $since)
            ->whereNotNull('reactions_breakdown')
            ->pluck('reactions_breakdown')
            ->each(function ($breakdown) use (&$summary): void {
                foreach ((array) $breakdown as $emoji => $count) {
                    $summary[$emoji] = ($summary[$emoji] ?? 0) + (int) $count;
                }
            });

        arsort($summary);

        return array_slice($summary, 0, 10, true);
    }

    /**
     * @return Collection<int, \stdClass>
     */
    public function getFormatBreakdown(int $sinceDays = 30, ?int $sourceId = null): Collection
    {
        $since = now()->subDays($sinceDays);

        $query = DB::table('posts')
            ->where('published_at', '>=', $since)
            ->whereNotNull('format_type');

        if ($sourceId !== null) {
            $query->where('source_id', $sourceId);
        }

        $results = $query
            ->selectRaw('
                format_type,
                COUNT(*) as posts_count,
                COALESCE(SUM(views), 0) as total_views,
                COALESCE(AVG(views), 0) as avg_views,
                COALESCE(AVG(er), 0) as avg_er,
                COALESCE(SUM(forwards), 0) as total_forwards,
                COALESCE(AVG(forwards), 0) as avg_forwards
            ')
            ->groupBy('format_type')
            ->orderByDesc('posts_count')
            ->get();

        return new Collection($results->all());
    }

    /**
     * @return Collection<int, \stdClass>
     */
    public function getTimeSlots(int $sinceDays = 30, ?int $sourceId = null): Collection
    {
        $since = now()->subDays($sinceDays);

        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            $dowSql = "CAST(strftime('%w', published_at) AS INTEGER)";
            $hourSql = "CAST(strftime('%H', published_at) AS INTEGER)";
        } else {
            // PostgreSQL
            $dowSql = 'EXTRACT(DOW FROM published_at)';
            $hourSql = 'EXTRACT(HOUR FROM published_at)';
        }

        $query = DB::table('posts')
            ->where('published_at', '>=', $since)
            ->whereNotNull('published_at');

        if ($sourceId !== null) {
            $query->where('source_id', $sourceId);
        }

        $results = $query
            ->selectRaw("
                {$dowSql} as day_of_week,
                {$hourSql} as hour,
                COUNT(*) as posts_count,
                COALESCE(AVG(er), 0) as avg_er,
                COALESCE(AVG(views), 0) as avg_views
            ")
            ->groupByRaw("{$dowSql}, {$hourSql}")
            ->orderByRaw("{$dowSql}, {$hourSql}")
            ->get();

        return new Collection($results->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function getCategoryBenchmark(string $category, int $sinceDays = 30, ?string $ownUsername = null): array
    {
        $since = now()->subDays($sinceDays);

        $ownSource = $ownUsername !== null
            ? Source::query()->where('username', $ownUsername)->first()
            : null;

        $ownStats = $ownSource !== null
            ? DB::table('posts')
                ->where('source_id', $ownSource->id)
                ->where('published_at', '>=', $since)
                ->selectRaw('COALESCE(AVG(er), 0) as avg_er, COALESCE(AVG(views), 0) as avg_views')
                ->first()
            : null;

        $competitorStats = DB::table('posts')
            ->join('sources', 'posts.source_id', '=', 'sources.id')
            ->where('sources.is_active', true)
            ->where('posts.published_at', '>=', $since)
            ->when($category !== 'all', fn ($q) => $q->where('sources.category', $category))
            ->when($ownSource !== null, fn ($q) => $q->where('sources.id', '!=', $ownSource->id))
            ->selectRaw('COALESCE(AVG(posts.er), 0) as avg_er, COALESCE(AVG(posts.views), 0) as avg_views')
            ->first();

        $competitorSources = Source::query()
            ->where('is_active', true)
            ->when($category !== 'all', fn ($q) => $q->where('category', $category))
            ->when($ownSource !== null, fn ($q) => $q->where('id', '!=', $ownSource->id))
            ->get();

        return [
            'category' => $category,
            'period_days' => $sinceDays,
            'own_avg_er' => (float) ($ownStats->avg_er ?? 0.0),
            'own_avg_views' => (float) ($ownStats->avg_views ?? 0.0),
            'competitor_avg_er' => (float) ($competitorStats->avg_er ?? 0.0),
            'competitor_avg_views' => (float) ($competitorStats->avg_views ?? 0.0),
            'own_channels' => $ownSource !== null ? [[
                'username' => $ownSource->username,
                'subscribers_count' => $ownSource->subscribers_count,
            ]] : [],
            'competitor_channels' => $competitorSources->map(fn (Source $s): array => [
                'username' => $s->username,
                'subscribers_count' => $s->subscribers_count,
            ])->values()->all(),
        ];
    }

    public function getChannelMedianMetric(int $sourceId, string $column = 'views', int $sinceDays = 30): float
    {
        $since = now()->subDays($sinceDays);
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $result = DB::table('posts')
                ->where('source_id', $sourceId)
                ->where('published_at', '>=', $since)
                ->selectRaw("COALESCE(percentile_cont(0.5) WITHIN GROUP (ORDER BY {$column}), 0) as median")
                ->value('median');

            return (float) ($result ?? 0.0);
        }

        // SQLite / MySQL fallback
        $values = DB::table('posts')
            ->where('source_id', $sourceId)
            ->where('published_at', '>=', $since)
            ->orderBy($column)
            ->pluck($column)
            ->all();

        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        $middle = (int) floor($count / 2);
        if ($count % 2 === 0) {
            return (float) (($values[$middle - 1] + $values[$middle]) / 2.0);
        }

        return (float) $values[$middle];
    }

    /**
     * @return Collection<int, Post>
     */
    public function getOutlierPosts(float $minMultiplier = 2.0, int $sinceDays = 14, ?int $sourceId = null, int $limit = 20, string $dimension = 'views'): Collection
    {
        $since = now()->subDays($sinceDays);

        $sourcesQuery = Source::query()->where('is_active', true);
        if ($sourceId !== null) {
            $sourcesQuery->where('id', $sourceId);
        }
        $sources = $sourcesQuery->get();

        $outliers = collect();

        foreach ($sources as $source) {
            $medianViews = $this->getChannelMedianMetric($source->id, 'views', $sinceDays);
            $medianReactions = $this->getChannelMedianMetric($source->id, 'reactions', $sinceDays);
            $medianComments = $this->getChannelMedianMetric($source->id, 'comments', $sinceDays);
            $medianForwards = $this->getChannelMedianMetric($source->id, 'forwards', $sinceDays);
            $medianEr = $this->getChannelMedianMetric($source->id, 'er', $sinceDays);

            $medianEngagement = $medianReactions + ($medianComments * 2) + ($medianForwards * 1.5);

            $posts = Post::query()
                ->where('source_id', $source->id)
                ->where('published_at', '>=', $since)
                ->with('source')
                ->get();

            foreach ($posts as $post) {
                $viewsMultiplier = $medianViews > 0 ? round($post->views / $medianViews, 2) : 1.0;
                $postEngagement = $post->reactions + ($post->comments * 2) + ($post->forwards * 1.5);
                $engagementMultiplier = $medianEngagement > 0
                    ? round($postEngagement / $medianEngagement, 2)
                    : ($postEngagement > 0 ? round($postEngagement, 2) : 1.0);
                $erMultiplier = $medianEr > 0 ? round($post->er / $medianEr, 2) : 1.0;

                $dimensionMultiplier = match ($dimension) {
                    'views' => $viewsMultiplier,
                    'engagement' => $engagementMultiplier,
                    'er' => $erMultiplier,
                    default => max($viewsMultiplier, $engagementMultiplier, $erMultiplier),
                };

                if ($dimensionMultiplier >= $minMultiplier) {
                    if ($viewsMultiplier >= 1.5 && $engagementMultiplier >= 1.5) {
                        $outlierType = 'breakout';
                    } elseif ($post->comments >= 3 && $medianComments > 0 && ($post->comments / $medianComments) >= 2.0) {
                        $outlierType = 'discussion_magnet';
                    } elseif ($engagementMultiplier > $viewsMultiplier) {
                        $outlierType = 'high_engagement';
                    } else {
                        $outlierType = 'viral_views';
                    }

                    $post->setAttribute('channel_median_views', $medianViews);
                    $post->setAttribute('multiplier', $dimensionMultiplier);
                    $post->setAttribute('peak_multiplier', max($viewsMultiplier, $engagementMultiplier, $erMultiplier));
                    $post->setAttribute('views_multiplier', $viewsMultiplier);
                    $post->setAttribute('engagement_multiplier', $engagementMultiplier);
                    $post->setAttribute('er_multiplier', $erMultiplier);
                    $post->setAttribute('outlier_type', $outlierType);

                    $outliers->push($post);
                }
            }
        }

        return $outliers->sortByDesc('multiplier')->take($limit)->values();
    }
}
