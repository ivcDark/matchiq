package jobs

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

// Завершённый матч: хозяева 6811 (flash TeHome01), гости 2012 (flashId у SStats пуст). Счёт 2:1.
const gameFinishedJSON = `{
  "game": {
    "id": 1577460, "flashId": "Mat00001", "status": 8, "statusName": "Finished", "elapsed": 90, "extraMinutes": 3,
    "homeResult": 2, "awayResult": 1, "homeHTResult": 0, "awayHTResult": 0,
    "homeTeam": {"id": 6811, "name": "Leningradets", "flashId": "TeHome01"},
    "awayTeam": {"id": 2012, "name": "FC Sochi", "flashId": null}
  },
  "statistics": {
    "shotsOnGoalHome": 5, "shotsOnGoalAway": 3, "totalShotsHome": 21, "totalShotsAway": 8, "cornerKicksHome": 6, "cornerKicksAway": 3,
    "foulsHome": 14, "foulsAway": 12, "offsidesHome": 1, "offsidesAway": 3, "yellowCardsHome": 2, "yellowCardsAway": 3,
    "redCardsHome": 0, "redCardsAway": 1, "ballPossessionHome": 56, "ballPossessionAway": 44, "goalkeeperSavesHome": 2, "goalkeeperSavesAway": 5,
    "totalPassesHome": 400, "totalPassesAway": 200, "passesAccurateHome": 300, "passesAccurateAway": 100,
    "expectedGoalsHome": 1.78, "expectedGoalsAway": 0.58, "crossesHome": 35, "crossesAway": 9, "headedGoalsHome": null, "headedGoalsAway": null
  },
  "lineups": {"homeFormation": "4-3-3", "awayFormation": null, "homeCoach": {"id": 24584, "name": "Jonatan Alba"}, "awayCoach": {"id": 0, "name": ""}},
  "lineupPlayers": [
    {"teamId": 6811, "playerId": 829, "playerName": "Rodrigo Becão", "number": 50, "position": "G", "grid": "1:1", "startXI": true},
    {"teamId": 6811, "playerId": 1031, "playerName": "Igor Silva", "number": 2, "position": "D", "grid": "2:4", "startXI": true},
    {"teamId": 6811, "playerId": 342299, "playerName": "N. Bozov", "number": 7, "position": "M", "grid": null, "startXI": true},
    {"teamId": 6811, "playerId": 478098, "playerName": "V. Churilov", "number": 19, "position": "F", "grid": null, "startXI": false},
    {"teamId": 2012, "playerId": null, "playerName": "Ilya Dronov", "number": 68, "position": "M", "grid": null, "startXI": false},
    {"teamId": 999, "playerId": 5, "playerName": "Stranger", "number": 1, "position": "M", "grid": null, "startXI": true}
  ],
  "playerStats": [
    {"playerId": 829, "minutes": 90, "capitan": true, "goalsTotal": 0, "goalsAssists": 0, "penaltyScored": 0, "shotsTotal": 0, "shotsOn": 0, "cardsYellow": 0, "cardsRed": 0, "rating": 6.79, "passesTotal": 66, "duelsWon": 5, "substitute": false},
    {"playerId": 1031, "minutes": 90, "capitan": false, "goalsTotal": 1, "goalsAssists": 1, "penaltyScored": 1, "shotsTotal": 3, "shotsOn": 2, "cardsYellow": 1, "cardsRed": 0, "rating": 8.1, "passesTotal": 43, "penaltySaved": null},
    {"playerId": 777777, "minutes": 10, "rating": 9.9}
  ],
  "events": [
    {"id": 105, "teamId": 2012, "elapsed": 85, "extra": null, "type": 1, "name": "Normal Goal", "player": {"id": 50, "name": "A. Gost"}, "assistPlayer": null},
    {"id": 101, "teamId": 6811, "elapsed": 81, "extra": null, "type": 1, "name": "Own Goal", "player": {"id": 53594, "name": "I. Kalinin"}, "assistPlayer": {"id": 99, "name": "X. Ignored"}},
    {"id": 102, "teamId": 6811, "elapsed": 64, "extra": null, "type": 1, "name": "Normal Goal", "player": {"id": 1031, "name": "I. Silva"}, "assistPlayer": {"id": 342299, "name": "N. Bozov"}},
    {"id": 103, "teamId": 6811, "elapsed": 46, "extra": null, "type": 3, "name": "Substitution 1", "player": {"id": 342299, "name": "N. Bozov"}, "assistPlayer": {"id": 478098, "name": "V. Churilov"}},
    {"id": 104, "teamId": 2012, "elapsed": 34, "extra": null, "type": 2, "name": "Yellow Card", "player": {"id": null, "name": null}, "assistPlayer": null},
    {"id": 110, "teamId": 6811, "elapsed": 40, "extra": null, "type": 4, "name": "Goal confirmed", "player": {"id": null, "name": null}, "assistPlayer": null},
    {"id": 111, "teamId": 6811, "elapsed": 90, "extra": 2, "type": 2, "name": "Red Card", "player": {"id": 829, "name": "R. Becao"}, "assistPlayer": null},
    {"id": 112, "teamId": 6811, "elapsed": 30, "extra": null, "type": 1, "name": "Missed Penalty", "player": {"id": 1031, "name": "I. Silva"}, "assistPlayer": {"id": 1031, "name": "I. Silva"}},
    {"id": 113, "teamId": 6811, "elapsed": 20, "extra": null, "type": 7, "name": "Mystery", "player": null, "assistPlayer": null},
    {"id": 114, "teamId": 12345, "elapsed": 21, "extra": null, "type": 2, "name": "Yellow Card", "player": null, "assistPlayer": null}
  ]
}`

// Идущий матч: событий нет, статистика и составы пусты — это не ошибка.
const gameLiveEmptyJSON = `{
  "game": {
    "id": 1577610, "flashId": "Live0001", "status": 3, "statusName": "First Half", "elapsed": 42,
    "homeResult": 1, "awayResult": 0, "homeHTResult": null, "awayHTResult": null,
    "homeTeam": {"id": 3985, "flashId": "TeHome01"}, "awayTeam": {"id": 1087, "flashId": "TeAway01"}
  },
  "statistics": {"shotsOnGoalHome": null, "shotsOnGoalAway": null, "totalShotsHome": null, "totalShotsAway": null},
  "lineups": {"homeFormation": null, "awayFormation": null, "homeCoach": {"id": null, "name": null}, "awayCoach": {"id": null, "name": null}},
  "lineupPlayers": [], "playerStats": null, "events": null
}`

const gameLiveGoalJSON = `{
  "game": {
    "id": 1577610, "flashId": "Live0001", "status": 3, "statusName": "First Half", "elapsed": 28,
    "homeResult": 1, "awayResult": 0,
    "homeTeam": {"id": 3985, "flashId": "TeHome01"}, "awayTeam": {"id": 1087, "flashId": "TeAway01"}
  },
  "events": [
    {"id": 8836955, "teamId": 3985, "elapsed": 28, "extra": null, "type": 1, "name": "Normal Goal", "player": {"id": null, "name": null}, "assistPlayer": null}
  ]
}`

func mustGame(t *testing.T, raw string) sstats.GameFull {
	t.Helper()
	var g sstats.GameFull
	if err := json.Unmarshal([]byte(raw), &g); err != nil {
		t.Fatal(err)
	}

	return g
}

func detailsSource(t *testing.T) fakeSource {
	t.Helper()

	return fakeSource{game: map[string]sstats.GameFull{
		"1577460":  mustGame(t, gameFinishedJSON),
		"Mat00001": mustGame(t, gameFinishedJSON),
		"Live0001": mustGame(t, gameLiveEmptyJSON),
	}}
}

func collectDetails(t *testing.T, src fakeSource, payload dto.MatchDetailsPayload) (*dto.Batch, dto.Done) {
	t.Helper()
	msgs := runCommand(t, src, dto.JobSyncMatchDetails, payload, 200)
	batch, ok := msgs[0].(*dto.Batch)
	if !ok {
		t.Fatalf("expected batch, got %#v", msgs[0])
	}

	return batch, msgs[len(msgs)-1].(dto.Done)
}

func detailsOf(t *testing.T, b *dto.Batch, id string) dto.MatchDetailsData {
	t.Helper()
	for _, e := range b.Entities {
		if e.EntityType == dto.EntityMatchDetails && e.ExternalID == id {
			return e.Data.(dto.MatchDetailsData)
		}
	}
	t.Fatalf("no match_details for %s in %+v", id, b.Entities)

	return dto.MatchDetailsData{}
}

func TestSyncMatchDetailsUsesGamesEndpointWithNumericID(t *testing.T) {
	var got []string
	src := detailsSource(t)
	src.gotGameIDs = &got

	_, _ = collectDetails(t, src, dto.MatchDetailsPayload{
		MatchExternalIDs: []string{"Mat00001", "Live0001"},
		MatchAPIIDs:      map[string]string{"Mat00001": "1577460"},
	})

	if len(got) != 2 || got[0] != "1577460" || got[1] != "Live0001" {
		t.Fatalf("requested ids = %v, want numeric id and flash fallback", got)
	}
}

func TestSyncMatchDetailsNormalizesGame(t *testing.T) {
	batch, done := collectDetails(t, detailsSource(t), dto.MatchDetailsPayload{MatchExternalIDs: []string{"Mat00001"}})
	d := detailsOf(t, batch, "Mat00001")

	if d.PeopleSource != dto.PeopleSStatsAPI {
		t.Fatalf("people source = %q", d.PeopleSource)
	}
	if len(batch.Entities) != 1 || done.EntitiesTotal != 1 {
		t.Fatalf("only match_details expected (people are resolved by Laravel): %+v", batch.Entities)
	}

	s := d.State
	if s == nil || s.Status != dto.MatchFinished || s.Minute != nil || s.HomeGoals == nil || *s.HomeGoals != 2 || *s.AwayGoals != 1 || *s.HomeGoalsHT != 0 {
		t.Fatalf("state = %+v", s)
	}

	// Событие 113 (неизвестный тип), 114 (чужая команда) и участник чужой команды дают ошибки, VAR (тип 4) пропущен.
	if len(batch.Errors) != 3 {
		t.Fatalf("errors = %+v", batch.Errors)
	}
	for _, e := range batch.Errors {
		if e.EntityType != dto.EntityMatchDetails || e.ExternalID != "Mat00001" || e.Phase != dto.PhaseNormalize {
			t.Fatalf("error = %+v", e)
		}
	}

	var types []string
	for _, e := range d.Events {
		types = append(types, e.Type)
	}
	// По времени: 30' промах, 34' жёлтая, 46' замена, 64' гол, 81' автогол, 85' гол, 90+2' красная.
	want := []string{"penalty_missed", "yellow", "substitution", "goal", "own_goal", "goal", "red"}
	if strings.Join(types, ",") != strings.Join(want, ",") {
		t.Fatalf("event types = %v, want %v", types, want)
	}

	missed, yellow, sub, goal, own, away, red := d.Events[0], d.Events[1], d.Events[2], d.Events[3], d.Events[4], d.Events[5], d.Events[6]
	if missed.RelatedPlayerExternalID != "" || missed.PlayerExternalID != "1031" || missed.Side != dto.SideHome || missed.TeamExternalID != "TeHome01" {
		t.Fatalf("missed penalty = %+v", missed)
	}
	if yellow.PlayerExternalID != "" || yellow.PlayerName != "" || yellow.Side != dto.SideAway || yellow.TeamExternalID != "" {
		t.Fatalf("yellow card without player and flash id = %+v", yellow)
	}
	if sub.PlayerExternalID != "342299" || sub.RelatedPlayerExternalID != "478098" || sub.RelatedPlayerName != "V. Churilov" || sub.Minute != 46 {
		t.Fatalf("substitution (player out, assist = player in) = %+v", sub)
	}
	if goal.PlayerExternalID != "1031" || goal.RelatedPlayerExternalID != "342299" {
		t.Fatalf("goal with assist = %+v", goal)
	}
	if own.RelatedPlayerExternalID != "" || own.Side != dto.SideHome {
		t.Fatalf("own goal is credited to the other team, no assist: %+v", own)
	}
	if red.Minute != 90 || red.AddedMinute == nil || *red.AddedMinute != 2 {
		t.Fatalf("red card minute = %+v", red)
	}
	if away.ScoreHome == nil || *away.ScoreHome != 2 || *away.ScoreAway != 1 || goal.ScoreHome == nil || *goal.ScoreHome != 1 || *goal.ScoreAway != 0 {
		t.Fatalf("running score: goal=%+v away=%+v", goal, away)
	}
	for i, e := range d.Events {
		if e.SortOrder != i {
			t.Fatalf("sort_order must follow time: %d = %d", i, e.SortOrder)
		}
	}
	if sub.Details["source_event_id"] != "103" {
		t.Fatalf("details = %+v", sub.Details)
	}

	if len(d.Lineup) != 5 {
		t.Fatalf("lineup (player of foreign team skipped) = %+v", d.Lineup)
	}
	gk, def, bozov, churilov, dronov := d.Lineup[0], d.Lineup[1], d.Lineup[2], d.Lineup[3], d.Lineup[4]
	if gk.PlayerExternalID != "829" || !gk.IsStarter || !gk.IsCaptain || gk.Position == nil || *gk.Position != "GK" || gk.Line == nil || *gk.Line != 0 || *gk.ShirtNumber != 50 {
		t.Fatalf("goalkeeper = %+v", gk)
	}
	if def.Line == nil || *def.Line != 1 || *def.Position != "DF" || def.IsCaptain {
		t.Fatalf("defender = %+v", def)
	}
	if bozov.MinuteOut == nil || *bozov.MinuteOut != 46 || bozov.Line != nil {
		t.Fatalf("substituted starter = %+v", bozov)
	}
	if churilov.IsStarter || churilov.MinuteIn == nil || *churilov.MinuteIn != 46 || *churilov.Position != "FW" {
		t.Fatalf("substitute who came on = %+v", churilov)
	}
	if dronov.PlayerExternalID != "" || dronov.PlayerName != "Ilya Dronov" || dronov.Side != dto.SideAway {
		t.Fatalf("player without numeric id keeps the name = %+v", dronov)
	}

	if len(d.PlayerStats) != 2 {
		t.Fatalf("player stats (player outside lineup skipped) = %+v", d.PlayerStats)
	}
	if ps := d.PlayerStats[1]; ps.PlayerExternalID != "1031" || *ps.Goals != 1 || *ps.Assists != 1 || *ps.PenaltiesScored != 1 || *ps.ShotsOnTarget != 2 || !ps.IsMOTM || ps.PlayerName != "Igor Silva" {
		t.Fatalf("player stats = %+v", ps)
	}
	if ps := d.PlayerStats[0]; ps.IsMOTM || ps.Extra["passes_total"] != 66 || ps.Extra["duels_won"] != 5 || ps.Extra["penalty_saved"] != nil {
		t.Fatalf("player stats extra = %+v", ps)
	}

	if len(d.TeamStats) != 2 {
		t.Fatalf("team stats = %+v", d.TeamStats)
	}
	home, away2 := d.TeamStats[0], d.TeamStats[1]
	if home.Period != dto.StatsFullTime || home.Side != dto.SideHome || *home.Shots != 21 || *home.ShotsOnTarget != 5 || *home.Corners != 6 || *home.PossessionPct != 56 || *home.Passes != 400 {
		t.Fatalf("home stats = %+v", home)
	}
	if *home.XG != 1.78 || *home.XGA != 0.58 || *away2.XG != 0.58 || *away2.RedCards != 1 || *home.PassAccuracyPct != 75 || *away2.PassAccuracyPct != 50 {
		t.Fatalf("xg / accuracy: home=%+v away=%+v", home, away2)
	}
	if home.Extra["crosses"] != 35 || home.Extra["completed_passes"] != 300 {
		t.Fatalf("home extra = %+v", home.Extra)
	}
	if _, has := home.Extra["headed_goals"]; has {
		t.Fatalf("null metrics must not reach extra: %+v", home.Extra)
	}

	if len(d.Teams) != 2 || d.Teams[0].Formation != "4-3-3" || d.Teams[0].TeamExternalID != "TeHome01" || d.Teams[1].Side != dto.SideAway || d.Teams[1].Formation != "" {
		t.Fatalf("teams = %+v", d.Teams)
	}
}

func TestSyncMatchDetailsEmptyLiveResponseIsNotAnError(t *testing.T) {
	batch, _ := collectDetails(t, detailsSource(t), dto.MatchDetailsPayload{MatchExternalIDs: []string{"Live0001"}})
	d := detailsOf(t, batch, "Live0001")

	if len(batch.Errors) != 0 {
		t.Fatalf("errors = %+v", batch.Errors)
	}
	if len(d.Events)+len(d.Lineup)+len(d.TeamStats)+len(d.PlayerStats)+len(d.Teams) != 0 {
		t.Fatalf("nothing to load, got %+v", d)
	}
	s := d.State
	if s == nil || s.Status != dto.MatchLive || s.Period == nil || *s.Period != dto.PeriodFirstHalf || s.Minute == nil || *s.Minute != 42 || *s.HomeGoals != 1 || *s.AwayGoals != 0 {
		t.Fatalf("state = %+v", s)
	}
}

func TestSyncMatchDetailsGoalWithoutPlayer(t *testing.T) {
	src := fakeSource{game: map[string]sstats.GameFull{"Live0001": mustGame(t, gameLiveGoalJSON)}}
	batch, _ := collectDetails(t, src, dto.MatchDetailsPayload{MatchExternalIDs: []string{"Live0001"}})
	d := detailsOf(t, batch, "Live0001")

	if len(batch.Errors) != 0 || len(d.Events) != 1 {
		t.Fatalf("errors = %+v events = %+v", batch.Errors, d.Events)
	}
	e := d.Events[0]
	if e.Type != dto.EventGoal || e.PlayerExternalID != "" || e.PlayerName != "" || e.Minute != 28 || e.TeamExternalID != "TeHome01" {
		t.Fatalf("goal without player = %+v", e)
	}
	if e.ScoreHome == nil || *e.ScoreHome != 1 || *e.ScoreAway != 0 {
		t.Fatalf("score after goal = %+v", e)
	}
}

func TestGameScoreDroppedWhenRunningScoreDiffers(t *testing.T) {
	g := mustGame(t, gameFinishedJSON)
	two := sstats.FlexInt(3)
	g.Game.HomeResult = &two

	d, _ := normalizeGame("Mat00001", g)
	for _, e := range d.Events {
		if e.ScoreHome != nil || e.ScoreAway != nil {
			t.Fatalf("score must be dropped when it does not add up to the result: %+v", e)
		}
	}
}

func TestGameStatusNames(t *testing.T) {
	cases := map[string]struct {
		status string
		period string
	}{
		"Not Started":               {dto.MatchScheduled, ""},
		"First Half":                {dto.MatchLive, dto.PeriodFirstHalf},
		"Halftime":                  {dto.MatchLive, dto.PeriodHalfTime},
		"Second Half":               {dto.MatchLive, dto.PeriodSecondHalf},
		"Extra Time":                {dto.MatchLive, dto.PeriodExtraTime},
		"Penalty Shootout":          {dto.MatchLive, dto.PeriodPenalties},
		"Finished":                  {dto.MatchFinished, ""},
		"Finished After Extra Time": {dto.MatchFinished, ""},
		"Finished After Penalty":    {dto.MatchFinished, ""},
		"Technical Loss":            {dto.MatchFinished, ""},
		"Postponed":                 {dto.MatchPostponed, ""},
		"Cancelled":                 {dto.MatchCancelled, ""},
		"Abandoned":                 {dto.MatchAbandoned, ""},
		"Something New":             {"", ""},
	}
	for name, want := range cases {
		status, period := gameStatus(name)
		got := ""
		if period != nil {
			got = *period
		}
		if status != want.status || got != want.period {
			t.Fatalf("%q = %q/%q, want %q/%q", name, status, got, want.status, want.period)
		}
	}
}

func TestSyncMatchDetailsFetchErrorsDoNotStopRun(t *testing.T) {
	src := detailsSource(t)
	src.detailsErr = map[string]error{"Gone0001": &sstats.APIError{Message: "match not found"}}
	batch, done := collectDetails(t, src, dto.MatchDetailsPayload{MatchExternalIDs: []string{"Gone0001", "Mat00001", "Mat00001", " "}})

	detailsOf(t, batch, "Mat00001")
	var fetch *dto.EntityError
	for i, e := range batch.Errors {
		if e.Phase == dto.PhaseFetch {
			fetch = &batch.Errors[i]
		}
	}
	if fetch == nil || fetch.ExternalID != "Gone0001" || fetch.EntityType != dto.EntityMatchDetails || !strings.Contains(fetch.Message, "match not found") {
		t.Fatalf("errors = %+v", batch.Errors)
	}
	if done.ErrorsTotal != len(batch.Errors) {
		t.Fatalf("done = %+v", done)
	}
}

func TestSyncMatchDetailsRejectsForeignMatch(t *testing.T) {
	src := fakeSource{game: map[string]sstats.GameFull{"Other001": mustGame(t, gameFinishedJSON)}}
	batch, _ := collectDetails(t, src, dto.MatchDetailsPayload{MatchExternalIDs: []string{"Other001"}})

	if len(batch.Entities) != 0 || len(batch.Errors) != 1 || batch.Errors[0].Phase != dto.PhaseNormalize {
		t.Fatalf("batch = %+v", batch)
	}
}

func TestSyncMatchDetailsRejectsBadPayload(t *testing.T) {
	for name, payload := range map[string]dto.MatchDetailsPayload{
		"empty":    {},
		"blank":    {MatchExternalIDs: []string{" ", ""}},
		"too many": {MatchExternalIDs: manyIDs(maxMatchDetailsPerRun + 1)},
	} {
		msgs := runCommand(t, fakeSource{}, dto.JobSyncMatchDetails, payload, 200)
		if f, ok := msgs[0].(dto.Failed); !ok || f.Retryable {
			t.Fatalf("%s: expected non-retryable failed, got %#v", name, msgs[0])
		}
	}
}

func manyIDs(n int) []string {
	ids := make([]string, n)
	for i := range ids {
		ids[i] = "M" + strings.Repeat("0", 6) + string(rune('a'+i%26)) + string(rune('a'+(i/26)%26)) + string(rune('a'+(i/676)%26))
	}

	return ids
}

func TestSyncMatchDetailsFatalErrorsFailRun(t *testing.T) {
	for name, err := range map[string]error{
		"unauthorized": sstats.ErrUnauthorized,
		"rate limit":   &sstats.StatusError{Code: 429, Message: "too many requests"},
	} {
		src := detailsSource(t)
		src.detailsErr = map[string]error{"Mat00001": err}
		msgs := runCommand(t, src, dto.JobSyncMatchDetails, dto.MatchDetailsPayload{MatchExternalIDs: []string{"Mat00001"}}, 200)

		f, ok := msgs[0].(dto.Failed)
		if !ok {
			t.Fatalf("%s: expected failed, got %#v", name, msgs[0])
		}
		if name == "rate limit" && !f.Retryable {
			t.Fatalf("rate limit must be retryable")
		}
		if name == "unauthorized" && f.Retryable {
			t.Fatalf("unauthorized must not be retryable")
		}
	}
}

func TestSnakeCase(t *testing.T) {
	cases := map[string]string{
		"touchesInOppositionBox": "touches_in_opposition_box",
		"xgOnTarget":             "xg_on_target",
		"xgotFaced":              "xgot_faced",
		"expectedGoalsOnTarget":  "expected_goals_on_target",
		"saves":                  "saves",
		"isBestInTeam":           "is_best_in_team",
	}
	for in, want := range cases {
		if got := snakeCase(in); got != want {
			t.Fatalf("snakeCase(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestRunnerSplitsHeavyBatchesBySize(t *testing.T) {
	src := detailsSource(t)

	var ids []string
	for i := 0; i < 3; i++ {
		id := "Big0000" + string(rune('1'+i))
		g := mustGame(t, gameFinishedJSON)
		g.Game.FlashID = id
		g.Events[0].Name = "Normal Goal " + strings.Repeat("x", 400_000)
		src.game[id] = g
		ids = append(ids, id)
	}

	msgs := runCommand(t, src, dto.JobSyncMatchDetails, dto.MatchDetailsPayload{MatchExternalIDs: ids}, 1000)

	batches := 0
	entities := 0
	for _, m := range msgs {
		if b, ok := m.(*dto.Batch); ok {
			batches++
			entities += len(b.Entities)
		}
	}
	if batches < 2 {
		t.Fatalf("heavy payload must be split by size, got %d batch(es)", batches)
	}
	if done := msgs[len(msgs)-1].(dto.Done); done.EntitiesTotal != entities || done.BatchesTotal != batches {
		t.Fatalf("done = %+v, entities=%d batches=%d", done, entities, batches)
	}
}
