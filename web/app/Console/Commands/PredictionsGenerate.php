<?php

namespace App\Console\Commands;

use App\Services\Predictions\GenerateMatchPredictions;
use Illuminate\Console\Command;

class PredictionsGenerate extends Command
{
    protected $signature = 'predictions:generate {--days=7 : Окно предстоящих матчей, дней} {--force : Пересчитать, даже если прогноз уже есть}';

    protected $description = 'Эвристические вероятности событий для предстоящих матчей (снимок прогноза)';

    public function handle(GenerateMatchPredictions $generate): int
    {
        $created = $generate->handleUpcoming((int) $this->option('days'), (bool) $this->option('force'));
        $this->info("Прогнозов создано: {$created}");

        return self::SUCCESS;
    }
}
