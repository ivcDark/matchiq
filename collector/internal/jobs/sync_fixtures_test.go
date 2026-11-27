package jobs

import (
	"encoding/json"
	"testing"
	"time"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

const gamesJSON = `[
 {"mappings":{"flashscore":"xWXoFJwP","pari":"66244605"},"id":1563072,"flashId":"xWXoFJwP","date":"2026-07-11T15:00:00+03:00","dateUtc":1783771200,
  "status":8,"statusName":"Finished","homeResult":3,"awayResult":0,"homeHTResult":2,"awayHTResult":0,"homeFTResult":3,"awayFTResult":0,
  "homeTeam":{"mappings":{"flashscore":["fPxiAl6T"],"pari":["420730"]},"id":1078,"name":"FC UFA","flashId":"fPxiAl6T","country":{"code":"RU","name":"Russia"}},
  "awayTeam":{"mappings":{},"id":6811,"name":"Leningradets","flashId":null,"country":{"code":"RU","name":"Russia"}},
  "season":{"uid":"0159bf63","year":2026,"league":{"id":236,"flashScoreId":"jizXHcsM"}},"roundName":"Regular Season - 1",
  "odds":[{"marketId":1,"marketName":null,"odds":[{"name":"Home","value":2.07},{"name":"Away","value":3.5},{"name":"Draw","value":3.1}]},
          {"marketId":5,"marketName":"Goals Over/Under","odds":[{"name":"Over 2.5","value":2.17},{"name":"Under 2.5","value":0}]}]},
 {"mappings":{},"id":1563100,"flashId":null,"dateUtc":1785000000,"status":4,"statusName":"Halftime","homeResult":1,"awayResult":1,"homeHTResult":1,"awayHTResult":1,
  "homeTeam":{"id":1078,"name":"FC UFA","flashId":"fPxiAl6T"},"awayTeam":{"id":596,"name":"Zenit","flashId":"Zen00001"},
  "season":{"uid":"0159bf63","year":2026},"roundName":"Regular Season - 2","odds":null},
 {"id":1563200,"flashId":"Up000001","dateUtc":1786000000,"status":2,"statusName":"Not Started","homeResult":null,"awayResult":null,
  "homeTeam":{"id":596,"name":"Zenit","flashId":"Zen00001"},"awayTeam":{"id":6811,"name":"Leningradets"},"season":{"uid":"0159bf63","year":2026},"roundName":"Play-offs"},
 {"id":1563300,"flashId":"Bad00001","dateUtc":1786000000,"status":99,"statusName":"Weird",
  "homeTeam":{"id":596},"awayTeam":{"id":6811},"season":{"uid":"0159bf63"}},
 {"id":1563400,"dateUtc":1786000000,"status":2,"homeTeam":{"id":596},"awayTeam":{"id":596},"season":{"uid":"0159bf63"}}
]`

func games(t *testing.T) []sstats.Game {
	t.Helper()
	var g []sstats.Game
	if err := json.Unmarshal([]byte(gamesJSON), &g); err != nil {
		t.Fatal(err)
	}

	return g
}

func fixtureEntities(msgs []any) (map[string]dto.MatchData, map[string]dto.TeamData, []dto.EntityError, []string) {
	matches, teams := map[string]dto.MatchData{}, map[string]dto.TeamData{}
	var errs []dto.EntityError
	var order []string
	for _, m := range msgs {
		b, ok := m.(*dto.Batch)
		if !ok {
			continue
		}
		for _, e := range b.Entities {
			order = append(order, e.EntityType)
			switch d := e.Data.(type) {
			case dto.MatchData:
				matches[e.ExternalID] = d
			case dto.TeamData:
				teams[e.ExternalID] = d
			}
		}
		errs = append(errs, b.Errors...)
	}

	return matches, teams, errs, order
}

func TestSyncFixturesFromGamesList(t *testing.T) {
	var got sstats.GamesQuery
	src := fakeSource{games: games(t), gotGames: &got}

	msgs := runCommand(t, src, dto.JobSyncFixtures, dto.FixturesPayload{
		SeasonAPIID: "0159bf63", Window: &dto.FixturesWindow{From: "2026-07-10", To: "2026-08-31"},
	}, 200)

	if got.SeasonUID != "0159bf63" || !got.From.Equal(time.Date(2026, 7, 10, 0, 0, 0, 0, time.UTC)) || !got.To.Equal(time.Date(2026, 9, 1, 0, 0, 0, 0, time.UTC)) {
		t.Fatalf("query = %+v", got)
	}

	matches, teams, errs, order := fixtureEntities(msgs)
	if order[0] != dto.EntityTeam || order[len(order)-1] != dto.EntityMatch {
		t.Fatalf("teams must go before matches: %v", order)
	}
	if len(teams) != 3 || teams["6811"].FlashID != "" || teams["6811"].Name != "Leningradets" || teams["1078"].FlashID != "fPxiAl6T" ||
		teams["1078"].Refs[dto.RefPari] != "420730" || teams["1078"].CountryCode != "RU" {
		t.Fatalf("teams = %+v", teams)
	}

	fin := matches["1563072"]
	if fin.APIID != "1563072" || fin.FlashID != "xWXoFJwP" || fin.Status != dto.MatchFinished || *fin.HomeGoals != 3 || *fin.AwayGoals != 0 ||
		*fin.HomeGoalsHT != 2 || fin.KickoffAt != "2026-07-11T12:00:00Z" || fin.RoundNumber == nil || *fin.RoundNumber != 1 ||
		fin.HomeTeamAPIID != "1078" || fin.AwayTeamAPIID != "6811" || fin.HomeTeamExternalID != "fPxiAl6T" || fin.AwayTeamExternalID != "" ||
		fin.SeasonAPIID != "0159bf63" || fin.SeasonExternalID != "" || fin.StageExternalID != "" ||
		fin.Refs[dto.RefFlashscore] != "xWXoFJwP" || fin.Refs[dto.RefPari] != "66244605" {
		t.Fatalf("finished = %+v", fin)
	}
	if len(fin.Odds) != 4 || fin.Odds[0] != (dto.MatchOddData{MarketID: 1, Outcome: "Home", Value: 2.07}) ||
		fin.Odds[3].MarketName != "Goals Over/Under" || fin.Odds[3].Outcome != "Over 2.5" {
		t.Fatalf("odds = %+v", fin.Odds)
	}

	live := matches["1563100"]
	if live.Status != dto.MatchLive || live.Period == nil || *live.Period != dto.PeriodHalfTime || *live.HomeGoals != 1 || live.FlashID != "" || live.Odds != nil {
		t.Fatalf("live = %+v", live)
	}
	up := matches["1563200"]
	if up.Status != dto.MatchScheduled || up.HomeGoals != nil || up.RoundNumber != nil || up.RoundName != "Play-offs" {
		t.Fatalf("upcoming = %+v", up)
	}

	if len(errs) != 2 {
		t.Fatalf("errors = %+v", errs)
	}
	raw, _ := json.Marshal(fin)
	var asMap map[string]any
	_ = json.Unmarshal(raw, &asMap)
	if _, ok := asMap["home_goals_et"]; ok {
		t.Fatal("extra time is not in /Games/list: the key must be absent so Laravel keeps the stored value")
	}
}

func TestSyncFixturesLsStageFallbackForMultiStageSeason(t *testing.T) {
	var lsQuery sstats.FixturesQuery
	src := fakeSource{
		games:    games(t)[:3],
		gotQuery: &lsQuery,
		fixtures: []sstats.LsFixture{
			{ID: "xWXoFJwP", StageID: "StMain01", RoundID: "77", RoundName: "Round 1", AwayTeamID: "Len00001"},
			{ID: "Up000001", StageID: "StPlay01", RoundID: "88", RoundName: "Final", AwayTeamID: "Len00001"},
		},
	}
	msgs := runCommand(t, src, dto.JobSyncFixtures, dto.FixturesPayload{
		SeasonExternalID: "rXQUSf8L", SeasonAPIID: "0159bf63", LsStages: true,
	}, 200)

	if lsQuery.SeasonID != "rXQUSf8L" {
		t.Fatalf("ls query = %+v", lsQuery)
	}
	matches, _, _, _ := fixtureEntities(msgs)
	if m := matches["1563072"]; m.StageExternalID != "StMain01" || m.RoundExternalID != "77" {
		t.Fatalf("stage from Ls = %+v", m)
	}
	if m := matches["1563200"]; m.StageExternalID != "StPlay01" || m.RoundExternalID != "88" {
		t.Fatalf("stage from Ls = %+v", m)
	}
	if m := matches["1563100"]; m.StageExternalID != "" {
		t.Fatalf("match without flash-id has no Ls stage: %+v", m)
	}
}

func TestSyncFixturesLsFailureKeepsMatchesButDefersTeamsWithoutFlash(t *testing.T) {
	src := fakeSource{games: games(t)[:2], fixtureErr: &sstats.StatusError{Code: 502, Message: "bad gateway"}}
	msgs := runCommand(t, src, dto.JobSyncFixtures, dto.FixturesPayload{SeasonExternalID: "rXQUSf8L", SeasonAPIID: "0159bf63", LsStages: true}, 200)
	matches, teams, errs, _ := fixtureEntities(msgs)
	if len(matches) != 1 || matches["1563100"].APIID == "" || len(errs) != 2 || errs[0].Phase != dto.PhaseFetch || errs[1].ExternalID != "1563072" {
		t.Fatalf("matches=%+v errs=%+v", matches, errs)
	}
	if _, ok := teams["6811"]; ok {
		t.Fatal("team without flashId must not be sent while /Ls/List is unavailable")
	}

	src.fixtureErr = sstats.ErrUnauthorized
	msgs = runCommand(t, src, dto.JobSyncFixtures, dto.FixturesPayload{SeasonExternalID: "rXQUSf8L", SeasonAPIID: "0159bf63", LsStages: true}, 200)
	if _, ok := msgs[0].(dto.Failed); !ok {
		t.Fatalf("401 from Ls must fail the run: %#v", msgs[0])
	}
}

func TestSyncFixturesStageFromPayloadSkipsLs(t *testing.T) {
	var lsQuery sstats.FixturesQuery
	src := fakeSource{games: games(t)[1:2], gotQuery: &lsQuery}
	msgs := runCommand(t, src, dto.JobSyncFixtures, dto.FixturesPayload{
		SeasonExternalID: "rXQUSf8L", LeagueAPIID: "236", SeasonYear: 2026, StageExternalID: "StMain01", LsStages: true,
	}, 200)
	matches, _, _, _ := fixtureEntities(msgs)
	if matches["1563100"].StageExternalID != "StMain01" || lsQuery.SeasonID != "" {
		t.Fatalf("stage = %+v, ls query = %+v", matches["1563100"], lsQuery)
	}
}

func TestSyncFixturesSingleStageWithFlashTeamsSkipsLs(t *testing.T) {
	var lsQuery sstats.FixturesQuery
	src := fakeSource{games: games(t)[1:2], gotQuery: &lsQuery}
	runCommand(t, src, dto.JobSyncFixtures, dto.FixturesPayload{SeasonExternalID: "rXQUSf8L", SeasonAPIID: "0159bf63"}, 200)
	if lsQuery.SeasonID != "" {
		t.Fatalf("no extra /Ls/List for a single-stage season with known teams: %+v", lsQuery)
	}
}

func TestSyncFixturesTeamFlashAndPenaltiesFromLsList(t *testing.T) {
	var lsQuery sstats.FixturesQuery
	pens := func(n int) *sstats.FlexInt { v := sstats.FlexInt(n); return &v }
	src := fakeSource{
		games:    games(t)[:3],
		gotQuery: &lsQuery,
		fixtures: []sstats.LsFixture{
			{ID: "xWXoFJwP", StageID: "StMain01", HomeTeamID: "fPxiAl6T", AwayTeamID: "Len00001", HomeResultAP: pens(4), AwayResultAP: pens(3)},
		},
	}
	msgs := runCommand(t, src, dto.JobSyncFixtures, dto.FixturesPayload{SeasonExternalID: "rXQUSf8L", SeasonAPIID: "0159bf63"}, 200)

	if lsQuery.SeasonID != "rXQUSf8L" {
		t.Fatalf("Leningradets has no flashId: /Ls/List must be requested, got %+v", lsQuery)
	}
	matches, teams, _, _ := fixtureEntities(msgs)
	if teams["6811"].FlashID != "Len00001" || teams["6811"].Refs[dto.RefFlashscore] != "Len00001" {
		t.Fatalf("team flash-id from the Ls record of the same match: %+v", teams["6811"])
	}
	fin := matches["1563072"]
	if fin.AwayTeamExternalID != "Len00001" || fin.HomePens == nil || *fin.HomePens != 4 || *fin.AwayPens != 3 || fin.StageExternalID != "" {
		t.Fatalf("finished = %+v", fin)
	}
	if up := matches["1563200"]; up.AwayTeamExternalID != "Len00001" || up.HomePens != nil {
		t.Fatalf("same team in another match = %+v", up)
	}
}

func TestSyncFixturesSkipsMatchWhoseTeamHasNoFlashAnywhere(t *testing.T) {
	src := fakeSource{games: games(t)[:3], fixtures: []sstats.LsFixture{{ID: "Other001", HomeTeamID: "x", AwayTeamID: "y"}}}
	msgs := runCommand(t, src, dto.JobSyncFixtures, dto.FixturesPayload{SeasonExternalID: "rXQUSf8L", SeasonAPIID: "0159bf63"}, 200)
	matches, teams, errs, _ := fixtureEntities(msgs)
	if len(matches) != 1 || matches["1563100"].APIID == "" || len(errs) != 2 || errs[0].ExternalID != "1563072" || errs[1].ExternalID != "1563200" {
		t.Fatalf("matches=%+v errs=%+v", matches, errs)
	}
	if _, ok := teams["6811"]; ok {
		t.Fatal("team without flash-id must not be created in a season with Ls structure")
	}
}

func TestSyncFixturesRejectsBadPayload(t *testing.T) {
	for _, payload := range []any{
		dto.FixturesPayload{SeasonExternalID: "rXQUSf8L"},
		dto.FixturesPayload{SeasonAPIID: "x", Window: &dto.FixturesWindow{From: "2026-09-01", To: "2026-08-01"}},
	} {
		msgs := runCommand(t, fakeSource{}, dto.JobSyncFixtures, payload, 200)
		if f, ok := msgs[0].(dto.Failed); !ok || f.Retryable {
			t.Fatalf("expected non-retryable failure, got %#v", msgs[0])
		}
	}
}

func TestGameStatusCodes(t *testing.T) {
	cases := map[int][2]string{
		1: {dto.MatchScheduled, ""}, 2: {dto.MatchScheduled, ""}, 3: {dto.MatchLive, dto.PeriodFirstHalf}, 4: {dto.MatchLive, dto.PeriodHalfTime},
		5: {dto.MatchLive, dto.PeriodSecondHalf}, 6: {dto.MatchLive, dto.PeriodExtraTime}, 11: {dto.MatchLive, dto.PeriodExtraTime},
		7: {dto.MatchLive, dto.PeriodPenalties}, 12: {dto.MatchLive, ""}, 19: {dto.MatchLive, ""},
		8: {dto.MatchFinished, ""}, 9: {dto.MatchFinished, ""}, 10: {dto.MatchFinished, ""}, 17: {dto.MatchFinished, ""}, 18: {dto.MatchFinished, ""},
		13: {dto.MatchAbandoned, ""}, 14: {dto.MatchPostponed, ""}, 15: {dto.MatchCancelled, ""},
	}
	for code, want := range cases {
		status, period, ok := gameStatusCode(code)
		got := ""
		if period != nil {
			got = *period
		}
		if !ok || status != want[0] || got != want[1] {
			t.Errorf("code %d: %s/%s, want %v", code, status, got, want)
		}
	}
	if _, _, ok := gameStatusCode(16); ok {
		t.Error("unknown code must be rejected")
	}
}

func TestRoundNumber(t *testing.T) {
	for name, want := range map[string]int{"Round 5": 5, "Regular Season - 12": 12, "League Stage - 3": 3, "Play-offs": 0, "Final": 0} {
		got := roundNumber(name)
		if (want == 0) != (got == nil) || (got != nil && *got != want) {
			t.Errorf("roundNumber(%q) = %v, want %d", name, got, want)
		}
	}
}
