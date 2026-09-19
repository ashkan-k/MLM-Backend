<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// First day of each month: settle previous month bonuses (also refreshes continuously on each tx).
Schedule::command('finopal:pay-monthly-bonuses --month='.now()->subMonth()->format('Y-m').' --force')
    ->monthlyOn(1, '01:15')
    ->name('finopal-monthly-bonuses')
    ->withoutOverlapping();
