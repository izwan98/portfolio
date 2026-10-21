<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// MLBB Draft: pull fresh hero win/pick/ban rates for every rank once a day.
Schedule::command('mlbb:refresh')
    ->dailyAt('05:00')
    ->timezone('Asia/Kuala_Lumpur')
    ->withoutOverlapping()
    ->onOneServer();
