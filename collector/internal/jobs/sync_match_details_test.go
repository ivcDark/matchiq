package jobs

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

const gameInfoJSON = `{
  "game": {"id": "Mat00001", "homeTeamId": "TeHome01", "awayTeamId": "TeAway01", "status": 3},
  "events": [
    {"teamId": "TeAway01", "sourceEventId": "ev1", "period": 1, "sortOrder": 0, "homeScoreAfter": 0, "awayScoreAfter": 1, "time": 1, "minute": 1, "injuryTime": 0, "team": 2,
     "actions": [{"playerUniqueId": "PlAway01", "actionType": 3, "actionName": "Goal", "playerName": "Offor C."}, {"playerUniqueId": "PlAway02", "actionType": 8, "actionName": "Assistance", "playerName": "Gassama N."}]},
    {"teamId": "TeHome01", "sourceEventId": "ev2", "period": 1, "sortOrder": 1, "homeScoreAfter": 1, "awayScoreAfter": 1, "time": 33, "minute": 33, "injuryTime": 0, "team": 1,
     "actions": [{"playerUniqueId": "PlAway02", "actionType": 4, "actionName": "Own goal", "playerName": "Gassama N."}]},
    {"teamId": "TeAway01", "sourceEventId": "ev3", "period": 2, "sortOrder": 2, "time": 46, "minute": 46, "injuryTime": 0, "team": 2,
     "actions": [{"playerUniqueId": "PlAway01", "actionType": 6, "actionName": "Substitution - Out", "playerName": "Offor C."}, {"playerUniqueId": "PlAway03", "actionType": 7, "actionName": "Substitution - In", "playerName": "Civic E."}]},
    {"teamId": "TeHome01", "sourceEventId": "ev4", "period": 2, "sortOrder": 3, "time": 64, "minute": 64, "injuryTime": 0, "team": 1,
     "actions": [{"playerUniqueId": "PlHome02", "actionType": 1, "actionName": "Yellow Card", "playerName": "Kozlov D.", "reason": "Foul", "reasonCode": 12}]},
    {"teamId": "TeAway01", "sourceEventId": "ev5", "period": 2, "sortOrder": 4, "time": 90, "minute": 90, "injuryTime": 2, "team": 2,
     "actions": [{"playerUniqueId": "PlAway03", "actionType": 1, "actionName": "Yellow Card", "playerName": "Civic E."}, {"playerUniqueId": "PlAway03", "actionType": 2, "actionName": "Red Card", "playerName": "Civic E."}]},
    {"teamId": "TeHome01", "sourceEventId": "ev6", "period": 1, "sortOrder": 5, "homeScoreAfter": 2, "awayScoreAfter": 1, "time": 27, "minute": 27, "injuryTime": 0, "team": 1,
     "actions": [{"playerUniqueId": "PlHome01", "actionType": 5, "actionName": "Penalty Awarded", "playerName": "Glebov K."}, {"playerUniqueId": "PlHome01", "actionType": 10, "actionName": "Penalty", "playerName": "Glebov K."}]},
    {"teamId": "TeHome01", "sourceEventId": "ev7", "period": 2, "sortOrder": 6, "time": 70, "minute": 70, "injuryTime": 0, "team": 1,
     "actions": [{"playerUniqueId": "PlHome01", "actionType": 5, "actionName": "Penalty Awarded", "playerName": "Glebov K."}, {"playerUniqueId": "PlHome01", "actionType": 11, "actionName": "Penalty missed", "playerName": "Glebov K."}]},
    {"teamId": "TeAway01", "sourceEventId": "ev8", "period": 2, "sortOrder": 7, "time": 62, "minute": 62, "injuryTime": 0, "team": 2,
     "actions": [{"playerUniqueId": "CoAway01", "actionType": 1, "actionName": "Yellow Card", "playerName": "Talalaev A."}, {"playerUniqueId": "CoAway01", "actionType": 47, "actionName": "Not on pitch", "playerName": "Talalaev A."}]},
    {"teamId": "TeHome01", "sourceEventId": "ev9", "period": 4, "sortOrder": 8, "time": 1, "penaltyAttempt": 1, "team": 1,
     "actions": [{"playerUniqueId": "PlHome01", "actionType": 10, "actionName": "Penalty", "playerName": "Glebov K."}]},
    {"teamId": "TeHome01", "sourceEventId": "ev10", "period": 2, "sortOrder": 9, "time": 80, "minute": 80, "team": 1,
     "actions": [{"playerUniqueId": "PlHome01", "actionType": 999, "actionName": "VAR something"}]}
  ],
  "statistics": {
    "fullTime": {
      "metrics": [{"id": "432", "name": "xG"}],
      "expectedGoals": {"home": 2.25, "away": 1.52}, "ballPossession": {"home": 65, "away": 35},
      "totalShots": {"home": 18, "away": 14}, "shotsOnTarget": {"home": 5, "away": 3}, "cornerKicks": {"home": 6, "away": 2},
      "totalPasses": {"home": 548, "away": 290}, "completedPasses": {"home": 454, "away": 190},
      "bigChances": {"home": 4, "away": 2}, "xgOnTarget": {"home": 2.77, "away": 1.06}, "goalkeeperSaves": {"home": 1, "away": 4},
      "otherStats": {"Goal kicks": {"home": "8", "away": "11"}}
    },
    "firstHalf": {"expectedGoals": {"home": 1.1, "away": 0.4}},
    "secondHalf": {}
  }
}`

const gameLineupsJSON = `{
  "lineups": [
    {"teamId": "TeHome01", "side": 1, "formation": "4-1-4-1", "averageRating": 7.2, "players": [
      {"playerId": "PlHome01", "playerName": "Glebov K.", "kind": 0, "shirtNumber": "17", "isCaptain": true, "isGoalkeeper": false, "position": "Winger", "formationLine": 1, "formationRow": 3, "sortOrder": 0, "subOutTime": "76",
       "statistics": {"lineupPlayerId": 1, "rating": 7.7, "isBestRating": true, "isBestInTeam": false, "minutesPlayed": 76, "goals": 1, "goalsPenalty": 1, "assists": 0, "yellowCards": 0, "redCards": 0,
         "totalShots": 3, "shotsOnTarget": 2, "expectedGoals": 0.75, "expectedGoalsOnTarget": 1.2, "expectedAssists": 0.001935, "touches": 40, "bonuses": "GOALS,WINNING_GOAL", "extraData": {"A": "1"}}},
      {"playerId": "PlHome02", "playerName": "Kozlov D.", "kind": 0, "shirtNumber": "18", "isGoalkeeper": false, "position": "Midfielder", "formationRow": 3, "sortOrder": 1},
      {"playerId": "PlHome03", "playerName": "Henrique Carmo", "kind": 1, "shirtNumber": "37", "position": "Forward", "sortOrder": 0, "subInTime": "45+2"},
      {"playerId": "CoHome01", "playerName": "Coach H.", "kind": 2},
      {"playerId": "PlHome04", "playerName": "Hurt P.", "kind": 3, "missingReason": "Knee Injury"}
    ]},
    {"teamId": "TeAway01", "side": 2, "formation": "5-4-1", "averageRating": 6.7, "players": [
      {"playerId": "PlAway01", "playerName": "Offor C.", "kind": 0, "shirtNumber": "9", "position": "Striker", "formationRow": 3, "sortOrder": 0, "subOutTime": "46"},
      {"playerId": "PlAway02", "playerName": "Gassama N.", "kind": 0, "shirtNumber": "4", "position": "Centre back", "formationRow": 1, "sortOrder": 1},
      {"playerId": "PlAway03", "playerName": "Civic E.", "kind": 1, "shirtNumber": "77", "position": "Defender", "sortOrder": 0, "subInTime": "46",
       "statistics": {"rating": 6.7, "minutesPlayed": 44, "goals": 0, "assists": 0, "yellowCards": 1, "redCards": 1}},
      {"playerId": "CoAway01", "playerName": "Talalaev A.", "kind": 2}
    ]}
  ],
  "players": [
    {"id": "PlHome01", "name": "Kirill Glebov", "shortName": "Glebov K.", "role": "Midfielder", "birthdate": "2000-02-03", "country": {"num": 158, "id": "russia", "name": "Russia", "abbr2": "ru"}},
    {"id": "PlHome03", "name": "Henrique Carmo", "role": "Forward", "country": {"num": 31, "id": "brazil", "name": "Brazil", "abbr2": "br"}},
    {"id": "PlAway01", "name": "Chinonso Offor", "role": "Forward", "birthdate": "not-a-date", "country": {"num": 158, "id": "russia", "name": "Russia", "abbr2": "ru"}},
    {"id": "CoAway01", "name": "Andrey Talalaev", "role": "Coach", "country": {"num": 158, "id": "russia", "name": "Russia", "abbr2": "ru"}}
  ]
}`

func detailsSource(t *testing.T) fakeSource {
	t.Helper()
	var info sstats.LsGameInfo
	var lineups sstats.LsGameLineups
	if err := json.Unmarshal([]byte(gameInfoJSON), &info); err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal([]byte(gameLineupsJSON), &lineups); err != nil {
		t.Fatal(err)
	}

	return fakeSource{
		info:    map[string]sstats.LsGameInfo{"Mat00001": info},
		lineups: map[string]sstats.LsGameLineups{"Mat00001": lineups},
	}
}

func collectDetails(t *testing.T, src fakeSource, ids ...string) (*dto.Batch, dto.Done) {
	t.Helper()
	msgs := runCommand(t, src, dto.JobSyncMatchDetails, dto.MatchDetailsPayload{MatchExternalIDs: ids}, 200)
	batch, ok := msgs[0].(*dto.Batch)
	if !ok {
		t.Fatalf("expected batch, got %#v", msgs[0])
	}

	return batch, msgs[len(msgs)-1].(dto.Done)
}

func entitiesOf(b *dto.Batch, typ string) []dto.Entity {
	var out []dto.Entity
	for _, e := range b.Entities {
		if e.EntityType == typ {
			out = append(out, e)
		}
	}

	return out
}

func TestSyncMatchDetailsNormalizesEverything(t *testing.T) {
	batch, done := collectDetails(t, detailsSource(t), "Mat00001")

	if len(entitiesOf(batch, dto.EntityMatchDetails)) != 1 {
		t.Fatalf("entities = %+v", batch.Entities)
	}
	last := batch.Entities[len(batch.Entities)-1]
	if last.EntityType != dto.EntityMatchDetails || last.ExternalID != "Mat00001" {
		t.Fatalf("match_details must go last, got %s", last.EntityType)
	}
	d := last.Data.(dto.MatchDetailsData)

	if len(batch.Errors) != 1 || !strings.Contains(batch.Errors[0].Message, "неизвестный тип события") || batch.Errors[0].ExternalID != "Mat00001" {
		t.Fatalf("errors = %+v", batch.Errors)
	}
	if done.ErrorsTotal != 1 || done.EntitiesTotal != len(batch.Entities) {
		t.Fatalf("done = %+v", done)
	}

	if len(d.Teams) != 2 {
		t.Fatalf("teams = %+v", d.Teams)
	}
	home := d.Teams[0]
	if home.Side != "home" || home.TeamExternalID != "TeHome01" || home.Formation != "4-1-4-1" || home.CoachExternalID != "CoHome01" || *home.Rating != 7.2 {
		t.Fatalf("home team = %+v", home)
	}
	if d.Teams[1].CoachExternalID != "CoAway01" {
		t.Fatalf("away coach = %+v", d.Teams[1])
	}

	// 3 + 3 игрока без тренеров и отсутствующих.
	if len(d.Lineup) != 6 {
		t.Fatalf("lineup = %+v", d.Lineup)
	}
	glebov := d.Lineup[0]
	if !glebov.IsStarter || !glebov.IsCaptain || *glebov.ShirtNumber != 17 || *glebov.Position != "MF" || *glebov.Line != 3 || *glebov.MinuteOut != 76 || glebov.MinuteIn != nil {
		t.Fatalf("glebov = %+v", glebov)
	}
	carmo := d.Lineup[2]
	if carmo.IsStarter || *carmo.Position != "FW" || *carmo.MinuteIn != 47 || carmo.Line != nil {
		t.Fatalf("carmo = %+v", carmo)
	}

	var typesSeen []string
	for _, e := range d.Events {
		typesSeen = append(typesSeen, e.Type)
	}
	want := []string{"goal", "own_goal", "substitution", "yellow", "second_yellow", "penalty_goal", "penalty_missed", "yellow"}
	if strings.Join(typesSeen, ",") != strings.Join(want, ",") {
		t.Fatalf("event types = %v, want %v", typesSeen, want)
	}
	goal := d.Events[0]
	if goal.PlayerExternalID != "PlAway01" || goal.RelatedPlayerExternalID != "PlAway02" || goal.TeamExternalID != "TeAway01" || *goal.ScoreHome != 0 || *goal.ScoreAway != 1 || goal.Minute != 1 {
		t.Fatalf("goal = %+v", goal)
	}
	sub := d.Events[2]
	if sub.PlayerExternalID != "PlAway01" || sub.RelatedPlayerExternalID != "PlAway03" || sub.ScoreHome != nil {
		t.Fatalf("sub = %+v", sub)
	}
	yellow := d.Events[3]
	if yellow.Details["reason"] != "Foul" || yellow.Details["reason_code"] != 12 || yellow.Details["source_event_id"] != "ev4" {
		t.Fatalf("yellow details = %+v", yellow.Details)
	}
	second := d.Events[4]
	if second.Minute != 90 || *second.AddedMinute != 2 {
		t.Fatalf("second yellow = %+v", second)
	}
	coachCard := d.Events[7]
	if coachCard.PlayerExternalID != "" || coachCard.Details["not_on_pitch"] != true || coachCard.Details["person_name"] != "Talalaev A." {
		t.Fatalf("coach card = %+v", coachCard)
	}

	if len(d.TeamStats) != 4 {
		t.Fatalf("team stats = %+v", d.TeamStats)
	}
	fh, fa := d.TeamStats[0], d.TeamStats[1]
	if fh.Period != "full_time" || fh.TeamExternalID != "TeHome01" || *fh.XG != 2.25 || *fh.XGA != 1.52 || *fa.XG != 1.52 || *fa.XGA != 2.25 {
		t.Fatalf("xg stats = %+v / %+v", fh, fa)
	}
	if *fh.Shots != 18 || *fh.ShotsOnTarget != 5 || *fh.Corners != 6 || *fh.PossessionPct != 65 || *fh.Passes != 548 || *fh.PassAccuracyPct != 82.85 || *fh.Saves != 1 || fh.RedCards != nil {
		t.Fatalf("home stats = %+v", fh)
	}
	if fh.Extra["xg_on_target"] != 2.77 || fh.Extra["big_chances"] != 4 || fh.Extra["completed_passes"] != 454 {
		t.Fatalf("extra = %+v", fh.Extra)
	}
	if fh.Extra["other_stats"].(map[string]string)["Goal kicks"] != "8" {
		t.Fatalf("other_stats = %+v", fh.Extra)
	}
	if d.TeamStats[2].Period != "first_half" || *d.TeamStats[2].XG != 1.1 || d.TeamStats[2].Shots != nil {
		t.Fatalf("first half = %+v", d.TeamStats[2])
	}

	if len(d.PlayerStats) != 2 {
		t.Fatalf("player stats = %+v", d.PlayerStats)
	}
	ps := d.PlayerStats[0]
	if ps.PlayerExternalID != "PlHome01" || ps.TeamExternalID != "TeHome01" || *ps.Minutes != 76 || *ps.Goals != 1 || *ps.PenaltiesScored != 1 ||
		*ps.XG != 0.75 || *ps.XA != 0.001935 || *ps.Rating != 7.7 || !ps.IsMOTM || *ps.Shots != 3 || *ps.ShotsOnTarget != 2 {
		t.Fatalf("player stats = %+v", ps)
	}
	if ps.Extra["expected_goals_on_target"] != 1.2 || ps.Extra["touches"] != 40 || ps.Extra["extra_data"] != nil {
		t.Fatalf("player extra = %+v", ps.Extra)
	}
	if b, ok := ps.Extra["bonuses"].([]string); !ok || len(b) != 2 || b[1] != "WINNING_GOAL" {
		t.Fatalf("bonuses = %+v", ps.Extra["bonuses"])
	}
	if ps2 := d.PlayerStats[1]; ps2.IsMOTM || *ps2.RedCards != 1 || ps2.XG != nil {
		t.Fatalf("second player stats = %+v", ps2)
	}

	players := entitiesOf(batch, dto.EntityPlayer)
	coaches := entitiesOf(batch, dto.EntityCoach)
	countries := entitiesOf(batch, dto.EntityCountry)
	if len(players) != 6 || len(coaches) != 2 || len(countries) != 2 {
		t.Fatalf("players=%d coaches=%d countries=%d", len(players), len(coaches), len(countries))
	}
	var glebovPlayer dto.PlayerData
	var offorPlayer dto.PlayerData
	var kozlovPlayer dto.PlayerData
	for _, e := range players {
		switch e.ExternalID {
		case "PlHome01":
			glebovPlayer = e.Data.(dto.PlayerData)
		case "PlAway01":
			offorPlayer = e.Data.(dto.PlayerData)
		case "PlHome02":
			kozlovPlayer = e.Data.(dto.PlayerData)
		}
	}
	if !glebovPlayer.HasProfile || kozlovPlayer.HasProfile {
		t.Fatalf("has_profile flags: %+v / %+v", glebovPlayer, kozlovPlayer)
	}
	if glebovPlayer.Name != "Kirill Glebov" || glebovPlayer.CountryExternalID != "158" || glebovPlayer.Position != "MF" || glebovPlayer.BirthDate != "2000-02-03" {
		t.Fatalf("glebov player = %+v", glebovPlayer)
	}
	if offorPlayer.BirthDate != "" || offorPlayer.Position != "FW" {
		t.Fatalf("offor player = %+v", offorPlayer)
	}
	if kozlovPlayer.Name != "Kozlov D." || kozlovPlayer.CountryExternalID != "" || kozlovPlayer.TeamExternalID != "TeHome01" {
		t.Fatalf("player without profile = %+v", kozlovPlayer)
	}
	if countries[0].EntityType != dto.EntityCountry {
		t.Fatalf("countries = %+v", countries)
	}
}

func TestSyncMatchDetailsFetchErrorsDoNotStopRun(t *testing.T) {
	src := detailsSource(t)
	src.detailsErr = map[string]error{"Gone0001": &sstats.APIError{Message: "match not found"}}
	batch, done := collectDetails(t, src, "Gone0001", "Mat00001", "Mat00001", " ")

	if len(entitiesOf(batch, dto.EntityMatchDetails)) != 1 {
		t.Fatalf("expected one details entity, got %+v", batch.Entities)
	}
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

func TestSyncMatchDetailsEmptySourceIsError(t *testing.T) {
	src := fakeSource{info: map[string]sstats.LsGameInfo{"Empty001": {Game: sstats.LsGameRef{ID: "Empty001", HomeTeamID: "a", AwayTeamID: "b"}}}}
	batch, _ := collectDetails(t, src, "Empty001")

	if len(batch.Entities) != 0 || len(batch.Errors) != 1 || batch.Errors[0].Phase != dto.PhaseNormalize {
		t.Fatalf("batch = %+v", batch)
	}
}

func TestSyncMatchDetailsLineupTeamMismatch(t *testing.T) {
	src := detailsSource(t)
	lineups := src.lineups["Mat00001"]
	lineups.Lineups[1].TeamID = "Other001"
	src.lineups["Mat00001"] = lineups

	batch, _ := collectDetails(t, src, "Mat00001")
	d := entitiesOf(batch, dto.EntityMatchDetails)[0].Data.(dto.MatchDetailsData)
	if len(d.Teams) != 1 || d.Teams[0].Side != "home" {
		t.Fatalf("teams = %+v", d.Teams)
	}
	found := false
	for _, e := range batch.Errors {
		found = found || strings.Contains(e.Message, "не совпадает")
	}
	if !found {
		t.Fatalf("errors = %+v", batch.Errors)
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

func TestParseMinute(t *testing.T) {
	str := func(s string) *string { return &s }
	cases := map[string]int{"46": 46, "45+2": 47, " 90+4 ": 94}
	for in, want := range cases {
		if got := parseMinute(str(in)); got == nil || *got != want {
			t.Fatalf("parseMinute(%q) = %v, want %d", in, got, want)
		}
	}
	for _, in := range []string{"", "abc", "45+"} {
		if got := parseMinute(str(in)); got != nil {
			t.Fatalf("parseMinute(%q) = %d, want nil", in, *got)
		}
	}
	if parseMinute(nil) != nil {
		t.Fatal("nil must stay nil")
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
	var lineups sstats.LsGameLineups
	_ = json.Unmarshal([]byte(gameLineupsJSON), &lineups)

	var ids []string
	for i := 0; i < 3; i++ {
		id := "Big0000" + string(rune('1'+i))
		var info sstats.LsGameInfo
		_ = json.Unmarshal([]byte(gameInfoJSON), &info)
		info.Game.ID = id
		info.Events[3].Actions[0].Comment = strings.Repeat("x", 400_000)
		src.info[id], src.lineups[id] = info, lineups
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
