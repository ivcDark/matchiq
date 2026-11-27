package jobs

import (
	"context"
	"encoding/json"
	"testing"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

type fakeSource struct {
	leagues    []sstats.League
	seasons    map[string][]sstats.LsSeason
	structure  sstats.LsSeasonStructure
	gotSeason  *string
	standings  []sstats.LsStandings
	fixtures   []sstats.LsFixture
	fixtureErr error
	gotQuery   *sstats.FixturesQuery
	games      []sstats.Game
	gotGames   *sstats.GamesQuery
	err        error

	game       map[string]sstats.GameFull
	gotGameIDs *[]string
	detailsErr map[string]error

	squads    map[string][]sstats.Player
	squadErrs map[string]error
	lsSquads  map[string][]sstats.LsSquadPlayer
	lsErrs    map[string]error
}

func (f fakeSource) Leagues(context.Context) ([]sstats.League, error) {
	return f.leagues, f.err
}
func (f fakeSource) Seasons(_ context.Context, id string) ([]sstats.LsSeason, error) {
	return f.seasons[id], f.err
}
func (f fakeSource) SeasonStructure(_ context.Context, id string) (sstats.LsSeasonStructure, error) {
	if f.gotSeason != nil {
		*f.gotSeason = id
	}
	return f.structure, f.err
}
func (f fakeSource) Standings(context.Context, string) ([]sstats.LsStandings, error) {
	return f.standings, f.err
}

func (f fakeSource) Fixtures(_ context.Context, q sstats.FixturesQuery) ([]sstats.LsFixture, error) {
	if f.gotQuery != nil {
		*f.gotQuery = q
	}
	if f.fixtureErr != nil {
		return nil, f.fixtureErr
	}
	return f.fixtures, f.err
}

func (f fakeSource) Games(_ context.Context, q sstats.GamesQuery) ([]sstats.Game, error) {
	if f.gotGames != nil {
		*f.gotGames = q
	}
	return f.games, f.err
}

func (f fakeSource) Game(_ context.Context, id string) (sstats.GameFull, error) {
	if f.gotGameIDs != nil {
		*f.gotGameIDs = append(*f.gotGameIDs, id)
	}
	if err := f.detailsErr[id]; err != nil {
		return sstats.GameFull{}, err
	}
	return f.game[id], f.err
}

func (f fakeSource) Players(_ context.Context, teamID string) ([]sstats.Player, error) {
	if err := f.squadErrs[teamID]; err != nil {
		return nil, err
	}
	return f.squads[teamID], f.err
}

func (f fakeSource) LsPlayers(_ context.Context, teamID string) ([]sstats.LsSquadPlayer, error) {
	if err := f.lsErrs[teamID]; err != nil {
		return nil, err
	}
	return f.lsSquads[teamID], f.err
}

type counter struct{}

func (counter) Requests() int    { return 3 }
func (counter) RateLimited() int { return 0 }

type sink struct{ msgs []any }

func (s *sink) Publish(_ context.Context, _ string, msg any) error {
	s.msgs = append(s.msgs, msg)
	return nil
}

func runCommand(t *testing.T, src Source, jobType string, payload any, batchSize int) []any {
	t.Helper()
	raw, _ := json.Marshal(payload)
	out := &sink{}
	r := &Runner{
		Registry:  DefaultRegistry(),
		Publisher: out,
		BatchSize: batchSize,
		NewSource: func() (Source, SourceCounter) { return src, counter{} },
	}
	cmd := dto.Command{
		Envelope: dto.Envelope{SchemaVersion: 1, MessageID: "m1", RunID: 7, JobType: jobType, Provider: "sstats"},
		Payload:  raw,
	}
	if err := r.Run(context.Background(), cmd); err != nil {
		t.Fatal(err)
	}

	return out.msgs
}

func strp(s string) *string { return &s }

func leaguesSource(t *testing.T) fakeSource {
	t.Helper()
	var leagues []sstats.League
	err := json.Unmarshal([]byte(`[
		{"mappings":{"flashscore":["YacqHHdS"],"pari":["11935","142242"]},"id":235,"name":"Premier League","country":{"code":"RU","name":"Russia"},"flashScoreId":"YacqHHdS",
		 "seasons":[{"uid":"s-2025","year":2025,"dateStart":"2025-07-18T00:00:00","dateEnd":"2026-05-24T00:00:00","flashScoreId":"russia/premier-league-2025-2026"},
		            {"uid":"s-2026","year":2026,"dateStart":"2026-07-25T00:00:00","dateEnd":"2026-12-07T00:00:00","flashScoreId":null},
		            {"uid":"s-2099","year":2099,"dateStart":"2099-07-25T00:00:00","dateEnd":"2099-12-07T00:00:00"},
		            {"uid":"","year":2020}]},
		{"mappings":{},"id":39,"name":"Premier League","country":{"code":"GB-ENG","name":"England"},"flashScoreId":"dYlOSQOD","seasons":[]},
		{"mappings":{},"id":651,"name":"Second League - Group 1","country":{"code":"RU","name":"Russia"},
		 "seasons":[{"uid":"g-2022","year":2022,"dateStart":"2022-07-23T00:00:00","dateEnd":"2023-06-10T00:00:00"}]},
		{"mappings":{"flashscore":["UmfIhP4H"]},"id":1025,"name":"Second League A - Division A Gold","country":{"code":"RU","name":"Russia"},"flashScoreId":"UmfIhP4H",
		 "seasons":[{"uid":"a-2025","year":2025,"dateStart":"2025-07-20T00:00:00","dateEnd":"2026-06-21T00:00:00","flashScoreId":"russia/fnl-2-division-a-gold-2025-2026"}]},
		{"mappings":{"flashscore":["UmfIhP4H"]},"id":1061,"name":"Second League A - Spring Season Gold","country":{"code":"RU","name":"Russia"},"flashScoreId":"UmfIhP4H",
		 "seasons":[{"uid":"b-2024","year":2024,"dateStart":"2025-03-02T00:00:00","dateEnd":"2025-06-28T00:00:00","flashScoreId":null}]},
		{"mappings":{"flashscore":["xGrwqq16"]},"id":2,"name":"UEFA Champions League","country":{"code":"WW","name":"World"},"flashScoreId":"xGrwqq16",
		 "seasons":[{"uid":"c-2025","year":2025,"dateStart":"2025-07-08T00:00:00","dateEnd":"2026-05-30T00:00:00"},
		            {"uid":"c-2026","year":2026,"dateStart":"2026-07-07T00:00:00","dateEnd":"2026-12-17T00:00:00"}]}
	]`), &leagues)
	if err != nil {
		t.Fatal(err)
	}

	return fakeSource{leagues: leagues}
}

func catalogEntities(t *testing.T, msgs []any) (map[string]dto.CompetitionData, map[string]dto.SeasonData, []dto.EntityError) {
	t.Helper()
	competitions, seasons := map[string]dto.CompetitionData{}, map[string]dto.SeasonData{}
	var errs []dto.EntityError
	for _, m := range msgs {
		b, ok := m.(*dto.Batch)
		if !ok {
			continue
		}
		for _, e := range b.Entities {
			switch d := e.Data.(type) {
			case dto.CompetitionData:
				if e.ExternalID != d.APIID {
					t.Fatalf("competition key must be the numeric id: %+v", e)
				}
				competitions[e.ExternalID] = d
			case dto.SeasonData:
				seasons[e.ExternalID] = d
			default:
				t.Fatalf("unexpected entity %s", e.EntityType)
			}
		}
		errs = append(errs, b.Errors...)
	}

	return competitions, seasons, errs
}

func TestSyncCatalogFromLeagues(t *testing.T) {
	msgs := runCommand(t, leaguesSource(t), dto.JobSyncCatalog, dto.CatalogPayload{
		Filters: dto.CatalogFilters{Country: "Russia"},
	}, 200)
	competitions, seasons, errs := catalogEntities(t, msgs)

	rpl := competitions["235"]
	if rpl.FlashID != "YacqHHdS" || rpl.CountryCode != "RU" || rpl.CountryName != "Russia" || rpl.Scope != dto.ScopeDomestic ||
		rpl.Refs[dto.RefFlashscore] != "YacqHHdS" || rpl.Refs[dto.RefPari] != "11935" {
		t.Fatalf("rpl = %+v", rpl)
	}
	if _, ok := competitions["39"]; ok {
		t.Fatal("country filter must drop England")
	}
	if g := competitions["651"]; g.FlashID != "" || g.Refs != nil {
		t.Fatalf("league without flash-id is kept by numeric id only: %+v", g)
	}
	if _, ok := competitions["1061"]; ok {
		t.Fatal("duplicate flash-id: the league with Flashscore seasons wins")
	}
	if _, ok := competitions["1025"]; !ok {
		t.Fatal("flash-id owner must be emitted")
	}

	cur := seasons["s-2026"]
	if cur.Label != "2026-2027" || cur.IsCurrent == nil || !*cur.IsCurrent || cur.CompetitionAPIID != "235" || cur.CompetitionExternalID != "YacqHHdS" ||
		cur.StartsOn != "2026-07-25" || cur.EndsOn != "2026-12-07" || cur.APIID != "s-2026" {
		t.Fatalf("current season = %+v", cur)
	}
	if prev := seasons["s-2025"]; prev.IsCurrent == nil || *prev.IsCurrent || prev.Label != "2025-2026" {
		t.Fatalf("previous season = %+v", prev)
	}
	if future := seasons["s-2099"]; *future.IsCurrent {
		t.Fatalf("future season is not current: %+v", future)
	}

	var dup, noUID bool
	for _, e := range errs {
		dup = dup || (e.ExternalID == "1061" && e.EntityType == dto.EntityCompetition)
		noUID = noUID || e.EntityType == dto.EntitySeason
	}
	if !dup || !noUID || len(errs) != 2 {
		t.Fatalf("errors = %+v", errs)
	}
}

func TestSyncCatalogLeagueIDsMatchNumericAndFlash(t *testing.T) {
	msgs := runCommand(t, leaguesSource(t), dto.JobSyncCatalog, dto.CatalogPayload{
		Filters: dto.CatalogFilters{LeagueIDs: []string{"dYlOSQOD", "2"}},
	}, 200)
	competitions, seasons, _ := catalogEntities(t, msgs)
	if len(competitions) != 2 || competitions["39"].CountryCode != "GB-ENG" {
		t.Fatalf("competitions = %+v", competitions)
	}
	ucl := competitions["2"]
	if ucl.Scope != dto.ScopeContinental || ucl.CountryCode != "WW" {
		t.Fatalf("ucl = %+v", ucl)
	}
	if s := seasons["c-2026"]; s.Label != "2026-2027" {
		t.Fatalf("split seasons are detected league-wide (the current one ends in its start year so far): %+v", s)
	}
}

func TestSyncCatalogRequiresFilters(t *testing.T) {
	msgs := runCommand(t, leaguesSource(t), dto.JobSyncCatalog, dto.CatalogPayload{}, 200)
	f, ok := msgs[0].(dto.Failed)
	if !ok || f.Retryable {
		t.Fatalf("expected non-retryable failed, got %#v", msgs[0])
	}
}

func TestSyncStructureResolvesLsSeasonByLabel(t *testing.T) {
	var got string
	src := fakeSource{
		gotSeason: &got,
		seasons:   map[string][]sstats.LsSeason{"jizXHcsM": {{UniqueID: "old", Years: "2025-2026"}, {UniqueID: "rXQUSf8L", Years: "2026-2027"}}},
		structure: sstats.LsSeasonStructure{Stages: []sstats.LsStage{{ID: "StG00001", Name: "Main", StatsType: "table"}}},
	}
	msgs := runCommand(t, src, dto.JobSyncStructure, dto.StructurePayload{
		Competition: dto.CompetitionRef{ID: 1, ExternalID: "jizXHcsM"}, SeasonLabel: "2026-2027",
	}, 200)
	batch := msgs[0].(*dto.Batch)
	if got != "rXQUSf8L" || batch.Entities[0].EntityType != dto.EntitySeason || batch.Entities[0].ExternalID != "rXQUSf8L" {
		t.Fatalf("season=%q entities=%+v", got, batch.Entities)
	}
	season := batch.Entities[0].Data.(dto.SeasonData)
	if season.IsCurrent != nil || season.Label != "2026-2027" || season.CompetitionExternalID != "jizXHcsM" {
		t.Fatalf("season = %+v", season)
	}
	if stage := batch.Entities[1].Data.(dto.StageData); stage.SeasonExternalID != "rXQUSf8L" {
		t.Fatalf("stage = %+v", stage)
	}

	msgs = runCommand(t, src, dto.JobSyncStructure, dto.StructurePayload{
		Competition: dto.CompetitionRef{ID: 1, ExternalID: "jizXHcsM"}, SeasonLabel: "2030-2031",
	}, 200)
	if f, ok := msgs[0].(dto.Failed); !ok || f.Retryable {
		t.Fatalf("unknown season must fail permanently: %#v", msgs[0])
	}
}

func TestSyncStructureStagesAndTeams(t *testing.T) {
	two := sstats.FlexInt(1)
	src := fakeSource{structure: sstats.LsSeasonStructure{
		Stages: []sstats.LsStage{
			{ID: "StG00001", Name: "Premier League", StatsType: "table"},
			{ID: "StG00002", Name: "Cup", StatsType: "draw", SortOrder: &two},
			{ID: "StG00003", Name: "Weird", StatsType: "???"},
		},
		Teams: []sstats.LsTeam{
			{UniqueID: "TeAm0001", Name: "Zenit", Country: &sstats.LsCountry{Num: "189", ID: "russia", Name: "Russia", Abbr2: "ru"}},
			{UniqueID: "TeAm0002", Name: "Spartak Moscow", CountryID: "189"},
		},
	}}

	msgs := runCommand(t, src, dto.JobSyncStructure, dto.StructurePayload{SeasonExternalID: "SeAs2025"}, 2)

	var batches []*dto.Batch
	for _, m := range msgs {
		if b, ok := m.(*dto.Batch); ok {
			batches = append(batches, b)
		}
	}
	if len(batches) != 3 {
		t.Fatalf("expected 3 batches for 5 entities / size 2, got %d", len(batches))
	}
	if batches[0].Entities[0].EntityType != dto.EntityCountry {
		t.Fatalf("countries must go first: %+v", batches[0].Entities)
	}
	last := batches[len(batches)-1]
	if len(last.Errors) != 1 || last.Errors[0].ExternalID != "StG00003" {
		t.Fatalf("stage error must be attached to last batch: %+v", last.Errors)
	}
	stage := batches[2].Entities[len(batches[2].Entities)-1].Data.(dto.StageData)
	if stage.StageOrder != 2 || stage.Type != dto.StageTypeKnockout || stage.HasStandings {
		t.Fatalf("stage = %+v", stage)
	}
	done := msgs[len(msgs)-1].(dto.Done)
	if done.BatchesTotal != 3 || done.EntitiesTotal != 5 {
		t.Fatalf("done = %+v", done)
	}
}

func TestSyncStructureGroupsRoundsAndStageTeams(t *testing.T) {
	flex := func(n int) *sstats.FlexInt { v := sstats.FlexInt(n); return &v }
	src := fakeSource{
		structure: sstats.LsSeasonStructure{
			Stages: []sstats.LsStage{
				{ID: "GrStage1", Name: "Group Stage", StatsType: "table", SortOrder: flex(0)},
				{ID: "KoStage1", Name: "Play Offs", StatsType: "draw", SortOrder: flex(1)},
			},
			Teams: []sstats.LsTeam{
				{UniqueID: "TeAm0001", Name: "Zenit", CountryID: "189"},
				{UniqueID: "TeAm0002", Name: "Spartak", CountryID: "189"},
			},
			StandingTables: []sstats.LsStandingTable{
				{ID: "14593", StageID: "GrStage1", Name: "Group B", SortOrder: flex(1)},
				{ID: "14592", StageID: "GrStage1", Name: "Group A", SortOrder: flex(0)},
			},
			Rounds: []sstats.LsRound{
				{ID: "1", StageID: "GrStage1", Name: "Round 1", Number: flex(1), SortOrder: flex(1), Kind: 1},
				{ID: "2", StageID: "KoStage1", Name: "Quarter-finals", SortOrder: flex(1), Kind: 2},
				{ID: "3", StageID: "Unknown", Name: "Final", SortOrder: flex(1), Kind: 2},
			},
			StageTeams: []sstats.LsStageTeam{
				{StageID: "GrStage1", TeamID: "TeAm0001"},
				{StageID: "GrStage1", TeamID: "TeAm0002"},
				{StageID: "KoStage1", TeamID: "TeAm0001"},
			},
		},
		standings: []sstats.LsStandings{
			{Table: sstats.LsStandingTable{StageID: "GrStage1", Name: "Group A"}, Rows: []sstats.LsStandingsRow{{TeamID: "TeAm0001"}}},
			{Table: sstats.LsStandingTable{StageID: "GrStage1", Name: "Group B"}, Rows: []sstats.LsStandingsRow{{TeamID: "TeAm0002"}}},
		},
	}

	msgs := runCommand(t, src, dto.JobSyncStructure, dto.StructurePayload{SeasonExternalID: "SeAs2025"}, 100)

	var groups []dto.StageGroupData
	var teams []dto.StageTeamData
	var rounds []dto.RoundData
	var stages []dto.StageData
	for _, m := range msgs {
		b, ok := m.(*dto.Batch)
		if !ok {
			continue
		}
		for _, e := range b.Entities {
			switch d := e.Data.(type) {
			case dto.StageGroupData:
				groups = append(groups, d)
			case dto.StageTeamData:
				teams = append(teams, d)
			case dto.RoundData:
				rounds = append(rounds, d)
			case dto.StageData:
				stages = append(stages, d)
			}
		}
	}

	if stages[0].Type != dto.StageTypeGroup || !stages[0].HasStandings {
		t.Fatalf("a table stage with several standing tables must be a group stage: %+v", stages[0])
	}
	if len(groups) != 2 || groups[0].Name != "Group A" || groups[0].GroupOrder != 1 || groups[1].Name != "Group B" {
		t.Fatalf("groups = %+v", groups)
	}
	if len(teams) != 3 || teams[0].GroupName != "Group A" || teams[1].GroupName != "Group B" || teams[2].GroupName != "" {
		t.Fatalf("stage teams = %+v", teams)
	}
	if len(rounds) != 2 || rounds[0].Type != dto.RoundTypeMatchday || rounds[0].Number != 1 ||
		rounds[1].Type != dto.RoundTypeKnockoutRound || rounds[1].Code != "QF" || rounds[1].Number != 1 {
		t.Fatalf("rounds = %+v", rounds)
	}
}

func TestKnockoutRoundCode(t *testing.T) {
	cases := map[string]string{
		"Final": "F", "Semi-finals": "SF", "Quarter-finals": "QF", "1/8-finals": "R16",
		"1/16-finals": "R32", "1/32-finals": "R64", "Play Offs": "",
	}
	for name, want := range cases {
		if got := knockoutRoundCode(name); got != want {
			t.Errorf("knockoutRoundCode(%q) = %q, want %q", name, got, want)
		}
	}
}

func TestUnauthorizedIsFailedNotRetryable(t *testing.T) {
	msgs := runCommand(t, fakeSource{err: sstats.ErrUnauthorized}, dto.JobSyncStructure,
		dto.StructurePayload{SeasonExternalID: "x"}, 200)
	f := msgs[0].(dto.Failed)
	if f.Retryable || f.Kind != dto.KindFailed {
		t.Fatalf("failed = %+v", f)
	}
}

func TestUnknownJobTypeFails(t *testing.T) {
	msgs := runCommand(t, fakeSource{}, "nope", struct{}{}, 200)
	if _, ok := msgs[0].(dto.Failed); !ok {
		t.Fatalf("expected failed, got %#v", msgs[0])
	}
}
