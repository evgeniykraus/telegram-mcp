<?php

namespace Tests\Unit;

use App\Support\TelegramHelper;
use PHPUnit\Framework\TestCase;

class TelegramHelperTest extends TestCase
{
    public function test_normalize_username_various_formats(): void
    {
        $this->assertEquals('@durov', TelegramHelper::normalizeUsername('durov'));
        $this->assertEquals('@durov', TelegramHelper::normalizeUsername('@durov'));
        $this->assertEquals('@durov', TelegramHelper::normalizeUsername('https://t.me/durov'));
        $this->assertEquals('@durov', TelegramHelper::normalizeUsername('http://t.me/durov/'));
        $this->assertEquals('@durov', TelegramHelper::normalizeUsername('https://telegram.me/durov'));
        $this->assertEquals('@durov', TelegramHelper::normalizeUsername('t.me/durov?start=ref'));
        $this->assertEquals('@durov', TelegramHelper::normalizeUsername('https://t.me/durov/12345'));
        $this->assertNull(TelegramHelper::normalizeUsername(''));
        $this->assertNull(TelegramHelper::normalizeUsername(null));
    }

    public function test_normalize_channels(): void
    {
        $input = 'https://t.me/channel1, @channel2, channel3, https://telegram.me/channel1';
        $normalized = TelegramHelper::normalizeChannels($input);

        $this->assertEquals(['@channel1', '@channel2', '@channel3'], $normalized);
    }
}
