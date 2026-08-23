<?php

namespace Tests\Unit\Support;

use App\Support\TelegramHelper;
use PHPUnit\Framework\TestCase;

class TelegramHelperTest extends TestCase
{
    public function test_normalize_username_with_at_prefix(): void
    {
        $this->assertSame('@durov', TelegramHelper::normalizeUsername('@durov'));
        $this->assertSame('@durov', TelegramHelper::normalizeUsername('durov'));
    }

    public function test_normalize_username_with_t_me_url(): void
    {
        $this->assertSame('@durov', TelegramHelper::normalizeUsername('https://t.me/durov'));
        $this->assertSame('@durov', TelegramHelper::normalizeUsername('http://t.me/durov'));
        $this->assertSame('@durov', TelegramHelper::normalizeUsername('t.me/durov'));
        $this->assertSame('@durov', TelegramHelper::normalizeUsername('https://telegram.me/durov'));
        $this->assertSame('@durov', TelegramHelper::normalizeUsername('https://www.t.me/durov/123'));
        $this->assertSame('@durov', TelegramHelper::normalizeUsername('https://t.me/durov?start=test'));
    }

    public function test_normalize_username_handles_empty_and_null(): void
    {
        $this->assertNull(TelegramHelper::normalizeUsername(null));
        $this->assertNull(TelegramHelper::normalizeUsername(''));
        $this->assertNull(TelegramHelper::normalizeUsername('   '));
        $this->assertNull(TelegramHelper::normalizeUsername('@'));
    }

    public function test_normalize_channels_array_and_comma_separated(): void
    {
        $this->assertSame(
            ['@durov', '@vibecodemoe'],
            TelegramHelper::normalizeChannels(['https://t.me/durov', '@vibecodemoe'])
        );

        $this->assertSame(
            ['@durov', '@vibecodemoe'],
            TelegramHelper::normalizeChannels('https://t.me/durov, vibecodemoe, @durov')
        );

        $this->assertSame([], TelegramHelper::normalizeChannels(null));
        $this->assertSame([], TelegramHelper::normalizeChannels(''));
    }
}
