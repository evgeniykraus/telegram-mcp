<?php

namespace App\Services\Analytics;

use App\DTOs\Analytics\BenchmarkDto;
use App\DTOs\Analytics\ChannelReportDto;
use App\DTOs\Analytics\FormatBreakdownDto;
use App\DTOs\Analytics\OutlierPostDto;
use App\DTOs\Analytics\PostDto;
use App\DTOs\Analytics\ReportDto;
use App\DTOs\Analytics\SimilarPostDto;
use App\DTOs\Analytics\StyleDnaDto;
use App\DTOs\Analytics\TimeSlotDto;
use App\DTOs\Analytics\TopicSaturationDto;
use App\Models\Post;
use App\Repositories\Analytics\Contracts\AnalyticsRepositoryInterface;
use App\Repositories\Post\Contracts\PostRepositoryInterface;
use App\Repositories\Source\Contracts\SourceRepositoryInterface;
use App\Services\Collection\TelegramCollectionService;
use App\Services\Pipeline\EmbeddingService;
use App\Support\TelegramHelper;
use Carbon\Carbon;

class AnalyticsService
{
    public function __construct(
        private readonly AnalyticsRepositoryInterface $analyticsRepository,
        private readonly SourceRepositoryInterface $sourceRepository,
        private readonly PostRepositoryInterface $postRepository,
        private readonly ?EmbeddingService $embeddingService = null,
        private readonly ?TelegramCollectionService $collectionService = null,
    ) {}

    public function getChannelReport(string $username, int $days = 30, bool $autoCollect = true): ChannelReportDto
    {
        $cleanUsername = TelegramHelper::normalizeUsername($username) ?? $username;
        $source = $this->sourceRepository->findByUsername($cleanUsername);

        if ($source === null && $autoCollect && $this->collectionService !== null) {
            $since = Carbon::now()->subDays($days);
            $this->collectionService->collectChannel($cleanUsername, 100, $since);
            $source = $this->sourceRepository->findByUsername($cleanUsername);
        }

        if ($source === null) {
            throw new \InvalidArgumentException("Source channel '{$cleanUsername}' not found. Please collect it first.");
        }

        $this->sourceRepository->touchQueried($source);

        $stats = $this->analyticsRepository->getChannelStats($source->id, $days);
        $bestPost = $stats['best_post'] ?? null;
        $reactionsSummary = $this->analyticsRepository->getReactionsSummary($source->id, $days);

        return ChannelReportDto::fromArray([
            'source_id' => $source->id,
            'username' => $source->username,
            'title' => $source->title,
            'subscribers_count' => $source->subscribers_count,
            'total_posts' => $stats['total_posts'],
            'total_views' => $stats['total_views'],
            'avg_views_per_post' => $stats['avg_views'],
            'avg_er' => $stats['avg_er'],
            'best_er' => $stats['best_er'],
            'total_forwards' => $stats['total_forwards'],
            'total_reactions' => $stats['total_reactions'],
            'total_comments' => $stats['total_comments'],
            'best_post_id' => $bestPost?->id,
            'best_post_url' => $bestPost?->url,
            'extra_metrics' => [
                'period_days' => $days,
                'last_collected_at' => $source->last_collected_at?->toIso8601String(),
                'top_reactions' => $reactionsSummary,
            ],
        ]);
    }

    /**
     * @return list<PostDto>
     */
    public function getTopPosts(
        int $limit = 20,
        ?string $username = null,
        ?int $sinceDays = 30,
        ?string $format = null,
        string $sortBy = 'content_score'
    ): array {
        $sourceId = null;
        if ($username !== null) {
            $cleanUsername = TelegramHelper::normalizeUsername($username) ?? $username;
            $source = $this->sourceRepository->findByUsername($cleanUsername);
            if ($source === null) {
                return [];
            }
            $this->sourceRepository->touchQueried($source);
            $sourceId = $source->id;
        }

        $posts = $this->postRepository->getTopPosts($limit, $sourceId, $sinceDays, $format, $sortBy);

        return $posts->map(fn (Post $post): PostDto => $this->postToDto($post))->values()->all();
    }

    public function getPostById(int|string $idOrUrl): ?PostDto
    {
        $post = null;
        $channel = null;
        $externalId = null;

        if (is_numeric($idOrUrl)) {
            $post = $this->postRepository->findById((int) $idOrUrl);
            if ($post !== null) {
                $channel = $post->source?->username;
                $externalId = $post->external_id;
            }
        } else {
            $url = (string) $idOrUrl;
            if (preg_match('#(?:t\.me|telegram\.me)/([^/]+)/(\d+)#i', $url, $matches)) {
                $channel = TelegramHelper::normalizeUsername($matches[1]);
                $externalId = $matches[2];
                $source = $channel ? $this->sourceRepository->findByUsername($channel) : null;
                $post = $source ? $this->postRepository->findByExternalId($source->id, $externalId) : null;
            }
        }

        // If post is missing or was scraped via web preview without reactions, fetch fresh via MTProto on-demand
        if (($post === null || empty($post->reactions_breakdown)) && $channel && $externalId && $this->collectionService !== null) {
            $fetched = $this->collectionService->fetchSinglePost($channel, $externalId);
            if ($fetched !== null) {
                $post = $fetched;
            }
        }

        if ($post === null) {
            return null;
        }

        return $this->postToDto($post);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPostComments(string|int $idOrUrl, int $limit = 20): array
    {
        $channel = null;
        $externalId = null;

        if (is_numeric($idOrUrl)) {
            $post = $this->postRepository->findById((int) $idOrUrl);
            if ($post !== null) {
                $channel = $post->source?->username;
                $externalId = $post->external_id;
            }
        } else {
            $url = (string) $idOrUrl;
            if (preg_match('#(?:t\.me|telegram\.me)/([^/]+)/(\d+)#i', $url, $matches)) {
                $channel = TelegramHelper::normalizeUsername($matches[1]);
                $externalId = $matches[2];
            }
        }

        if ($channel === null || $externalId === null || $this->collectionService === null) {
            return [
                'total_comments' => 0,
                'comments' => [],
                'error' => 'Unable to determine channel or post ID from input.',
            ];
        }

        return $this->collectionService->fetchPostComments($channel, $externalId, $limit);
    }

    /**
     * @return list<FormatBreakdownDto>
     */
    public function getFormatBreakdown(int $days = 30, ?string $username = null): array
    {
        $sourceId = null;
        if ($username !== null) {
            $cleanUsername = TelegramHelper::normalizeUsername($username) ?? $username;
            $source = $this->sourceRepository->findByUsername($cleanUsername);
            if ($source === null) {
                return [];
            }
            $this->sourceRepository->touchQueried($source);
            $sourceId = $source->id;
        }

        $rawBreakdown = $this->analyticsRepository->getFormatBreakdown($days, $sourceId);
        $totalPostsAllFormats = $rawBreakdown->sum('posts_count');

        return $rawBreakdown->map(function ($item) use ($totalPostsAllFormats): FormatBreakdownDto {
            $postsCount = (int) $item->posts_count;
            $share = $totalPostsAllFormats > 0 ? round($postsCount / $totalPostsAllFormats, 4) : 0.0;

            return FormatBreakdownDto::fromArray([
                'format_type' => $item->format_type,
                'posts_count' => $postsCount,
                'total_views' => (int) $item->total_views,
                'avg_views' => round((float) $item->avg_views, 2),
                'avg_er' => round((float) $item->avg_er, 4),
                'total_forwards' => (int) $item->total_forwards,
                'avg_forwards' => round((float) $item->avg_forwards, 2),
                'share_of_posts' => $share,
            ]);
        })->values()->all();
    }

    /**
     * @return list<TimeSlotDto>
     */
    public function getBestTimeToPost(int $days = 30, ?string $username = null): array
    {
        $sourceId = null;
        if ($username !== null) {
            $cleanUsername = TelegramHelper::normalizeUsername($username) ?? $username;
            $source = $this->sourceRepository->findByUsername($cleanUsername);
            if ($source === null) {
                return [];
            }
            $this->sourceRepository->touchQueried($source);
            $sourceId = $source->id;
        }

        $rawSlots = $this->analyticsRepository->getTimeSlots($days, $sourceId);
        $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        return $rawSlots->map(function ($slot) use ($dayNames): TimeSlotDto {
            $dow = (int) $slot->day_of_week;
            $dayName = $dayNames[$dow % 7] ?? 'Unknown';
            $avgEr = round((float) $slot->avg_er, 4);
            $avgViews = round((float) $slot->avg_views, 2);
            $performanceScore = round(($avgEr * 100 * 0.7) + ($avgViews * 0.001 * 0.3), 2);

            return TimeSlotDto::fromArray([
                'day_of_week' => $dow,
                'day_name' => $dayName,
                'hour' => (int) $slot->hour,
                'posts_count' => (int) $slot->posts_count,
                'avg_er' => $avgEr,
                'avg_views' => $avgViews,
                'performance_score' => $performanceScore,
            ]);
        })->sortByDesc('performance_score')->values()->all();
    }

    public function getBenchmark(int $days = 30, string $category = 'all'): BenchmarkDto
    {
        $benchmarkData = $this->analyticsRepository->getCategoryBenchmark($category, $days);

        $ownEr = round((float) $benchmarkData['own_avg_er'], 4);
        $compEr = round((float) $benchmarkData['competitor_avg_er'], 4);
        $erDiff = $compEr > 0 ? round((($ownEr - $compEr) / $compEr) * 100, 2) : 0.0;

        $ownViews = round((float) $benchmarkData['own_avg_views'], 2);
        $compViews = round((float) $benchmarkData['competitor_avg_views'], 2);
        $viewsDiff = $compViews > 0 ? round((($ownViews - $compViews) / $compViews) * 100, 2) : 0.0;

        return BenchmarkDto::fromArray([
            'category' => $category,
            'period_days' => $days,
            'own_avg_er' => $ownEr,
            'competitor_avg_er' => $compEr,
            'er_difference_percent' => $erDiff,
            'own_avg_views' => $ownViews,
            'competitor_avg_views' => $compViews,
            'views_difference_percent' => $viewsDiff,
            'own_channels' => $benchmarkData['own_channels'],
            'competitor_channels' => $benchmarkData['competitor_channels'],
        ]);
    }

    public function getCompositeReport(int $days = 30, ?string $username = null): ReportDto
    {
        $cleanUsername = $username !== null ? (TelegramHelper::normalizeUsername($username) ?? $username) : null;
        $summary = [];
        if ($cleanUsername !== null) {
            $channelReport = $this->getChannelReport($cleanUsername, $days);
            $summary = $channelReport->toArray();
        }

        $topPosts = array_map(fn ($p) => $p->toArray(), $this->getTopPosts(10, $cleanUsername, $days));
        $formats = array_map(fn ($f) => $f->toArray(), $this->getFormatBreakdown($days, $cleanUsername));
        $bestTimeSlots = array_map(fn ($t) => $t->toArray(), array_slice($this->getBestTimeToPost($days, $cleanUsername), 0, 5));

        $benchmark = null;
        if ($cleanUsername !== null) {
            $source = $this->sourceRepository->findByUsername($cleanUsername);
            if ($source && $source->category) {
                $benchmark = $this->getBenchmark($days, $source->category)->toArray();
            }
        }

        return ReportDto::fromArray([
            'period_days' => $days,
            'channel_username' => $cleanUsername,
            'summary' => $summary,
            'top_posts' => $topPosts,
            'formats' => $formats,
            'best_time_slots' => $bestTimeSlots,
            'benchmark' => $benchmark,
        ]);
    }

    /**
     * @return list<SimilarPostDto>
     */
    public function searchSimilarPosts(string|int $queryOrPostId, int $limit = 10, ?string $channel = null): array
    {
        $sourceId = null;
        if ($channel !== null) {
            $cleanChannel = TelegramHelper::normalizeUsername($channel) ?? $channel;
            $source = $this->sourceRepository->findByUsername($cleanChannel);
            if ($source !== null) {
                $sourceId = $source->id;
            }
        }

        $embedding = null;
        $excludePostId = null;

        if (is_numeric($queryOrPostId)) {
            $post = $this->postRepository->findById((int) $queryOrPostId);
            if ($post !== null) {
                $excludePostId = $post->id;
                if (! empty($post->embedding) && is_array($post->embedding)) {
                    $embedding = $post->embedding;
                } elseif (! empty($post->text) && $this->embeddingService !== null) {
                    $embedding = $this->embeddingService->embedText($post->text);
                    if ($embedding !== null) {
                        $this->postRepository->updateEmbedding($post, $embedding);
                    }
                }
            }
        }

        if ($embedding === null && is_string($queryOrPostId) && $this->embeddingService !== null) {
            $embedding = $this->embeddingService->embedText($queryOrPostId);
        }

        if ($embedding === null) {
            return [];
        }

        $results = $this->postRepository->searchSimilarByVector($embedding, $limit, $excludePostId, $sourceId);

        return $results->map(function ($post): SimilarPostDto {
            $distance = isset($post->distance) ? (float) $post->distance : null;
            $similarity = $distance !== null ? round(max(0.0, 1.0 - $distance), 4) : null;

            return SimilarPostDto::fromArray([
                'id' => $post->id,
                'external_id' => $post->external_id,
                'source_username' => $post->source?->username ?? '',
                'url' => $post->url,
                'published_at' => $post->published_at?->toIso8601String(),
                'text' => $post->text,
                'views' => $post->views,
                'forwards' => $post->forwards,
                'reactions' => $post->reactions,
                'er' => $post->er,
                'content_score' => $post->content_score,
                'similarity_score' => $similarity,
            ]);
        })->values()->all();
    }

    public function embedPendingPosts(int $limit = 100): int
    {
        if ($this->embeddingService === null) {
            return 0;
        }

        $posts = $this->postRepository->getPostsWithoutEmbeddings($limit);
        if ($posts->isEmpty()) {
            return 0;
        }

        $texts = $posts->pluck('text')->all();
        $embeddings = $this->embeddingService->embedBatch($texts);

        $embeddedCount = 0;
        foreach ($posts as $index => $post) {
            if (isset($embeddings[$index])) {
                $this->postRepository->updateEmbedding($post, $embeddings[$index]);
                $embeddedCount++;
            }
        }

        return $embeddedCount;
    }

    /**
     * @return list<OutlierPostDto>
     */
    public function getViralOutliers(
        float $minMultiplier = 2.0,
        int $days = 14,
        ?string $channel = null,
        int $limit = 20
    ): array {
        $sourceId = null;
        if ($channel !== null) {
            $cleanUsername = TelegramHelper::normalizeUsername($channel) ?? $channel;
            $source = $this->sourceRepository->findByUsername($cleanUsername);
            if ($source === null) {
                return [];
            }
            $this->sourceRepository->touchQueried($source);
            $sourceId = $source->id;
        }

        $outliers = $this->analyticsRepository->getOutlierPosts($minMultiplier, $days, $sourceId, $limit);

        return $outliers->map(function ($post): OutlierPostDto {
            $rawText = (string) ($post->text ?? '');
            $lines = explode("\n", trim($rawText));
            $firstLine = trim($lines[0] ?? '');
            $hook = mb_strlen($firstLine) > 120 ? mb_substr($firstLine, 0, 120).'...' : $firstLine;
            $textPreview = mb_strlen($rawText) > 200 ? mb_substr($rawText, 0, 200).'...' : $rawText;

            return OutlierPostDto::fromArray([
                'id' => $post->id,
                'source_id' => $post->source_id,
                'source_username' => $post->source?->username ?? '',
                'url' => $post->url,
                'published_at' => $post->published_at?->toIso8601String(),
                'views' => (int) $post->views,
                'reactions' => (int) $post->reactions,
                'comments' => (int) $post->comments,
                'forwards' => (int) $post->forwards,
                'channel_median_views' => round((float) ($post->getAttribute('channel_median_views') ?? 0.0), 2),
                'multiplier' => round((float) ($post->getAttribute('multiplier') ?? 0.0), 2),
                'views_multiplier' => round((float) ($post->getAttribute('views_multiplier') ?? 0.0), 2),
                'engagement_multiplier' => round((float) ($post->getAttribute('engagement_multiplier') ?? 0.0), 2),
                'er' => round((float) ($post->er ?? 0.0), 4),
                'outlier_type' => (string) ($post->getAttribute('outlier_type') ?? 'viral_views'),
                'format_type' => $post->format_type,
                'hook' => $hook,
                'text_preview' => $textPreview,
                'reactions_breakdown' => $post->reactions_breakdown,
            ]);
        })->all();
    }

    public function checkTopicSaturation(string $topicQuery, int $days = 14, ?string $channel = null): TopicSaturationDto
    {
        $similarPosts = $this->searchSimilarPosts($topicQuery, 20, $channel);

        // Filter by timeframe
        $since = Carbon::now()->subDays($days);
        $recentSimilar = array_values(array_filter($similarPosts, function ($p) use ($since) {
            if (empty($p->publishedAt)) {
                return true;
            }

            return Carbon::parse($p->publishedAt)->gte($since);
        }));

        $count = count($recentSimilar);
        $avgSim = 0.0;
        if ($count > 0) {
            $sims = array_filter(array_map(fn ($p) => $p->similarityScore ?? 0.0, $recentSimilar));
            $avgSim = ! empty($sims) ? round(array_sum($sims) / count($sims), 3) : 0.0;
        }

        $angles = [];
        foreach (array_slice($recentSimilar, 0, 5) as $p) {
            $rawText = (string) ($p->text ?? '');
            $firstLine = trim(explode("\n", $rawText)[0] ?? '');
            $angles[] = [
                'channel' => $p->sourceUsername,
                'url' => $p->url,
                'hook' => mb_substr($firstLine, 0, 100),
                'views' => $p->views,
                'published_at' => $p->publishedAt,
                'similarity' => $p->similarityScore,
            ];
        }

        if ($count >= 6) {
            $saturationLevel = 'fatigued';
            $recommendation = 'Тема перегрета конкурентами (более 5 постов за период). Избегайте прямого пересказа. Рекомендуется контр-интуитивный угол (contrarian take), разбор неочевидных ошибок или смена формата на кейс.';
        } elseif ($count >= 2) {
            $saturationLevel = 'active';
            $recommendation = 'Тема в активном тренде. Спрос аудитории подтвержден. Рекомендуется углубить практическую часть: дать пошаговый алгоритм, конкретные промпты или готовый шаблон.';
        } else {
            $saturationLevel = 'emerging';
            $recommendation = 'Свежая / мало раскрытая нишевая тема. Отличная возможность занять позицию первоисточника с подробным лонгридом или обучалкой.';
        }

        return TopicSaturationDto::fromArray([
            'query' => $topicQuery,
            'period_days' => $days,
            'posts_count' => $count,
            'avg_similarity' => $avgSim,
            'saturation_level' => $saturationLevel,
            'recommendation' => $recommendation,
            'recent_angles' => $angles,
        ]);
    }

    public function getChannelStyleDna(string $channel, int $samplePostsCount = 30): StyleDnaDto
    {
        $cleanUsername = TelegramHelper::normalizeUsername($channel) ?? $channel;
        $source = $this->sourceRepository->findByUsername($cleanUsername);
        if ($source === null && $this->collectionService !== null) {
            $this->collectionService->collectChannel($cleanUsername, 100, Carbon::now()->subDays(60));
            $source = $this->sourceRepository->findByUsername($cleanUsername);
        }

        if ($source === null) {
            throw new \InvalidArgumentException("Channel '{$cleanUsername}' not found.");
        }

        $this->sourceRepository->touchQueried($source);

        $posts = $this->postRepository->getRecentBySource($source->id, $samplePostsCount);
        if ($posts->isEmpty()) {
            throw new \InvalidArgumentException("No posts found for '{$cleanUsername}' to analyze style.");
        }

        $totalChars = 0;
        $totalParagraphs = 0;
        $totalWords = 0;
        $totalEmojis = 0;
        $hookTypes = [];
        $dividers = [];
        $ctas = [];

        foreach ($posts as $post) {
            $text = (string) ($post->text ?? '');
            if (trim($text) === '') {
                continue;
            }

            $totalChars += mb_strlen($text);
            $paragraphs = array_filter(array_map('trim', explode("\n", $text)));
            $totalParagraphs += count($paragraphs);

            $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
            $totalWords += count($words);

            // Emoji counting
            preg_match_all('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', $text, $emojiMatches);
            $totalEmojis += count($emojiMatches[0] ?? []);

            // Detect dividers
            if (str_contains($text, '━━━━') || str_contains($text, '----') || str_contains($text, '——') || str_contains($text, '***')) {
                $dividers['horizontal_lines'] = 'Использует горизонтальные разделители (━━━━ / —— / ***)';
            }
            if (preg_match('/^[—–•\-*]\s+/m', $text)) {
                $dividers['bullet_lists'] = 'Использует маркированные списки с тире или маркерами (— ...)';
            }
            if (preg_match('/^[🪙🗿🛡🎸⚡️🐱🐈🔥📌🔹🔸▪️▫️]\s+/mu', $text)) {
                $dividers['emoji_bullet_headers'] = 'Использует тематические эмодзи-маркеры для смысловых блоков';
            }

            // Hook types
            $firstLine = $paragraphs[0] ?? '';
            if (preg_match('/^(?:🔖|📖|📄|📊|💡|🔥|⚡️|🎸|🐱|🐈|🚀|🎯|✨)/u', $firstLine)) {
                $hookTypes['emoji_badge_heading'] = 'Эмодзи-плашка в начале заголовка (🔖 / ⚡️ / 🎸 / 💡)';
            }
            if (preg_match('/^(?:Кейс|Отчёт|Инструкция|Разбор|Апдейт|Дайджест|Обзор|Новость)/ui', $firstLine)) {
                $hookTypes['case_report_heading'] = 'Рубрикаторы («Кейс: ...», «Отчёт: ...», «Инструкция: ...»)';
            }
            if (preg_match('/\d+/u', $firstLine) && (str_contains($firstLine, '₽') || str_contains($firstLine, '$') || str_contains($firstLine, 'тысяч') || str_contains($firstLine, '%'))) {
                $hookTypes['numbers_and_money'] = 'Числовые факты и метрики в первой строке';
            }
            if (preg_match('/^(?:Чиним|Поднимаем|Даем|Экономим|Делаем|Как|Почему|Разбираем)/ui', $firstLine)) {
                $hookTypes['action_verb_hook'] = 'Глагольный хук действия в заголовке («Чиним...», «Поднимаем...», «Экономим...»)';
            }

            // CTA types
            $lastLines = implode(' ', array_slice($paragraphs, -2));
            if (str_contains($lastLines, '👇') || str_contains($lastLines, 'ссылк') || str_contains($lastLines, 'кнопк') || str_contains($lastLines, 'забирай') || str_contains($lastLines, 'скачать')) {
                $ctas['benefit_lead_magnet'] = 'Выдача пользы/лид-магнита за клик/переход («Жми на кнопку 👇», «Забирай по ссылке»)';
            }
            if (str_contains($lastLines, '?') && (str_contains($lastLines, 'Как у вас') || str_contains($lastLines, 'Что думаете') || str_contains($lastLines, 'поделитесь'))) {
                $ctas['open_question_comment'] = 'Открытый вовлекающий вопрос в конце поста';
            }
            if (preg_match('/(?:Помните|один ключ|все модели|подписывайся|подпишись|ссылка в закрепе)/ui', $lastLines)) {
                $ctas['signature_brand_slogan'] = 'Фирменный слоган / подвал канала («Помните, один ключ — все модели!»)';
            }
            if (preg_match('/(?:npx\s+|pip\s+install|git\s+clone)/ui', $text)) {
                $ctas['installation_snippet'] = 'Инструкция по установке утилиты (npx / pip install)';
            }
        }

        $analyzedCount = max(1, $posts->count());
        $avgPostLength = (int) round($totalChars / $analyzedCount);
        $avgParagraphs = (int) round($totalParagraphs / $analyzedCount);
        $emojiDensity = $totalWords > 0 ? round(($totalEmojis / $totalWords) * 100, 2) : 0.0;

        // Top 3 exemplar posts
        $exemplarPosts = $posts->sortByDesc('views')->take(3)->map(function ($p) {
            return [
                'id' => $p->id,
                'url' => $p->url,
                'views' => $p->views,
                'format' => $p->format_type,
                'text' => $p->text,
            ];
        })->values()->all();

        return StyleDnaDto::fromArray([
            'channel' => $source->username,
            'analyzed_posts_count' => $analyzedCount,
            'avg_post_length_chars' => $avgPostLength,
            'avg_paragraphs_count' => $avgParagraphs,
            'emoji_density_per_100_words' => $emojiDensity,
            'detected_hook_types' => array_values($hookTypes),
            'dividers_used' => array_values($dividers),
            'signature_cta_types' => array_values($ctas),
            'exemplar_posts' => $exemplarPosts,
        ]);
    }

    private function postToDto(Post $post): PostDto
    {
        return PostDto::fromArray([
            'id' => $post->id,
            'source_id' => $post->source_id,
            'source_username' => $post->source?->username ?? '',
            'external_id' => $post->external_id,
            'url' => $post->url,
            'published_at' => $post->published_at?->toIso8601String(),
            'text' => $post->text,
            'format_type' => $post->format_type,
            'views' => $post->views,
            'forwards' => $post->forwards,
            'reactions' => $post->reactions,
            'comments' => $post->comments,
            'er' => $post->er,
            'content_score' => $post->content_score,
            'viral_factor' => $post->viral_factor,
            'discussion_index' => $post->discussion_index,
            'is_ad' => $post->is_ad,
            'reactions_breakdown' => $post->reactions_breakdown,
        ]);
    }
}
