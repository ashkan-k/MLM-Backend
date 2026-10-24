<?php

use App\Support\JalaliDate;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// First day of each Jalali month (Tehran): settle previous business month.
Schedule::call(function () {
    if (JalaliDate::parts()[2] !== 1) {
        return;
    }
    Artisan::call('finopal:pay-monthly-bonuses', [
        '--month' => JalaliDate::businessPreviousMonthKey(now()),
        '--force' => true,
    ]);
})
    ->dailyAt('01:15')
    ->timezone(JalaliDate::TIMEZONE)
    ->name('finopal-monthly-bonuses')
    ->withoutOverlapping();

Schedule::command('finopal:dispatch-crm-reminders')
    ->everyMinute()
    ->name('finopal-crm-reminders')
    ->withoutOverlapping();
