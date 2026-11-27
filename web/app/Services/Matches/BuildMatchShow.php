<?php

namespace App\Services\Matches;

use App\Domains\Matches\LeagueTable;
use App\Domains\Matches\TeamForm;
use App\Http\Responses\Matches\ForecastPayload;
use App\Http\Responses\Matches\FormBlocksPayload;
use App\Http\Responses\Matches\MatchCenterPayload;
use App\Http\Responses\Matches\MatchHeaderPayload;
use App\Http\Responses\Matches\MatchShowResponse;
use App\Http\Responses\Matches\MiniStandingsPayload;
use App\Models\FootballMatch;
use App\Repositories\MatchCatalogRepository;

/** Страница матча: реальные поля матча из БД, форма и таблица — по сыгранным матчам, прогноз — если он есть. */
class BuildMatchShow
{
    public function __construct(private readonly MatchCatalogRepository $matches) {}

    /** Возвращает null, если матча нет. */
    public function handle(int $matchId, ?string $returnLeague): ?MatchShowResponse
    {
        $match = $this->matches->findForShow($matchId);
        if ($match === null) {
            return null;
        }

        $locale = config('matchiq.ui_locale');
        $form = new TeamForm($this->matches->finishedForTeams([$match->home_team_id, $match->away_team_id]));
        $matchCenter = MatchCenterPayload::make($match);

        $preview = $this->matches->previewFor($match->id, $locale);
        $facts = is_array($preview?->facts) ? array_values(array_filter($preview->facts, 'is_string')) : [];

        return new MatchShowResponse(
            match: MatchHeaderPayload::make($match, $form, $matchCenter !== null),
            preview: $preview?->preview_text,
            insights: $facts,
            forecast: ForecastPayload::make($match->currentPrediction),
            standings: $this->standings($match),
            formBlocks: FormBlocksPayload::make($match, $form),
            matchCenter: $matchCenter,
            returnLeague: $returnLeague,
        );
    }

    /** @return list<array<string, mixed>> */
    private function standings(FootballMatch $match): array
    {
        $finished = $this->matches->finishedInSeason($match->season_id);
        $table = LeagueTable::compute($finished);
        if ($table === []) {
            return [];
        }

        $teamIds = array_column($table, 'team_id');
        $teams = $this->matches->teamsByIds($teamIds);
        $latestForm = new TeamForm($finished);

        $formByTeam = [];
        foreach ($teamIds as $teamId) {
            $formByTeam[$teamId] = array_map(fn ($r) => $r->value, $latestForm->results($teamId));
        }

        return MiniStandingsPayload::make($table, $match->home_team_id, $match->away_team_id, $teams, $formByTeam);
    }
}
