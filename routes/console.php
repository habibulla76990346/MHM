<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Scheduled model catalog refresh (§11).
 *
 * Weekly, not daily: providers do not add models often, and each sync is an
 * authenticated call to a third party. Queued rather than run inline, so a
 * shared host running this from cron behaves the same as a VPS with a worker —
 * speed differs, capability does not.
 */
Schedule::command('aziv:models:sync')
    ->weeklyOn(1, '03:00')
    ->withoutOverlapping()
    ->onOneServer();
