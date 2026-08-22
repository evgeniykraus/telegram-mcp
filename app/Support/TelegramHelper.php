<?php

namespace App\Support;

class TelegramHelper
{
    /**
     * Normalize any Telegram channel representation (URL, handle, or raw username) to @username.
     */
    public static function normalizeUsername(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        // Strip protocol + domain, then take the first path segment without @, slashes or query params
        $clean = preg_replace('#^(?:https?://)?(?:www\.)?(?:t\.me|telegram\.me)/#i', '', trim($input));
        $clean = trim((string) preg_split('#[/?]#', (string) $clean)[0], " \t\n\r\0\x0B@");

        return $clean === '' ? null : '@'.$clean;
    }

    /**
     * Normalize a list of channels (comma-separated string or array) to clean @username array.
     *
     * @param  string|array<int|string, mixed>|null  $input
     * @return list<string>
     */
    public static function normalizeChannels(string|array|null $input): array
    {
        if ($input === null) {
            return [];
        }

        $items = is_array($input) ? $input : explode(',', (string) $input);
        $normalized = [];

        foreach ($items as $item) {
            $username = self::normalizeUsername(is_string($item) ? $item : (string) $item);
            if ($username !== null && ! in_array($username, $normalized, true)) {
                $normalized[] = $username;
            }
        }

        return $normalized;
    }
}
