package jobs

import (
	"encoding/json"
	"testing"
	"time"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

func fi(n int) *sstats.FlexInt {
	v := sstats.FlexInt(n)
	return &v
}

func fixture(id string, status int) sstats.LsFixture {
	return sstats.LsFixture{
		ID: id, TournamentID: "YacqHHdS", SeasonID: "SeAs2026", StageID: "StG00001", RoundName: "Round 3",
		HomeTeamID: "TeAm0001", AwayTeamID: "TeAm0002", Date: "2026-08-02T17:00:00+03:00", Status: sstats.FlexInt(status),
	}
}

func TestSyncFixturesNormalizesMatches(t *testing.T) {
	finished := fixture("Fin00001", 3)
	finished.HomeResult, finished.AwayResult = fi(2), fi(1)
	finished.HomeResultFT, finished.AwayResultFT = fi(2), fi(1)
	finished.HomeResultHT, finished.AwayResultHT = fi(1), fi(0)

	extra := fixture("Ext00001", 10)
	extra.HomeResultFT, extra.AwayResultFT = fi(1), fi(1)
	extra.HomeResultET, extra.AwayResultET = fi(1), fi(0)
	extra.HomeResult, extra.AwayResult = fi(2), fi(1)

	live := fixture("Liv00001", 13)
	live.HomeResult, live.AwayResult = fi(0), fi(0)

	upcoming := fixture("Upc00001", 1)
	upcoming.RoundName = "Final"

	badStatus := fixture("Bad00001", 777)
	noStage := fixture("Bad00002", 1)
	noStage.StageID = ""
	noTeams := fixture("Bad00003", 1)
	noTeams.AwayTeamID = ""

	var got sstats.FixturesQuery
	src := fakeSource{gotQuery: &got, fixtures: []sstats.LsFixture{finished, extra, live, upcoming, badStatus, noStage, noTeams}}
	msgs := runCommand(t, src, dto.JobSyncFixtures, dto.FixturesPayload{
		SeasonExternalID: "SeAs2026",
		Window:           &dto.FixturesWindow{From: "2026-08-01", To: "2026-08-31"},
	}, 200)

	if got.SeasonID != "SeAs2026" || !got.From.Equal(time.Date(2026, 8, 1, 0, 0, 0, 0, time.UTC)) || !got.To.Equal(time.Date(2026, 9, 1, 0, 0, 0, 0, time.UTC)) {
		t.Fatalf("query = %+v", got)
	}

	batch := msgs[0].(*dto.Batch)
	if len(batch.Entities) != 4 || len(batch.Errors) != 3 {
		t.Fatalf("entities=%d errors=%d: %+v", len(batch.Entities), len(batch.Errors), batch.Errors)
	}
	for _, e := range batch.Errors {
		if e.EntityType != dto.EntityMatch || e.Phase != dto.PhaseNormalize {
			t.Fatalf("error = %+v", e)
		}
	}

	m := batch.Entities[0].Data.(dto.MatchData)
	if batch.Entities[0].ExternalID != "Fin00001" || m.Status != dto.MatchFinished || *m.HomeGoals != 2 || *m.AwayGoals != 1 || *m.HomeGoalsHT != 1 {
		t.Fatalf("finished = %+v", m)
	}
	if m.KickoffAt != "2026-08-02T14:00:00Z" || *m.RoundNumber != 3 || m.HomeTeamExternalID != "TeAm0001" || m.StageExternalID != "StG00001" {
		t.Fatalf("finished refs = %+v", m)
	}

	et := batch.Entities[1].Data.(dto.MatchData)
	if *et.HomeGoals != 2 || *et.AwayGoals != 1 || *et.HomeGoalsET != 1 || et.HomePens != nil {
		t.Fatalf("extra time = %+v", et)
	}

	lv := batch.Entities[2].Data.(dto.MatchData)
	if lv.Status != dto.MatchLive || lv.Period == nil || *lv.Period != dto.PeriodSecondHalf || *lv.HomeGoals != 0 {
		t.Fatalf("live = %+v", lv)
	}

	up := batch.Entities[3].Data.(dto.MatchData)
	if up.Status != dto.MatchScheduled || up.HomeGoals != nil || up.RoundNumber != nil || up.RoundName != "Final" {
		t.Fatalf("upcoming = %+v", up)
	}

	done := msgs[len(msgs)-1].(dto.Done)
	if done.EntitiesTotal != 4 || done.ErrorsTotal != 3 {
		t.Fatalf("done = %+v", done)
	}
}

func TestSyncFixturesStageFallbackFromPayload(t *testing.T) {
	f := fixture("Fb000001", 1)
	f.StageID = ""
	msgs := runCommand(t, fakeSource{fixtures: []sstats.LsFixture{f}}, dto.JobSyncFixtures,
		dto.FixturesPayload{SeasonExternalID: "SeAs2026", StageExternalID: "StgPay01"}, 200)

	m := msgs[0].(*dto.Batch).Entities[0].Data.(dto.MatchData)
	if m.StageExternalID != "StgPay01" {
		t.Fatalf("stage = %q", m.StageExternalID)
	}
}

func TestSyncFixturesRejectsBadPayload(t *testing.T) {
	for name, payload := range map[string]dto.FixturesPayload{
		"no season": {},
		"bad date":  {SeasonExternalID: "s", Window: &dto.FixturesWindow{From: "01.08.2026"}},
		"reversed":  {SeasonExternalID: "s", Window: &dto.FixturesWindow{From: "2026-09-01", To: "2026-08-01"}},
	} {
		msgs := runCommand(t, fakeSource{}, dto.JobSyncFixtures, payload, 200)
		if f, ok := msgs[0].(dto.Failed); !ok || f.Retryable {
			t.Fatalf("%s: expected non-retryable failed, got %#v", name, msgs[0])
		}
	}
}

func TestMatchStatusMapping(t *testing.T) {
	cases := map[int]string{
		1: dto.MatchScheduled, 12: dto.MatchLive, 38: dto.MatchLive, 45: dto.MatchLive, 3: dto.MatchFinished, 11: dto.MatchFinished,
		9: dto.MatchFinished, 43: dto.MatchPostponed, 36: dto.MatchAbandoned, 37: dto.MatchAbandoned, 5: dto.MatchCancelled,
	}
	for code, want := range cases {
		if got, _, ok := matchStatus(code); !ok || got != want {
			t.Fatalf("status %d = %q, want %q", code, got, want)
		}
	}
	if _, _, ok := matchStatus(999); ok {
		t.Fatal("unknown status must not be accepted")
	}
}

func gameRef(t *testing.T, raw string) sstats.GameRef {
	t.Helper()
	var g sstats.GameRef
	if err := json.Unmarshal([]byte(raw), &g); err != nil {
		t.Fatal(err)
	}

	return g
}

func TestSyncFixturesAddsExternalRefs(t *testing.T) {
	g1 := gameRef(t, `{"id":1577610,"flashId":"Fin00001","mappings":{"flashscore":"Fin00001","pari":"70159794"},
		"homeTeam":{"id":3985,"flashId":"TeAm0001","mappings":{"flashscore":["TeAm0001"],"pari":["420773"]}},
		"awayTeam":{"id":6811,"flashId":null,"mappings":{}},
		"season":{"league":{"id":236,"flashScoreId":"YacqHHdS","mappings":{"flashscore":["YacqHHdS"],"pari":["80073"]}}}}`)
	g2 := gameRef(t, `{"id":"1577611","flashId":"Liv00001","mappings":{"flashscore":"Liv00001"},
		"homeTeam":{"id":3985,"flashId":"TeAm0001","mappings":{"flashscore":["TeAm0001"],"pari":["420773"]}},
		"awayTeam":{"id":1087,"flashId":"TeAm0002","mappings":{"pari":["419821"]}},
		"season":{"league":{"id":236,"flashScoreId":"YacqHHdS","mappings":{}}}}`)

	var asked []string
	src := fakeSource{
		fixtures: []sstats.LsFixture{fixture("Fin00001", 1), fixture("Liv00001", 1)},
		games:    []sstats.GameRef{g1, g2},
		gotFlash: &asked,
	}
	msgs := runCommand(t, src, dto.JobSyncFixtures, dto.FixturesPayload{SeasonExternalID: "SeAs2026"}, 200)

	if len(asked) != 2 || asked[0] != "Fin00001" || asked[1] != "Liv00001" {
		t.Fatalf("asked = %v", asked)
	}

	batch := msgs[0].(*dto.Batch)
	refs := map[string]dto.ExternalRefsData{}
	for _, e := range batch.Entities {
		if e.EntityType == dto.EntityExternalRefs {
			d := e.Data.(dto.ExternalRefsData)
			refs[d.EntityType+":"+e.ExternalID] = d
		}
	}
	// 2 матча + команды TeAm0001, TeAm0002 (у команды без flashId id нет ключа) + одна лига.
	if len(refs) != 5 {
		t.Fatalf("refs = %+v", refs)
	}
	m := refs["match:Fin00001"].Refs
	if m[dto.RefSstatsAPI] != "1577610" || m[dto.RefFlashscore] != "Fin00001" || m[dto.RefPari] != "70159794" {
		t.Fatalf("match refs = %+v", m)
	}
	if l := refs["match:Liv00001"].Refs; l[dto.RefSstatsAPI] != "1577611" || l[dto.RefPari] != "" {
		t.Fatalf("match without pari = %+v", l)
	}
	if tm := refs["team:TeAm0001"].Refs; tm[dto.RefSstatsAPI] != "3985" || tm[dto.RefPari] != "420773" {
		t.Fatalf("team refs = %+v", tm)
	}
	if c := refs["competition:YacqHHdS"].Refs; c[dto.RefSstatsAPI] != "236" || c[dto.RefPari] != "80073" {
		t.Fatalf("competition refs = %+v", c)
	}
}

func TestSyncFixturesKeepsMatchesWhenGamesListFails(t *testing.T) {
	src := fakeSource{fixtures: []sstats.LsFixture{fixture("Fin00001", 1)}, gamesErr: &sstats.StatusError{Code: 502, Message: "bad gateway"}}
	msgs := runCommand(t, src, dto.JobSyncFixtures, dto.FixturesPayload{SeasonExternalID: "SeAs2026"}, 200)

	batch := msgs[0].(*dto.Batch)
	if len(batch.Entities) != 1 || batch.Entities[0].EntityType != dto.EntityMatch {
		t.Fatalf("entities = %+v", batch.Entities)
	}
	if len(batch.Errors) != 1 || batch.Errors[0].Phase != dto.PhaseFetch || batch.Errors[0].EntityType != dto.EntityExternalRefs {
		t.Fatalf("errors = %+v", batch.Errors)
	}
}

func TestSyncFixturesFailsOnUnauthorizedGamesList(t *testing.T) {
	src := fakeSource{fixtures: []sstats.LsFixture{fixture("Fin00001", 1)}, gamesErr: sstats.ErrUnauthorized}
	msgs := runCommand(t, src, dto.JobSyncFixtures, dto.FixturesPayload{SeasonExternalID: "SeAs2026"}, 200)
	if _, ok := msgs[0].(dto.Failed); !ok {
		t.Fatalf("expected failed, got %#v", msgs[0])
	}
}
