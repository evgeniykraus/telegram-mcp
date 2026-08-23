<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hourly refresh of active tracked channels (within 14-day TTL window) and embeddings
Schedule::command('postiz:refresh')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Refresh metrics (views, reactions) for posts from the last 7 days every 3 hours
Schedule::command('postiz:refresh-metrics')
    ->everyThreeHours()
    ->withoutOverlapping()
    ->runInBackground();

// Daily pruning of expired idle channels and cache cleanup at 03:00
Schedule::command('postiz:prune')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();
