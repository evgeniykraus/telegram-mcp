<?php

namespace App\Services\Collection;

use App\DTOs\Analytics\DiscoveredChannelDto;
use App\DTOs\Analytics\GlobalPostDto;
use App\Factories\CustomMadelineProtoFactory;
use App\Repositories\Source\Contracts\SourceRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TelegramDiscoveryService
{
    public function __construct(
        private readonly CustomMadelineProtoFactory $madelineProtoFactory,
        private readonly SourceRepositoryInterface $sourceRepository,
    ) {}

    /**
     * Discover popular thematic Telegram channels exclusively via Telegram MTProto API.
     *
     * @return list<DiscoveredChannelDto>
     */
    public function discoverChannels(
        string $query,
        int $limit = 10,
        int $minSubscribers = 0
    ): array {
        $channelsMap = [];
        $queryVariants = $this->expandSearchQueries($query);

        // 1. Search Telegram globally via MadelineProto API
        try {
            $api = $this->madelineProtoFactory->get();

            // 1a. Contacts search across top query variants
            foreach (array_slice($queryVariants, 0, 3) as $searchQuery) {
                try {
                    $searchResults = $api->contacts->search([
                        'q' => $searchQuery,
                        'limit' => min($limit * 2, 50),
                    ]);

                    $chats = $searchResults['chats'] ?? [];
                    $this->ingestChatsIntoMap($chats, $channelsMap);
                } catch (\Throwable $e) {
                    Log::debug("Telegram discovery contacts.search for '{$searchQuery}' error: ".$e->getMessage());
                }
            }

            // 1b. Extract active channels from global message search
            try {
                $globalSearch = $api->messages->searchGlobal([
                    'q' => $query,
                    'filter' => ['_' => 'inputMessagesFilterEmpty'],
                    'min_date' => 0,
                    'max_date' => 0,
                    'offset_rate' => 0,
                    'offset_peer' => ['_' => 'inputPeerEmpty'],
                    'offset_id' => 0,
                    'limit' => 20,
                ]);

                $chatsFromMessages = $globalSearch['chats'] ?? [];
                $this->ingestChatsIntoMap($chatsFromMessages, $channelsMap);
            } catch (\Throwable $e) {
                Log::debug("Telegram discovery searchGlobal chat extraction for '{$query}' error: ".$e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram discovery API error: '.$e->getMessage());
        }

        // 2. Filter channels
        // If minSubscribers is 0, we apply a sensible soft floor of 500 subscribers if better channels exist
        $hasSignificantChannels = false;
        foreach ($channelsMap as $item) {
            if ($item['subscribers_count'] >= 500) {
                $hasSignificantChannels = true;
                break;
            }
        }

        $effectiveMinSubs = ($minSubscribers === 0 && $hasSignificantChannels) ? 500 : $minSubscribers;

        $filtered = array_filter(
            $channelsMap,
            fn (array $item) => $item['subscribers_count'] >= $effectiveMinSubs && ! $item['is_scam'] && ! $item['is_fake']
        );

        // Fallback: If strict filter eliminated all results and user didn't ask for high threshold, relax floor
        if (empty($filtered) && $minSubscribers === 0) {
            $filtered = array_filter(
                $channelsMap,
                fn (array $item) => ! $item['is_scam'] && ! $item['is_fake']
            );
        }

        // 3. Calculate Relevance Score & Sort
        $scored = [];
        $queryWords = $this->extractMeaningfulTokens($query);

        foreach ($filtered as $key => $item) {
            $titleAndUsername = mb_strtolower(($item['title'] ?? '').' '.($item['username'] ?? ''));
            $matchCount = 0;
            foreach ($queryWords as $word) {
                if (str_contains($titleAndUsername, $word)) {
                    $matchCount++;
                }
            }

            $score = ($matchCount * 50)
                + ($item['is_verified'] ? 30 : 0)
                + ($item['is_already_tracked'] ? 10 : 0)
                + min(50, (int) (log10(max(1, $item['subscribers_count'])) * 10));

            $scored[] = [
                'data' => $item,
                'score' => $score,
                'subs' => $item['subscribers_count'],
            ];
        }

        usort($scored, function (array $a, array $b): int {
            if ($a['subs'] !== $b['subs']) {
                return $b['subs'] <=> $a['subs'];
            }

            return $b['score'] <=> $a['score'];
        });

        $topChannels = array_slice(array_column($scored, 'data'), 0, $limit);

        // 4. Enrich top channels with recent engagement metrics directly from Telegram
        $this->enrichChannelsWithRecentMetrics($topChannels, $api ?? null);

        return array_map(fn (array $item) => DiscoveredChannelDto::fromArray($item), $topChannels);
    }

    /**
     * Search recent posts and news globally exclusively via Telegram MTProto API.
     *
     * @return list<GlobalPostDto>
     */
    public function searchGlobalNews(
        string $query,
        int $limit = 15,
        ?int $sinceHours = 24,
        int $minViews = 0
    ): array {
        $postsMap = [];
        $queryVariants = $this->expandSearchQueries($query);

        // Search Telegram MTProto globally across query variants
        try {
            $api = $this->madelineProtoFactory->get();
            $minTimestamp = $sinceHours !== null ? Carbon::now()->subHours($sinceHours)->timestamp : 0;

            foreach (array_slice($queryVariants, 0, 3) as $searchQuery) {
                try {
                    $response = $api->messages->searchGlobal([
                        'q' => $searchQuery,
                        'filter' => ['_' => 'inputMessagesFilterEmpty'],
                        'min_date' => 0,
                        'max_date' => 0,
                        'offset_rate' => 0,
                        'offset_peer' => ['_' => 'inputPeerEmpty'],
                        'offset_id' => 0,
                        'limit' => min($limit * 2, 50),
                    ]);

                    $messages = $response['messages'] ?? [];
                    $chats = [];
                    foreach ($response['chats'] ?? [] as $chat) {
                        if (isset($chat['id'])) {
                            $rawIdStr = (string) $chat['id'];
                            $chats[$rawIdStr] = $chat;
                            $cleanId = ltrim(str_replace('-100', '', $rawIdStr), '-');
                            $chats[$cleanId] = $chat;
                            $chats['-100'.$cleanId] = $chat;
                            $chats['-'.$cleanId] = $chat;
                        }
                    }

                    foreach ($messages as $message) {
                        if (! is_array($message) || ($message['_'] ?? '') !== 'message' || empty($message['message'])) {
                            continue;
                        }

                        $msgDate = (int) ($message['date'] ?? 0);
                        if ($minTimestamp > 0 && $msgDate < $minTimestamp) {
                            continue;
                        }

                        $peer = $message['peer_id'] ?? ($message['from_id'] ?? null);
                        $peerIdStr = null;
                        if (is_array($peer)) {
                            $peerIdStr = (string) ($peer['channel_id'] ?? ($peer['chat_id'] ?? ($peer['user_id'] ?? '')));
                        } elseif (is_scalar($peer)) {
                            $peerIdStr = (string) $peer;
                        }

                        $chatInfo = null;
                        if ($peerIdStr !== null && $peerIdStr !== '') {
                            $cleanPeer = ltrim(str_replace('-100', '', $peerIdStr), '-');
                            $chatInfo = $chats[$peerIdStr] ?? ($chats[$cleanPeer] ?? ($chats['-100'.$cleanPeer] ?? ($chats['-'.$cleanPeer] ?? null)));
                        }

                        $rawUsername = (string) ($chatInfo['username'] ?? '');
                        $channelTitle = (string) ($chatInfo['title'] ?? '');
                        if ($rawUsername === '') {
                            continue;
                        }

                        $username = str_starts_with($rawUsername, '@') ? $rawUsername : '@'.$rawUsername;
                        $cleanUsername = ltrim($username, '@');
                        $id = (string) ($message['id'] ?? '');
                        $url = "https://t.me/{$cleanUsername}/{$id}";

                        $views = (int) ($message['views'] ?? 0);
                        if ($views < $minViews) {
                            continue;
                        }

                        $forwards = (int) ($message['forwards'] ?? 0);
                        $reactionsCount = 0;
                        if (isset($message['reactions']['results']) && is_array($message['reactions']['results'])) {
                            foreach ($message['reactions']['results'] as $reactionItem) {
                                $reactionsCount += (int) ($reactionItem['count'] ?? 0);
                            }
                        }

                        $comments = (int) ($message['replies']['replies'] ?? 0);
                        $er = $views > 0 ? round(($reactionsCount + $forwards + $comments) / $views, 4) : 0.0;
                        $contentScore = round(($er * 100 * 0.5) + ($forwards * 0.3) + ($views * 0.001 * 0.2), 2);
                        $publishedAt = isset($message['date']) ? Carbon::createFromTimestamp($message['date'])->toDateTimeString() : null;

                        if (! isset($postsMap[$url])) {
                            $postsMap[$url] = [
                                'id' => $id,
                                'channel_username' => $username,
                                'channel_title' => $channelTitle,
                                'url' => $url,
                                'published_at' => $publishedAt,
                                'text' => (string) $message['message'],
                                'views' => $views,
                                'forwards' => $forwards,
                                'reactions' => $reactionsCount,
                                'er' => $er,
                                'content_score' => $contentScore,
                            ];
                        }
                    }

                    // If we already collected plenty of posts, no need to over-query
                    if (count($postsMap) >= $limit * 3) {
                        break;
                    }
                } catch (\Throwable $ex) {
                    Log::debug("Telegram searchGlobal for '{$searchQuery}' error: ".$ex->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram searchGlobal error: '.$e->getMessage());
        }

        // Relevance ranking
        $queryTokens = $this->extractMeaningfulTokens($query);
        $scoredPosts = [];

        foreach ($postsMap as $post) {
            $textLower = mb_strtolower($post['text'] ?? '');
            $tokenMatches = 0;
            foreach ($queryTokens as $tok) {
                if (str_contains($textLower, $tok)) {
                    $tokenMatches++;
                }
            }

            $relevanceScore = ($tokenMatches * 1000) + ($post['views'] * 0.1) + ($post['content_score'] * 10);
            $scoredPosts[] = [
                'post' => $post,
                'score' => $relevanceScore,
                'views' => $post['views'],
            ];
        }

        usort($scoredPosts, function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $b['views'] <=> $a['views'];
        });

        $topPosts = array_slice(array_column($scoredPosts, 'post'), 0, $limit);

        return array_map(fn (array $item) => GlobalPostDto::fromArray($item), $topPosts);
    }

    /**
     * Ingest chat array into channels map with metadata and tracking check.
     *
     * @param  list<array<string, mixed>>  $chats
     * @param  array<string, array<string, mixed>>  $channelsMap
     */
    private function ingestChatsIntoMap(array $chats, array &$channelsMap): void
    {
        foreach ($chats as $chat) {
            if (! is_array($chat) || empty($chat['username'])) {
                continue;
            }

            $isBroadcast = ! empty($chat['broadcast']);
            $isMegagroup = ! empty($chat['megagroup']);
            if (! $isBroadcast && ! $isMegagroup && ($chat['_'] ?? '') !== 'channel') {
                continue;
            }

            $rawUsername = (string) $chat['username'];
            $username = str_starts_with($rawUsername, '@') ? $rawUsername : '@'.$rawUsername;
            $key = strtolower($username);

            $subscribersCount = (int) ($chat['participants_count'] ?? 0);
            $title = (string) ($chat['title'] ?? $rawUsername);
            $isVerified = ! empty($chat['verified']);
            $isScam = ! empty($chat['scam']);
            $isFake = ! empty($chat['fake']);

            $existing = $channelsMap[$key] ?? null;
            $isAlreadyTracked = $existing['is_already_tracked'] ?? false;
            $lastCollectedAt = $existing['last_collected_at'] ?? null;
            $postsCount = $existing['posts_count'] ?? 0;

            if (! $isAlreadyTracked) {
                $dbSource = $this->sourceRepository->findByUsername($username);
                if ($dbSource !== null) {
                    $isAlreadyTracked = true;
                    $lastCollectedAt = $dbSource->last_collected_at?->toDateTimeString();
                    $postsCount = $dbSource->posts()->count();
                    if ($subscribersCount === 0 && $dbSource->subscribers_count > 0) {
                        $subscribersCount = (int) $dbSource->subscribers_count;
                    }
                }
            }

            $channelsMap[$key] = [
                'username' => $username,
                'title' => $title,
                'description' => $existing['description'] ?? null,
                'subscribers_count' => max($subscribersCount, (int) ($existing['subscribers_count'] ?? 0)),
                'is_verified' => $isVerified,
                'is_scam' => $isScam,
                'is_fake' => $isFake,
                'url' => "https://t.me/{$rawUsername}",
                'is_already_tracked' => $isAlreadyTracked,
                'last_collected_at' => $lastCollectedAt,
                'posts_count' => $postsCount,
            ];
        }
    }

    /**
     * Enrich top channels with recent engagement metrics directly from Telegram MTProto API.
     *
     * @param  list<array<string, mixed>>  $channels
     */
    private function enrichChannelsWithRecentMetrics(array &$channels, mixed $api = null): void
    {
        if ($api === null) {
            return;
        }

        foreach ($channels as &$chan) {
            $username = (string) ($chan['username'] ?? '');
            $cleanUsername = ltrim($username, '@');

            if ($cleanUsername === '') {
                continue;
            }

            try {
                $history = $api->messages->getHistory([
                    'peer' => $cleanUsername,
                    'limit' => 5,
                    'offset_id' => 0,
                    'offset_date' => 0,
                    'add_offset' => 0,
                    'max_id' => 0,
                    'min_id' => 0,
                    'hash' => 0,
                ]);

                $messages = $history['messages'] ?? [];
                $viewsList = [];
                $erList = [];
                $lastDate = null;

                foreach ($messages as $m) {
                    if (! is_array($m) || ($m['_'] ?? '') !== 'message') {
                        continue;
                    }

                    if ($lastDate === null && isset($m['date'])) {
                        $lastDate = Carbon::createFromTimestamp($m['date'])->toDateTimeString();
                    }

                    $views = (int) ($m['views'] ?? 0);
                    if ($views > 0) {
                        $viewsList[] = $views;

                        $forwards = (int) ($m['forwards'] ?? 0);
                        $comments = (int) ($m['replies']['replies'] ?? 0);
                        $reactionsCount = 0;
                        if (isset($m['reactions']['results']) && is_array($m['reactions']['results'])) {
                            foreach ($m['reactions']['results'] as $r) {
                                $reactionsCount += (int) ($r['count'] ?? 0);
                            }
                        }

                        $er = round(($reactionsCount + $forwards + $comments) / $views, 4);
                        $erList[] = $er;
                    }
                }

                if (! empty($viewsList)) {
                    $chan['recent_avg_views'] = (int) round(array_sum($viewsList) / count($viewsList));
                }
                if (! empty($erList)) {
                    $chan['recent_er'] = round(array_sum($erList) / count($erList), 4);
                }
                if ($lastDate !== null) {
                    $chan['last_post_at'] = $lastDate;
                }
            } catch (\Throwable $ex) {
                Log::debug("Could not peek recent history for {$cleanUsername}: ".$ex->getMessage());
            }
        }
        unset($chan);
    }

    /**
     * Expand query into useful synonyms and component tokens.
     *
     * @return list<string>
     */
    private function expandSearchQueries(string $query): array
    {
        $trimmed = trim($query);
        $queries = [$trimmed];

        $lower = mb_strtolower($trimmed);

        // Common domain expansions
        if (str_contains($lower, 'искусственный интеллект') || $lower === 'ии' || str_contains($lower, 'нейросет')) {
            $queries[] = 'нейросети';
            $queries[] = 'AI';
            $queries[] = 'интеллект';
        } elseif (str_contains($lower, 'крипт') || str_contains($lower, 'биткоин') || str_contains($lower, 'crypto')) {
            $queries[] = 'криптовалюта';
            $queries[] = 'crypto';
            $queries[] = 'биткоин';
        } elseif (str_contains($lower, 'маркетинг') || str_contains($lower, 'marketing') || str_contains($lower, 'smm')) {
            $queries[] = 'маркетинг';
            $queries[] = 'smm';
            $queries[] = 'marketing';
        }

        // Add individual meaningful words (length >= 4)
        $words = $this->extractMeaningfulTokens($trimmed);
        foreach ($words as $word) {
            if (mb_strlen($word) >= 4 && ! in_array($word, $queries, true)) {
                $queries[] = $word;
            }
        }

        return array_values(array_unique($queries));
    }

    /**
     * Extract meaningful search tokens without stop words.
     *
     * @return list<string>
     */
    private function extractMeaningfulTokens(string $text): array
    {
        $stopWords = ['про', 'для', 'или', 'как', 'все', 'это', 'при', 'что', 'the', 'and', 'for', 'with', 'about'];
        $rawWords = preg_split('/[\s,\.\-_:\/\\\]+/u', mb_strtolower(trim($text))) ?: [];

        $filtered = array_filter(
            $rawWords,
            fn (string $w) => mb_strlen($w) >= 3 && ! in_array($w, $stopWords, true)
        );

        return array_values($filtered);
    }
}
