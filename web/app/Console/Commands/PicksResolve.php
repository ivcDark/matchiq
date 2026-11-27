<?php

namespace App\Console\Commands;

use App\Enums\MatchStatus;
use App\Models\FootballMatch;
use App\Services\Picks\ResolveMatchPicks;
use Illuminate\Console\Command;

class PicksResolve extends Command
{
    protected $signature = 'picks:resolve {--match= : Только один матч (id)}';

    protected $description = 'Резолв выбранных событий после матча: hit/miss и очки рейтинга';

    public function handle(ResolveMatchPicks $resolve): int
    {
        $matches = FootballMatch::query()
            ->when($this->option('match'), fn ($q, $id) => $q->whereKey($id), fn ($q) => $q
                ->whereIn('status', [MatchStatus::Finished, MatchStatus::Cancelled, MatchStatus::Abandoned])
                ->where(fn ($q) => $q
                    ->whereHas('picks', fn ($p) => $p->active()->pending())
                    ->orWhere(fn ($q) => $q->where('status', MatchStatus::Finished)->where('kickoff_at', '>', now()->subDays(30))->whereHas('predictions.outcomes', fn ($o) => $o->whereNull('is_hit')))))
            ->get();

        $resolved = 0;
        foreach ($matches as $match) {
            $resolved += $resolve->handle($match)['resolved'];
        }

        $this->info("Матчей обработано: {$matches->count()}, picks закрыто: {$resolved}");

        return self::SUCCESS;
    }
}
