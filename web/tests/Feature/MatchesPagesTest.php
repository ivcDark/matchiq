<?php

namespace Tests\Feature;

use App\Enums\CompetitionStatus;
use App\Enums\CompetitionType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Enums\StageType;
use App\Enums\StatsPeriod;
use App\Models\Competition;
use App\Models\Country;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\MatchPrediction;
use App\Models\MatchTeamStat;
use App\Models\ModelVersion;
use App\Models\Player;
use App\Models\Round;
use App\Models\Season;
use App\Models\Stage;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MatchesPagesTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    private Competition $competition;

    private Season $season;

    private Stage $stage;

    private Round $round;

    /** @var array<string, Team> */
    private array $teams = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-27 12:00:00');

        $this->country = Country::create(['code' => 'RU', 'name' => 'Russia', 'name_i18n' => ['ru' => 'Россия']]);
        $this->competition = $this->makeCompetition('premier-league', 'Premier League', ['ru' => 'Премьер-лига'], CompetitionStatus::Live, 10, 'RPL', ['ru' => 'РПЛ']);
        $this->season = Season::create([
            'competition_id' => $this->competition->id, 'label' => '2026-2027', 'slug' => '2026-2027', 'is_current' => true,
        ]);
        $this->stage = Stage::create([
            'season_id' => $this->season->id, 'name' => 'Main', 'type' => StageType::League, 'cycles' => 2, 'has_standings' => true,
        ]);
        $this->round = Round::create(['stage_id' => $this->stage->id, 'number' => 5, 'type' => 'matchday']);

        foreach ([['zenit', 'Zenit', 'Зенит'], ['spartak', 'Spartak Moscow', 'Спартак'], ['cska', 'CSKA Moscow', 'ЦСКА']] as [$slug, $name, $ru]) {
            $this->teams[$slug] = Team::create([
                'country_id' => $this->country->id, 'slug' => $slug, 'name' => $name, 'name_i18n' => ['ru' => $ru],
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param  array<string, string>  $nameI18n */
    private function makeCompetition(string $slug, string $name, array $nameI18n, CompetitionStatus $status, int $sort, ?string $short = null, array $shortI18n = []): Competition
    {
        return Competition::create([
            'country_id' => $this->country->id, 'slug' => $slug, 'name' => $name, 'name_i18n' => $nameI18n,
            'short_name' => $short, 'short_name_i18n' => $shortI18n,
            'type' => CompetitionType::League, 'status' => $status, 'sort_order' => $sort,
        ]);
    }

    /** @param  array<string, mixed>  $attrs */
    private function makeMatch(string $home, string $away, string $kickoff, MatchStatus $status = MatchStatus::Scheduled, ?array $score = null, array $attrs = []): FootballMatch
    {
        return FootballMatch::create($attrs + [
            'competition_id' => $this->competition->id,
            'season_id' => $this->season->id,
            'stage_id' => $this->stage->id,
            'round_id' => $this->round->id,
            'home_team_id' => $this->teams[$home]->id,
            'away_team_id' => $this->teams[$away]->id,
            'kickoff_at' => $kickoff,
            'status' => $status,
            'home_goals' => $score[0] ?? null,
            'away_goals' => $score[1] ?? null,
        ]);
    }

    public function test_index_lists_real_leagues_and_matches_for_the_day(): void
    {
        $this->makeMatch('zenit', 'spartak', '2026-11-27 16:00:00');
        $this->makeMatch('cska', 'zenit', '2026-11-27 13:00:00', MatchStatus::Finished, [2, 1]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Matches/Index')
                ->where('selectedLeagues', ['all'])
                ->where('period', 'today')
                ->where('date', '2026-11-27')
                ->has('leagues', 1)
                ->where('leagues.0.id', 'premier-league')
                ->where('leagues.0.country', 'Россия')
                ->where('leagues.0.country_code', 'RU')
                ->where('leagues.0.name', 'Премьер-лига')
                ->where('leagues.0.short', 'РПЛ')
                ->where('leagues.0.status', 'live')
                ->has('matches', 2)
                ->where('matches.0.home', 'ЦСКА')
                ->where('matches.0.away', 'Зенит')
                ->where('matches.0.status', 'finished')
                ->where('matches.0.score_home', 2)
                ->where('matches.0.score_away', 1)
                ->where('matches.0.league_id', 'premier-league')
                ->where('matches.1.home', 'Зенит')
                ->where('matches.1.away', 'Спартак')
                ->where('matches.1.score_home', null)
                ->where('matches.1.prob_home', null)
                ->where('matches.1.top_market', null)
                ->where('widgets.league.label', 'Россия · Премьер-лига')
                ->where('widgets.league.tournament_id', $this->competition->id));
    }

    public function test_home_links_to_tournament_and_clubs_resolve_to_real_pages(): void
    {
        $this->makeMatch('zenit', 'spartak', '2026-11-01 16:00:00', MatchStatus::Finished, [3, 0]);
        $this->makeMatch('zenit', 'cska', '2026-11-27 16:00:00');

        $props = $this->get('/')->viewData('page')['props'];

        $tournamentId = $props['widgets']['league']['tournament_id'];
        $this->assertNotNull($tournamentId);
        $this->get(route('tournaments.show', $tournamentId))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Tournaments/Show')->where('tournament.id', $tournamentId));

        $this->assertNotEmpty($props['widgets']['standings']);
        foreach ($props['widgets']['standings'] as $row) {
            $this->assertIsInt($row['team_id']);
            $this->get(route('clubs.show', $row['team_id']))->assertOk()->assertInertia(fn ($page) => $page
                ->component('Clubs/Show')->where('club.id', $row['team_id'])->where('club.name', $row['team']));
        }

        $index = file_get_contents(resource_path('js/Pages/Matches/Index.vue'));
        $this->assertStringContainsString("route('clubs.show', row.team_id)", $index);
        $this->assertStringContainsString("route('tournaments.show', widgets.league.tournament_id)", $index);
    }

    public function test_index_hidden_competitions_are_not_exposed(): void
    {
        $hidden = $this->makeCompetition('secret', 'Secret Cup', [], CompetitionStatus::Hidden, 0);
        $this->makeCompetition('soon-league', 'Soon League', [], CompetitionStatus::Soon, 50);
        FootballMatch::create([
            'competition_id' => $hidden->id, 'season_id' => $this->season->id, 'stage_id' => $this->stage->id,
            'home_team_id' => $this->teams['zenit']->id, 'away_team_id' => $this->teams['cska']->id,
            'kickoff_at' => '2026-11-27 15:00:00', 'status' => MatchStatus::Scheduled,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('leagues', 2)
                ->where('leagues.0.id', 'premier-league')
                ->where('leagues.1.id', 'soon-league')
                ->where('leagues.1.status', 'soon')
                ->has('matches', 0));
    }

    public function test_index_filters_by_date_and_exposes_adjacent_window(): void
    {
        $this->makeMatch('zenit', 'spartak', '2026-11-28 11:00:00');
        $this->makeMatch('cska', 'spartak', '2026-12-10 11:00:00');

        $this->get('/?date=2026-11-28')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', 'tomorrow')
                ->where('date', '2026-11-28')
                ->has('matches', 1)
                ->where('matches.0.away', 'Спартак'));

        $this->get('/?date=2026-12-10')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', null)
                ->where('date', '2026-12-10')
                ->has('matches', 1)
                ->where('matches.0.home', 'ЦСКА'));
    }

    public function test_index_period_and_invalid_filters_fall_back(): void
    {
        $this->get('/?period=yesterday')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('period', 'yesterday')->where('date', '2026-11-26'));

        $this->get('/?period=upcoming&date=garbage&league=nope,all')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', 'tomorrow')
                ->where('selectedLeagues', ['all']));
    }

    public function test_index_league_filter_accepts_csv_and_array_and_drops_soon(): void
    {
        $this->makeCompetition('soon-league', 'Soon League', [], CompetitionStatus::Soon, 50);

        foreach (['/?league=premier-league,soon-league', '/?league[]=premier-league&league[]=soon-league'] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('selectedLeagues', ['premier-league']));
        }

        $this->get('/?league=soon-league')->assertOk()
            ->assertInertia(fn ($page) => $page->where('selectedLeagues', ['all']));
    }

    public function test_index_league_filter_limits_matches(): void
    {
        $other = $this->makeCompetition('other', 'Other League', [], CompetitionStatus::Live, 20);
        $this->makeMatch('zenit', 'spartak', '2026-11-27 16:00:00');
        FootballMatch::create([
            'competition_id' => $other->id, 'season_id' => $this->season->id, 'stage_id' => $this->stage->id,
            'home_team_id' => $this->teams['cska']->id, 'away_team_id' => $this->teams['spartak']->id,
            'kickoff_at' => '2026-11-27 17:00:00', 'status' => MatchStatus::Scheduled,
        ]);

        $this->get('/?league=other')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('selectedLeagues', ['other'])
                ->has('matches', 1)
                ->where('matches.0.league_id', 'other')
                ->where('widgets.league.id', 'other'));

        $this->get('/')->assertInertia(fn ($page) => $page->has('matches', 2));
    }

    public function test_index_form_and_table_widget_come_from_played_matches(): void
    {
        $this->makeMatch('zenit', 'spartak', '2026-11-01 16:00:00', MatchStatus::Finished, [3, 0]);
        $this->makeMatch('cska', 'zenit', '2026-11-08 16:00:00', MatchStatus::Finished, [1, 1]);
        $this->makeMatch('zenit', 'cska', '2026-11-27 16:00:00');

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('matches', 1)
                ->where('matches.0.form_home', ['В', 'Н'])
                ->where('matches.0.form_away', ['Н'])
                ->has('widgets.standings', 3)
                ->where('widgets.standings.0.team', 'Зенит')
                ->where('widgets.standings.0.team_id', $this->teams['zenit']->id)
                ->where('widgets.standings.2.team_id', $this->teams['spartak']->id)
                ->where('widgets.standings.0.pts', 4)
                ->where('widgets.standings.0.played', 2)
                ->where('widgets.standings.0.gd', 3)
                ->where('widgets.standings.2.team', 'Спартак')
                ->where('widgets.scorers', [])
                ->where('widgets.hotScorers', [])
                ->where('widgets.news', []));
    }

    public function test_index_scorers_widget_counts_goals_from_events(): void
    {
        $match = $this->makeMatch('zenit', 'spartak', '2026-11-01 16:00:00', MatchStatus::Finished, [2, 0]);
        $player = Player::create(['country_id' => $this->country->id, 'slug' => 'glushenkov', 'name' => 'M. Glushenkov', 'name_i18n' => ['ru' => 'М. Глушенков']]);
        foreach ([MatchEventType::Goal, MatchEventType::PenaltyGoal, MatchEventType::OwnGoal] as $i => $type) {
            MatchEvent::create([
                'match_id' => $match->id, 'team_id' => $this->teams['zenit']->id, 'player_id' => $player->id,
                'type' => $type, 'minute' => 10 + $i, 'sort_order' => $i,
            ]);
        }

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('widgets.scorers', 1)
                ->where('widgets.scorers.0.player', 'М. Глушенков')
                ->where('widgets.scorers.0.team', 'Зенит')
                ->where('widgets.scorers.0.goals', 2));
    }

    public function test_index_without_active_leagues_returns_empty_lists(): void
    {
        $this->competition->update(['status' => CompetitionStatus::Hidden]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('leagues', 0)
                ->has('matches', 0)
                ->where('widgets.league', null)
                ->where('widgets.standings', []));
    }

    public function test_show_returns_404_for_unknown_match(): void
    {
        $this->get('/matches/999')->assertNotFound();
    }

    public function test_show_scheduled_match_has_real_header_and_explicit_empty_blocks(): void
    {
        $this->makeMatch('zenit', 'spartak', '2026-11-01 16:00:00', MatchStatus::Finished, [3, 0]);
        $match = $this->makeMatch('zenit', 'spartak', '2026-11-28 16:00:00');

        $this->get('/matches/'.$match->id.'?league=premier-league')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Matches/Show')
                ->where('match.id', $match->id)
                ->where('match.home', 'Зенит')
                ->where('match.away', 'Спартак')
                ->where('match.league', 'Россия · Премьер-лига')
                ->where('match.league_short', 'РПЛ')
                ->where('match.status', 'scheduled')
                ->where('match.round', '5 тур')
                ->where('match.venue', null)
                ->where('match.referee', null)
                ->where('match.weather', null)
                ->where('match.home_club_id', $this->teams['zenit']->id)
                ->where('match.away_club_id', $this->teams['spartak']->id)
                ->where('match.form_home', ['В'])
                ->where('match.form_away', ['П'])
                ->where('returnLeague', 'premier-league')
                ->where('forecast', null)
                ->where('preview', null)
                ->where('matchCenter', null)
                ->where('markets', [])
                ->where('comments', [])
                ->where('refereeStats', null)
                ->where('comparison.match', [])
                ->where('formBlocks.0.id', 'h2h')
                ->has('formBlocks', 5)
                ->has('formBlocks.0.matches', 1)
                ->where('formBlocks.0.matches.0.result', 'В')
                ->where('formBlocks.0.matches.0.home', 'Зенит')
                ->where('formBlocks.0.matches.0.competition', 'РПЛ')
                ->has('standings', 2)
                ->where('standings.0.team', 'Зенит')
                ->where('standings.0.highlight', true)
                ->where('standings.0.pts', 3));
    }

    public function test_show_exposes_venue_referee_weather_and_forecast_from_db(): void
    {
        $version = ModelVersion::create(['code' => 'poisson_v1', 'name' => 'Poisson', 'version' => '1']);
        $match = $this->makeMatch('zenit', 'spartak', '2026-11-28 16:00:00', attrs: [
            'temperature_c' => -3.5, 'wind_speed_ms' => 4.0, 'weather_condition' => 'снег',
        ]);
        $prediction = MatchPrediction::create([
            'match_id' => $match->id, 'model_version_id' => $version->id, 'is_current' => true,
            'p_home' => 0.5, 'p_draw' => 0.3, 'p_away' => 0.2, 'generated_at' => now(),
        ]);
        $prediction->scorelines()->createMany([
            ['home_goals' => 1, 'away_goals' => 0, 'probability' => 0.12],
            ['home_goals' => 2, 'away_goals' => 1, 'probability' => 0.09],
        ]);

        $this->get('/matches/'.$match->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('match.weather.condition', 'снег')
                ->where('match.weather.temp_c', -3.5)
                ->where('forecast.home_pct', 50)
                ->where('forecast.draw_pct', 30)
                ->where('forecast.away_pct', 20)
                ->where('forecast.score_home', 1)
                ->where('forecast.score_away', 0)
                ->where('forecast.correct_scores.0.score', '1∶0')
                ->where('forecast.correct_scores.0.pct', 12));

        $this->get('/?date=2026-11-28')->assertInertia(fn ($page) => $page
            ->where('matches.0.prob_home', 50)
            ->where('matches.0.prob_draw', 30)
            ->where('matches.0.prob_away', 20));
    }

    public function test_show_finished_match_without_details_has_no_match_center(): void
    {
        $match = $this->makeMatch('zenit', 'spartak', '2026-11-20 16:00:00', MatchStatus::Finished, [1, 0]);

        $this->get('/matches/'.$match->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('match.status', 'finished')
                ->where('match.score_home', 1)
                ->where('match.details_loaded', false)
                ->where('matchCenter', null));
    }

    public function test_show_finished_match_exposes_events_and_stats_from_db(): void
    {
        $match = $this->makeMatch('zenit', 'spartak', '2026-11-20 16:00:00', MatchStatus::Finished, [1, 0]);
        $scorer = Player::create(['country_id' => $this->country->id, 'slug' => 'a', 'name' => 'A. Scorer']);
        $assist = Player::create(['country_id' => $this->country->id, 'slug' => 'b', 'name' => 'B. Assist']);

        MatchEvent::create([
            'match_id' => $match->id, 'team_id' => $this->teams['zenit']->id, 'player_id' => $scorer->id,
            'related_player_id' => $assist->id, 'type' => MatchEventType::Goal, 'minute' => 33,
            'score_home' => 1, 'score_away' => 0, 'sort_order' => 1,
        ]);
        MatchEvent::create([
            'match_id' => $match->id, 'team_id' => $this->teams['spartak']->id, 'player_id' => $assist->id,
            'type' => MatchEventType::SecondYellow, 'minute' => 80, 'sort_order' => 2,
        ]);
        MatchEvent::create([
            'match_id' => $match->id, 'team_id' => $this->teams['spartak']->id, 'player_id' => $assist->id,
            'type' => MatchEventType::Var, 'minute' => 81, 'sort_order' => 3,
        ]);
        MatchEvent::create([
            'match_id' => $match->id, 'team_id' => $this->teams['zenit']->id, 'player_id' => $scorer->id,
            'related_player_id' => $assist->id, 'type' => MatchEventType::Substitution, 'minute' => 70, 'sort_order' => 4,
        ]);
        foreach (['zenit' => [55.0, 12, null], 'spartak' => [45.0, 7, null]] as $slug => [$possession, $shots, $xg]) {
            MatchTeamStat::create([
                'match_id' => $match->id, 'team_id' => $this->teams[$slug]->id, 'period' => StatsPeriod::FullTime,
                'possession_pct' => $possession, 'shots' => $shots, 'xg' => $xg,
            ]);
        }

        $this->get('/matches/'.$match->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('match.details_loaded', true)
                ->where('matchCenter.minute', null)
                ->where('matchCenter.lineups', null)
                ->where('matchCenter.best_players', null)
                ->has('matchCenter.events', 3)
                ->where('matchCenter.events.0.type', 'goal')
                ->where('matchCenter.events.0.side', 'home')
                ->where('matchCenter.events.0.player', 'A. Scorer')
                ->where('matchCenter.events.0.assist', 'B. Assist')
                ->where('matchCenter.events.0.score_home', 1)
                ->where('matchCenter.events.1.type', 'red')
                ->where('matchCenter.events.1.side', 'away')
                ->where('matchCenter.events.2.type', 'sub')
                ->where('matchCenter.events.2.player_out', 'A. Scorer')
                ->where('matchCenter.events.2.player_in', 'B. Assist')
                ->where('matchCenter.stats.first_half', null)
                ->where('matchCenter.stats.second_half', null)
                ->where('matchCenter.stats.match.0.id', 'attack')
                ->where('matchCenter.stats.match.0.rows.0.id', 'shots')
                ->where('matchCenter.stats.match.0.rows.0.home', 12)
                ->where('matchCenter.stats.match.0.rows.0.away', 7)
                ->where('matchCenter.stats.match.1.id', 'control')
                ->where('matchCenter.stats.match.1.rows.0.fmt', 'pct')
                ->where('matchCenter.stats.match.1.rows.0.home', 55));
    }
}
