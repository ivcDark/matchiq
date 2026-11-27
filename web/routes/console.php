<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('predictions:generate --days=7')
    ->everyThirtyMinutes()->timezone(config('app.timezone'))->withoutOverlapping(25)
    ->description('Эвристические вероятности для предстоящих матчей');

Schedule::command('picks:resolve')
    ->everyFiveMinutes()->timezone(config('app.timezone'))->withoutOverlapping(10)
    ->description('Резолв picks и начисление очков по завершённым матчам');
