<?php

use Illuminate\Console\Scheduling\Schedule;

app()->afterResolving(Schedule::class, static function (Schedule $schedule): void {
    $schedule->command('sync:nya-entry-ips --scheduled')->everyFiveMinutes()->onOneServer()->withoutOverlapping(10);
});
