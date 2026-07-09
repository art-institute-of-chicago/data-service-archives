<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('archives:sync-all')
    ->dailyAt('02:00')
    ->withoutOverlapping(525600);
