<?php

use App\Services\AuthDataPruner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(static fn () => app(AuthDataPruner::class)->prune())
    ->dailyAt('03:00')
    ->name('auth-data-prune')
    ->withoutOverlapping();

Schedule::command('auth:clear-resets')
    ->hourly()
    ->withoutOverlapping();
