<?php

namespace App\Services\Collection;

use App\Enums\ContentFormat;
use App\Models\Source;
use App\Repositories\Post\Contracts\PostRepositoryInterface;
use App\Repositories\Source\Contracts\SourceRepositoryInterface;
use App\Support\TelegramHelper;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramWebCollectorService
{
    public function __construct(
        private readonly SourceRepositoryInterface $sourceRepository,
        private readonly PostRepositoryInterface $postRepository,
    ) {}

    /**
     * @return array{posts_collected: int, source: Source}
     */
    public function collectChannel(string $username, int $limit = 100, ?Carbon $since = null): array
    {
        $cleanUsername = TelegramHelper::normalizeUsername($username) ?? $username;
        $rawUsername = ltrim($cleanUsername, '@');

        $source = $this->sourceRepository->firstOrCreate([
            'source_type' => 'telegram',
            'username' => $cleanUsername,
        ], [
            'title' => $cleanUsername,
            'is_active' => true,
        ]);

        $collectedPosts = [];
        $before = null;
        $channelInfoUpdated = false;

        while (count($collectedPosts) < $limit) {
            $url = "https://t.me/s/{$rawUsername}".($before ? "?before={$before}" : '');

            try {
                $response = $this->buildHttpClient()->get($url);

                if (! $response->successful()) {
                    break;
                }

                $html = $response->body();

                if (! $channelInfoUpdated) {
                    $this->updateChannelInfo($source, $html);
                    $channelInfoUpdated = true;
                }

                $posts = $this->parsePostsFromHtml($html, $source, $since);
                if (empty($posts)) {
                    break;
                }

                $minIdInBatch = null;
                foreach ($posts as $p) {
                    $id = (int) $p['external_id'];
                    if ($minIdInBatch === null || $id < $minIdInBatch) {
                        $minIdInBatch = $id;
                    }
                    $collectedPosts[$p['external_id']] = $p;
                }

                if ($minIdInBatch === null || ($before !== null && $minIdInBatch >= $before)) {
                    break;
                }

                $before = $minIdInBatch;
            } catch (\Throwable $e) {
                Log::warning("TelegramWebCollector error for {$username}: {$e->getMessage()}");
                break;
            }
        }

        $postsList = array_values(array_slice($collectedPosts, 0, $limit));

        if (! empty($postsList)) {
            $this->postRepository->bulkUpsert($postsList);
        }

        $source->update(['last_collected_at' => now()]);

        return [
            'posts_collected' => count($postsList),
            'source' => $source,
        ];
    }

    private function updateChannelInfo(Source $source, string $html): void
    {
        $title = null;
        if (preg_match('/<meta\s+property="og:title"\s+content="([^"]+)"/i', $html, $m)) {
            $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
        }

        $description = null;
        if (preg_match('/<meta\s+property="og:description"\s+content="([^"]+)"/is', $html, $m)) {
            $description = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
        }

        $subscribers = $source->subscribers_count ?? 0;
        if (preg_match('/<div\s+class="tgme_header_counter">\s*([0-9.,KMBkmb\s]+)\s+subscribers/i', $html, $m)) {
            $subscribers = $this->parseCounter($m[1]);
        } elseif (preg_match('/<span\s+class="counter_value">\s*([0-9.,KMBkmb\s]+)\s*<\/span>\s*<span\s+class="counter_type">\s*subscribers/i', $html, $m)) {
            $subscribers = $this->parseCounter($m[1]);
        }

        $source->update([
            'title' => $title ?: $source->title,
            'description' => $description ?: $source->description,
            'subscribers_count' => $subscribers ?: $source->subscribers_count,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parsePostsFromHtml(string $html, Source $source, ?Carbon $since = null): array
    {
        $posts = [];
        $weightEr = (float) config('analytics.weights.er', 0.5);
        $weightFwd = (float) config('analytics.weights.forwards', 0.3);
        $weightViews = (float) config('analytics.weights.views', 0.2);

        // Split HTML by message blocks
        $messageBlocks = preg_split('/<div\s+class="tgme_widget_message_wrap/i', $html);
        array_shift($messageBlocks); // Remove header before first post

        foreach ($messageBlocks as $block) {
            if (! preg_match('/data-post="[^"]*\/(\d+)"/i', $block, $pm)) {
                continue;
            }
            $externalId = $pm[1];

            // Published date
            $publishedAt = now();
            if (preg_match('/<time\s+datetime="([^"]+)"/i', $block, $tm)) {
                try {
                    $publishedAt = Carbon::parse($tm[1]);
                } catch (\Throwable) {
                }
            }

            if ($since !== null && $publishedAt->lt($since)) {
                continue;
            }

            // Post Text
            $text = '';
            if (preg_match('/<div\s+class="tgme_widget_message_text[^"]*"[^>]*>(.*?)<\/div>/is', $block, $txtm)) {
                $rawText = preg_replace('/<br\s*\/?>/i', "\n", $txtm[1]);
                $text = trim(html_entity_decode(strip_tags($rawText), ENT_QUOTES | ENT_HTML5));
            }

            // Views
            $views = 0;
            if (preg_match('/<span\s+class="tgme_widget_message_views">([^<]+)<\/span>/i', $block, $vm)) {
                $views = $this->parseCounter($vm[1]);
            }

            // Reactions breakdown
            $reactionsBreakdown = [];
            $totalReactions = 0;
            if (preg_match_all('/<span\s+class="tgme_reaction[^"]*">.*?<span\s+class="tgme_reaction_emoji[^"]*">([^<]+)<\/span>\s*<span\s+class="tgme_reaction_count">([^<]+)<\/span>/is', $block, $rm, PREG_SET_ORDER)) {
                foreach ($rm as $match) {
                    $emoji = trim($match[1]);
                    $count = $this->parseCounter($match[2]);
                    $reactionsBreakdown[$emoji] = $count;
                    $totalReactions += $count;
                }
            } elseif (preg_match_all('/<div\s+class="tgme_widget_message_reaction[^"]*".*?<span\s+class="emoji[^"]*"><b>([^<]+)<\/b><\/span>.*?<span\s+class="tgme_widget_message_reaction_count">([^<]+)<\/span>/is', $block, $rm, PREG_SET_ORDER)) {
                foreach ($rm as $match) {
                    $emoji = trim($match[1]);
                    $count = $this->parseCounter($match[2]);
                    $reactionsBreakdown[$emoji] = $count;
                    $totalReactions += $count;
                }
            }

            // Forwards
            $forwards = 0;
            if (preg_match('/<span\s+class="tgme_widget_message_forwards">([^<]+)<\/span>/i', $block, $fm)) {
                $forwards = $this->parseCounter($fm[1]);
            }

            // Comments
            $comments = 0;
            if (preg_match('/<span\s+class="tgme_widget_message_comments">.*?(\d+)/is', $block, $cm)) {
                $comments = (int) $cm[1];
            }

            // Detect Format
            $format = ContentFormat::Text;
            if (str_contains($block, 'tgme_widget_message_poll')) {
                $format = ContentFormat::Poll;
            } elseif (str_contains($block, 'tgme_widget_message_video')) {
                $format = ContentFormat::Video;
            } elseif (str_contains($block, 'tgme_widget_message_photo')) {
                $format = ContentFormat::Photo;
            } elseif (str_contains($block, 'tgme_widget_message_link_preview') || preg_match('/https?:\/\//i', $text)) {
                $format = ContentFormat::Link;
            }

            // Detect Ad
            $isAd = preg_match('/erid[:\s=]|ерид[:\s=]|#реклама|#партнерский|#промо|реклама\.?\s+ооо|реклама\.?\s+ип|токен[:\s=]/iu', $text) === 1;

            // Calculate ER & Content Score
            $safeViews = max($views, 1);
            $er = ($totalReactions + $forwards + $comments) / $safeViews;

            $erScore = min($er * 100, 100.0);
            $fwdScore = min(($forwards / $safeViews) * 1000, 100.0);
            $viewsScore = min(log10($safeViews + 1) * 20, 100.0);
            $contentScore = round(($erScore * $weightEr) + ($fwdScore * $weightFwd) + ($viewsScore * $weightViews), 2);

            $posts[] = [
                'source_id' => $source->id,
                'external_id' => $externalId,
                'published_at' => $publishedAt,
                'text' => $text,
                'format_type' => $format->value,
                'url' => 'https://t.me/'.ltrim($source->username, '@')."/{$externalId}",
                'views' => $views,
                'forwards' => $forwards,
                'reactions' => $totalReactions,
                'comments' => $comments,
                'reactions_breakdown' => $reactionsBreakdown,
                'er' => $er,
                'content_score' => $contentScore,
                'is_ad' => $isAd,
                'raw_metadata' => [
                    'collector' => 'web_preview',
                    'collected_at' => now()->toIso8601String(),
                ],
            ];
        }

        return $posts;
    }

    private function parseCounter(string $val): int
    {
        $clean = trim(str_replace([' ', ','], ['', '.'], $val));
        if (preg_match('/^([0-9.]+)\s*([KkMm]?)$/i', $clean, $m)) {
            $num = (float) $m[1];
            $unit = strtoupper($m[2] ?? '');
            if ($unit === 'K') {
                return (int) round($num * 1000);
            }
            if ($unit === 'M') {
                return (int) round($num * 1000000);
            }

            return (int) round($num);
        }

        return (int) $clean;
    }

    private function buildHttpClient(): PendingRequest
    {
        $client = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept-Language' => 'ru,en;q=0.9',
        ])->timeout(15);

        $proxyConfig = config('telegram.settings.connection.proxy');
        if (! empty($proxyConfig['address'])) {
            $type = $proxyConfig['type'] ?? 'socks5';
            $address = $proxyConfig['address'];
            $port = $proxyConfig['port'] ?? null;

            if (! str_contains($address, '://')) {
                $proxyUrl = "{$type}://{$address}".($port ? ":{$port}" : '');
            } else {
                $proxyUrl = $port && ! str_contains($address, ":{$port}") ? "{$address}:{$port}" : $address;
            }

            if (! empty($proxyConfig['username']) && ! empty($proxyConfig['password'])) {
                $parsed = parse_url($proxyUrl);
                $scheme = $parsed['scheme'] ?? $type;
                $host = $parsed['host'] ?? $address;
                $portPart = isset($parsed['port']) ? ":{$parsed['port']}" : ($port ? ":{$port}" : '');
                $auth = urlencode((string) $proxyConfig['username']).':'.urlencode((string) $proxyConfig['password']).'@';
                $proxyUrl = "{$scheme}://{$auth}{$host}{$portPart}";
            }

            $client = $client->withOptions(['proxy' => $proxyUrl]);
        }

        return $client;
    }
}
