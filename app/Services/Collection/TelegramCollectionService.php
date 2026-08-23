<?php

namespace App\Services\Collection;

use App\Enums\CollectionRunStatus;
use App\Enums\ContentFormat;
use App\Factories\CustomMadelineProtoFactory;
use App\Models\CollectionRun;
use App\Models\Post;
use App\Repositories\Post\Contracts\PostRepositoryInterface;
use App\Repositories\Source\Contracts\SourceRepositoryInterface;
use App\Support\TelegramHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramCollectionService
{
    public function __construct(
        private readonly CustomMadelineProtoFactory $madelineProtoFactory,
        private readonly SourceRepositoryInterface $sourceRepository,
        private readonly PostRepositoryInterface $postRepository,
    ) {}

    public function collectChannel(
        string $username,
        int $limit = 100,
        ?Carbon $since = null
    ): CollectionRun {
        $cleanUsername = TelegramHelper::normalizeUsername($username) ?? $username;

        $source = $this->sourceRepository->firstOrCreate([
            'source_type' => 'telegram',
            'username' => $cleanUsername,
        ], [
            'title' => $cleanUsername,
            'is_active' => true,
        ]);

        $run = new CollectionRun([
            'id' => (string) Str::uuid(),
            'source_id' => $source->id,
            'started_at' => now(),
            'status' => CollectionRunStatus::Running,
        ]);
        $run->save();

        try {
            $api = $this->madelineProtoFactory->get();

            // Fetch and update channel metadata (subscribers, title, description)
            try {
                $fullInfo = $api->channels->getFullChannel(['channel' => $cleanUsername]);
                $participantsCount = $fullInfo['full_chat']['participants_count'] ?? null;
                $about = $fullInfo['full_chat']['about'] ?? null;
                $chatInfo = $fullInfo['chats'][0] ?? [];
                $title = $chatInfo['title'] ?? null;

                $channelUpdates = [];
                if ($participantsCount !== null) {
                    $channelUpdates['subscribers_count'] = (int) $participantsCount;
                }
                if (! empty($title)) {
                    $channelUpdates['title'] = (string) $title;
                }
                if (! empty($about)) {
                    $channelUpdates['description'] = (string) $about;
                }
                if (! empty($channelUpdates)) {
                    $this->sourceRepository->update($source, $channelUpdates);
                }
            } catch (\Throwable $infoEx) {
                Log::debug("Could not fetch full channel metadata for {$cleanUsername}: {$infoEx->getMessage()}");
            }

            $messages = $this->paginateHistory(
                fn (int $offsetId, int $batchLimit): array => $api->messages->getHistory([
                    'peer' => $cleanUsername,
                    'limit' => $batchLimit,
                    'offset_id' => $offsetId,
                    'offset_date' => 0,
                    'add_offset' => 0,
                    'max_id' => 0,
                    'min_id' => 0,
                    'hash' => 0,
                ])['messages'] ?? [],
                $limit,
                $since
            );

            $postsData = [];
            $fetchedCount = count($messages);

            foreach ($messages as $message) {
                if (! is_array($message) || ($message['_'] ?? '') !== 'message') {
                    continue;
                }

                $publishedAt = isset($message['date']) ? Carbon::createFromTimestamp($message['date']) : now();
                if ($since !== null && $publishedAt->lt($since)) {
                    continue;
                }

                $normalized = $this->normalizeMessage($message, $source->id, $cleanUsername);
                if ($normalized !== null) {
                    $postsData[] = $normalized;
                }
            }

            $savedCount = 0;
            if (! empty($postsData)) {
                $savedCount = DB::transaction(function () use ($postsData, $source): int {
                    $count = $this->postRepository->bulkUpsert($postsData);
                    $this->sourceRepository->update($source, ['last_collected_at' => now()]);

                    return $count;
                });
            }

            $run->update([
                'completed_at' => now(),
                'posts_fetched' => $fetchedCount,
                'posts_saved' => $savedCount,
                'status' => CollectionRunStatus::Success,
            ]);

            return $run;
        } catch (\Throwable $e) {
            Log::error("Failed to collect channel {$cleanUsername}: ".$e->getMessage(), [
                'exception' => $e,
            ]);

            $run->update([
                'completed_at' => now(),
                'status' => CollectionRunStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            return $run;
        }
    }

    /**
     * Page through getHistory until $limit messages are collected or $since is reached.
     *
     * @param  callable(int $offsetId, int $limit): list<array<string, mixed>>  $fetchPage
     * @return list<array<string, mixed>>
     */
    public function paginateHistory(callable $fetchPage, int $limit, ?Carbon $since = null): array
    {
        $allMessages = [];
        $offsetId = 0;
        $batchSize = (int) config('analytics.collection.pagination_batch_size', 100);
        $targetLimit = min($limit, (int) config('analytics.collection.max_limit', 2000));

        while (count($allMessages) < $targetLimit) {
            $batch = $fetchPage($offsetId, min($batchSize, $targetLimit - count($allMessages)));
            if (empty($batch)) {
                break;
            }

            foreach ($batch as $message) {
                if ($since !== null && isset($message['date'])
                    && Carbon::createFromTimestamp($message['date'])->lt($since)) {
                    break 2;
                }

                $allMessages[] = $message;
            }

            if (count($batch) < $batchSize) {
                break;
            }

            $offsetId = (int) ($batch[array_key_last($batch)]['id'] ?? 0);
        }

        return $allMessages;
    }

    /**
     * Fetch the latest posts from one or more channels on the fly (list, not counters).
     *
     * @param  list<string>  $channels
     * @return list<array<string, mixed>>
     */
    public function fetchRecentPosts(array $channels, ?Carbon $since = null, int $limit = 100): array
    {
        $api = $this->madelineProtoFactory->get();
        $posts = [];

        foreach ($channels as $channel) {
            $cleanUsername = TelegramHelper::normalizeUsername($channel) ?? $channel;
            $source = $this->sourceRepository->firstOrCreate(
                ['username' => $cleanUsername, 'source_type' => 'telegram'],
                ['title' => $cleanUsername, 'is_active' => true]
            );

            try {
                $messages = $this->paginateHistory(
                    fn (int $offsetId, int $batchLimit): array => $api->messages->getHistory([
                        'peer' => $cleanUsername,
                        'limit' => $batchLimit,
                        'offset_id' => $offsetId,
                        'offset_date' => 0,
                        'add_offset' => 0,
                        'max_id' => 0,
                        'min_id' => 0,
                        'hash' => 0,
                    ])['messages'] ?? [],
                    $limit,
                    $since
                );

                foreach ($messages as $message) {
                    if (! is_array($message) || ($message['_'] ?? '') !== 'message') {
                        continue;
                    }

                    $normalized = $this->normalizeMessage($message, $source->id, $cleanUsername);
                    if ($normalized !== null) {
                        $normalized['channel_username'] = $cleanUsername;
                        $posts[] = $normalized;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to fetch recent posts for {$cleanUsername}: ".$e->getMessage());
            }
        }

        return $posts;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    public function normalizeMessage(array $message, int $sourceId, string $channelUsername): ?array
    {
        $id = (string) ($message['id'] ?? '');
        if ($id === '') {
            return null;
        }

        $text = (string) ($message['message'] ?? '');
        $views = (int) ($message['views'] ?? 0);
        $forwards = (int) ($message['forwards'] ?? 0);
        $comments = (int) ($message['replies']['replies'] ?? 0);

        $reactionsBreakdown = [];
        $reactionsCount = 0;
        if (isset($message['reactions']['results']) && is_array($message['reactions']['results'])) {
            foreach ($message['reactions']['results'] as $reactionItem) {
                $emoticon = $reactionItem['reaction']['emoticon'] ?? null;
                $count = (int) ($reactionItem['count'] ?? 0);
                if ($emoticon !== null) {
                    $reactionsBreakdown[$emoticon] = $count;
                    $reactionsCount += $count;
                }
            }
        }

        $er = $views > 0 ? round(($reactionsCount + $forwards + $comments) / $views, 4) : 0.0;
        $isAd = $this->detectAd($text);
        $formatType = $this->detectFormat($message);

        $erWeight = (float) config('analytics.weights.er', 0.5);
        $forwardsWeight = (float) config('analytics.weights.forwards', 0.3);
        $viewsWeight = (float) config('analytics.weights.views', 0.2);

        $contentScore = round(($er * 100 * $erWeight) + ($forwards * $forwardsWeight) + ($views * 0.001 * $viewsWeight), 2);
        $cleanName = ltrim($channelUsername, '@');

        return [
            'source_id' => $sourceId,
            'external_id' => $id,
            'published_at' => isset($message['date']) ? Carbon::createFromTimestamp($message['date'])->toDateTimeString() : now()->toDateTimeString(),
            'text' => $text,
            'format_type' => $formatType,
            'url' => "https://t.me/{$cleanName}/{$id}",
            'views' => $views,
            'forwards' => $forwards,
            'reactions' => $reactionsCount,
            'comments' => $comments,
            'reactions_breakdown' => $reactionsBreakdown,
            'er' => $er,
            'content_score' => $contentScore,
            'is_ad' => $isAd,
            'forward_from_chat_id' => isset($message['fwd_from']['from_id']) ? (string) $message['fwd_from']['from_id'] : null,
            'raw_metadata' => $message,
        ];
    }

    public function detectAd(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        $pattern = '/erid[:\s=]|ерид[:\s=]|#реклама|#партнерский|#промо|реклама\.?\s+ооо|реклама\.?\s+ип|токен[:\s=]/iu';

        return preg_match($pattern, $text) === 1;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function detectFormat(array $message): string
    {
        $media = $message['media'] ?? null;
        if (! is_array($media)) {
            $entities = $message['entities'] ?? [];
            if (is_array($entities)) {
                foreach ($entities as $entity) {
                    if (($entity['_'] ?? '') === 'messageEntityUrl' || ($entity['_'] ?? '') === 'messageEntityTextUrl') {
                        return ContentFormat::Link->value;
                    }
                }
            }

            return ContentFormat::Text->value;
        }

        $mediaType = $media['_'] ?? '';

        return match ($mediaType) {
            'messageMediaPhoto' => ContentFormat::Photo->value,
            'messageMediaDocument' => $this->detectDocumentFormat($media),
            'messageMediaPoll' => ContentFormat::Poll->value,
            'messageMediaWebPage' => ContentFormat::Link->value,
            default => ContentFormat::Other->value,
        };
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function detectDocumentFormat(array $media): string
    {
        $mime = (string) ($media['document']['mime_type'] ?? '');
        if (str_starts_with($mime, 'video/')) {
            return ContentFormat::Video->value;
        }

        return ContentFormat::Document->value;
    }

    public function fetchSinglePost(string $channelUsername, int|string $postId): ?Post
    {
        $cleanUsername = TelegramHelper::normalizeUsername($channelUsername) ?? $channelUsername;
        $source = $this->sourceRepository->firstOrCreate(
            ['username' => $cleanUsername, 'source_type' => 'telegram'],
            ['title' => $cleanUsername, 'is_active' => true]
        );

        try {
            $api = $this->madelineProtoFactory->get();

            $res = $api->channels->getMessages([
                'channel' => $cleanUsername,
                'id' => [(int) $postId],
            ]);

            $message = $res['messages'][0] ?? null;
            if (! is_array($message) || ($message['_'] ?? '') !== 'message') {
                return null;
            }

            $normalized = $this->normalizeMessage($message, $source->id, $cleanUsername);
            if ($normalized === null) {
                return null;
            }

            $this->postRepository->bulkUpsert([$normalized]);

            return $this->postRepository->findByExternalId($source->id, (string) $postId);
        } catch (\Throwable $e) {
            Log::warning("Failed to fetch single post {$cleanUsername}/{$postId} via MTProto: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Batch-refresh metrics for existing posts via channels.getMessages.
     *
     * @param  list<string>  $externalIds
     */
    public function refreshPostMetrics(string $channelUsername, array $externalIds): int
    {
        $cleanUsername = TelegramHelper::normalizeUsername($channelUsername) ?? $channelUsername;
        $source = $this->sourceRepository->firstOrCreate(
            ['username' => $cleanUsername, 'source_type' => 'telegram'],
            ['title' => $cleanUsername, 'is_active' => true]
        );

        try {
            $messages = $this->fetchMessagesById($cleanUsername, $externalIds);

            $normalized = [];
            foreach ($messages as $message) {
                if (! is_array($message) || ($message['_'] ?? '') !== 'message') {
                    continue;
                }

                $post = $this->normalizeMessage($message, $source->id, $cleanUsername);
                if ($post !== null) {
                    $normalized[] = $post;
                }
            }

            if (empty($normalized)) {
                return 0;
            }

            return $this->postRepository->bulkUpsert($normalized);
        } catch (\Throwable $e) {
            Log::warning("Failed to refresh metrics for {$cleanUsername}: ".$e->getMessage());

            return 0;
        }
    }

    /**
     * @param  list<string>  $externalIds
     * @return list<array<string, mixed>>
     */
    protected function fetchMessagesById(string $channelUsername, array $externalIds): array
    {
        $api = $this->madelineProtoFactory->get();

        $res = $api->channels->getMessages([
            'channel' => $channelUsername,
            'id' => array_map('intval', $externalIds),
        ]);

        return $res['messages'] ?? [];
    }

    /**
     * Refresh metrics for posts published in the last N days, batched per channel.
     */
    public function refreshMetrics(int $days, int $batch, ?string $channel = null): int
    {
        $sourceId = null;
        if ($channel !== null) {
            $cleanUsername = TelegramHelper::normalizeUsername($channel);
            if ($cleanUsername === null) {
                return 0;
            }

            $source = $this->sourceRepository->findByUsername($cleanUsername);
            if ($source === null) {
                return 0;
            }

            $sourceId = $source->id;
        }

        $posts = $this->postRepository->getPostsForMetricsRefresh($days, $sourceId);
        $batch = max(1, $batch);

        $total = 0;
        foreach ($posts->groupBy('source_id') as $groupId => $group) {
            $source = $this->sourceRepository->findById($groupId);
            if ($source === null) {
                continue;
            }

            foreach (array_chunk($group->pluck('external_id')->all(), $batch) as $chunk) {
                $total += $this->refreshPostMetrics($source->username, $chunk);
            }
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchPostComments(
        string $channelUsername,
        int|string $postId,
        int $limit = 20,
        ?int $offsetId = null,
        ?int $sinceId = null,
    ): array {
        $cleanUsername = TelegramHelper::normalizeUsername($channelUsername) ?? $channelUsername;

        try {
            $api = $this->madelineProtoFactory->get();

            $disc = $api->messages->getDiscussionMessage([
                'peer' => $cleanUsername,
                'msg_id' => (int) $postId,
            ]);

            $chat = $disc['chats'][0] ?? null;
            if (! is_array($chat)) {
                return [
                    'channel' => $cleanUsername,
                    'post_id' => (int) $postId,
                    'total_comments' => 0,
                    'fetched_count' => 0,
                    'next_offset_id' => null,
                    'has_more' => false,
                    'last_seen_id' => null,
                    'thread_dialogue' => '',
                    'comments' => [],
                    'note' => 'No linked discussion group found for this channel',
                ];
            }

            $unreadCount = (int) ($disc['unread_count'] ?? 0);
            $commentsList = [];
            $nextOffsetId = null;

            if ($sinceId !== null) {
                $commentsList = $this->parseReplyMessages(
                    $this->fetchRepliesPage($api, $cleanUsername, (int) $postId, $limit, 0, $sinceId, 0),
                    $chat
                );
            } else {
                $cursor = $offsetId ?? 0;

                while (count($commentsList) < $limit) {
                    $pageSize = min($limit - count($commentsList), 100);
                    $messages = $this->fetchRepliesPage($api, $cleanUsername, (int) $postId, $pageSize, $cursor, 0, 0);
                    $page = $this->parseReplyMessages($messages, $chat);

                    if ($page === []) {
                        $nextOffsetId = null;
                        break;
                    }

                    $commentsList = array_merge($commentsList, $page);
                    $cursor = (int) ($messages[array_key_last($messages)]['id'] ?? 0);
                    $nextOffsetId = $cursor;

                    if (count($messages) < $pageSize) {
                        $nextOffsetId = null;
                        break;
                    }
                }
            }

            $lastSeenId = null;
            foreach ($commentsList as $comment) {
                $id = (int) ($comment['id'] ?? 0);
                if ($id > 0 && ($lastSeenId === null || $id > $lastSeenId)) {
                    $lastSeenId = $id;
                }
            }

            return [
                'channel' => $cleanUsername,
                'post_id' => (int) $postId,
                'total_comments' => $unreadCount > 0 ? $unreadCount : count($commentsList),
                'fetched_count' => count($commentsList),
                'next_offset_id' => $nextOffsetId,
                'has_more' => $nextOffsetId !== null,
                'last_seen_id' => $lastSeenId,
                'thread_dialogue' => $this->buildCommentDialogue($commentsList),
                'comments' => $commentsList,
            ];
        } catch (\Throwable $e) {
            Log::warning("Failed to fetch comments for {$cleanUsername}/{$postId}: ".$e->getMessage());

            return [
                'channel' => $cleanUsername,
                'post_id' => (int) $postId,
                'total_comments' => 0,
                'fetched_count' => 0,
                'next_offset_id' => null,
                'has_more' => false,
                'last_seen_id' => null,
                'thread_dialogue' => '',
                'comments' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch a single page of replies via messages.getReplies.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchRepliesPage(mixed $api, string $channelUsername, int $postId, int $limit, int $offsetId, int $minId, int $maxId): array
    {
        $replies = $api->messages->getReplies([
            'peer' => $channelUsername,
            'msg_id' => $postId,
            'offset_id' => $offsetId,
            'offset_date' => 0,
            'add_offset' => 0,
            'limit' => min($limit, 100),
            'max_id' => $maxId,
            'min_id' => $minId,
            'hash' => 0,
        ]);

        return $replies['messages'] ?? [];
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $chat
     * @return list<array<string, mixed>>
     */
    private function parseReplyMessages(array $messages, array $chat): array
    {
        $commentsList = [];

        foreach ($messages as $m) {
            if (is_array($m) && ! empty($m['message'])) {
                $text = trim($m['message']);
                $replyToId = $m['reply_to']['reply_to_msg_id'] ?? null;
                $dateStr = isset($m['date']) ? Carbon::createFromTimestamp($m['date'])->toDateTimeString() : null;
                $timeStr = isset($m['date']) ? Carbon::createFromTimestamp($m['date'])->format('H:i') : '';
                $isAuthor = ! empty($m['post']) || ($m['from_id']['channel_id'] ?? null) === ($chat['id'] ?? null);

                $commentsList[] = [
                    'id' => $m['id'],
                    'reply_to_msg_id' => $replyToId,
                    'is_author' => $isAuthor,
                    'time' => $timeStr,
                    'text' => $text,
                    'date' => $dateStr,
                ];
            }
        }

        return $commentsList;
    }

    /**
     * @param  list<array<string, mixed>>  $commentsList
     */
    private function buildCommentDialogue(array $commentsList): string
    {
        $idSet = array_flip(array_column($commentsList, 'id'));
        $threadDialogueLines = [];

        foreach ($commentsList as $c) {
            $authorBadge = $c['is_author'] ? '👑 [AUTHOR] ' : '';
            $replyTo = $c['reply_to_msg_id'];

            if ($replyTo !== null && isset($idSet[$replyTo])) {
                $threadDialogueLines[] = "  ↳ [#{$c['id']} {$c['time']}] {$authorBadge}{$c['text']}";
            } else {
                $threadDialogueLines[] = "• [#{$c['id']} {$c['time']}] {$authorBadge}{$c['text']}";
            }
        }

        return implode("\n", $threadDialogueLines);
    }
}
