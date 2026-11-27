package jobs

import (
	"context"
	"encoding/json"
	"testing"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

type fakeSource struct {
	leagues   []sstats.LsLeague
	seasons   map[string][]sstats.LsSeason
	structure sstats.LsSeasonStructure
	standings []sstats.LsStandings
	fixtures  []sstats.LsFixture
	gotQuery  *sstats.FixturesQuery
	err       error

	info       map[string]sstats.LsGameInfo
	lineups    map[string]sstats.LsGameLineups
	detailsErr map[string]error

	squads    map[string][]sstats.LsSquadPlayer
	squadErrs map[string]error
}

func (f fakeSource) Leagues(context.Context, string) ([]sstats.LsLeague, error) {
	return f.leagues, f.err
}
func (f fakeSource) Seasons(_ context.Context, id string) ([]sstats.LsSeason, error) {
	return f.seasons[id], f.err
}
func (f fakeSource) SeasonStructure(context.Context, string) (sstats.LsSeasonStructure, error) {
	return f.structure, f.err
}
func (f fakeSource) Standings(context.Context, string) ([]sstats.LsStandings, error) {
	return f.standings, f.err
}

func (f fakeSource) Fixtures(_ context.Context, q sstats.FixturesQuery) ([]sstats.LsFixture, error) {
	if f.gotQuery != nil {
		*f.gotQuery = q
	}
	return f.fixtures, f.err
}

func (f fakeSource) GameInfo(_ context.Context, id string) (sstats.LsGameInfo, error) {
	if err := f.detailsErr[id]; err != nil {
		return sstats.LsGameInfo{}, err
	}
	return f.info[id], f.err
}

func (f fakeSource) GameLineups(_ context.Context, id string) (sstats.LsGameLineups, error) {
	return f.lineups[id], f.err
}

func (f fakeSource) Players(_ context.Context, teamID string) ([]sstats.LsSquadPlayer, error) {
	if err := f.squadErrs[teamID]; err != nil {
		return nil, err
	}
	return f.squads[teamID], f.err
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

func rplSource() fakeSource {
	ru := sstats.LsCountry{Num: "189", ID: "russia", Name: "Russia", Abbr2: "ru"}

	return fakeSource{
		leagues: []sstats.LsLeague{
			{ID: "AbCd1234", Name: "Premier League", Country: ru},
			{ID: "ZzZz9999", Name: "Premier League", Country: sstats.LsCountry{Num: "198", ID: "england", Name: "England", Abbr2: "gb-eng"}},
			{ID: "FNL00001", Name: "FNL", Country: ru},
		},
		seasons: map[string][]sstats.LsSeason{
			"AbCd1234": {{UniqueID: "SeAs2025", TournamentID: "AbCd1234", Years: "2025-2026", IsCurrentSeason: true}, {UniqueID: "", Years: "x"}},
		},
	}
}

func TestSyncCatalogFiltersAndNormalizes(t *testing.T) {
	msgs := runCommand(t, rplSource(), dto.JobSyncCatalog, dto.CatalogPayload{
		Filters: dto.CatalogFilters{Country: "Russia", LeagueName: "premier"},
	}, 200)

	batch := msgs[0].(*dto.Batch)
	if batch.Kind != dto.KindBatch || batch.Seq != 1 {
		t.Fatalf("unexpected batch: %+v", batch)
	}
	types := []string{}
	for _, e := range batch.Entities {
		types = append(types, e.EntityType)
	}
	want := []string{"country", "competition", "season"}
	if len(types) != 3 || types[0] != want[0] || types[1] != want[1] || types[2] != want[2] {
		t.Fatalf("entities = %v, want %v", types, want)
	}
	if len(batch.Errors) != 1 || batch.Errors[0].Phase != dto.PhaseNormalize {
		t.Fatalf("expected one normalize error, got %+v", batch.Errors)
	}
	country := batch.Entities[0].Data.(dto.CountryData)
	if country.Code != "RU" || country.Kind != dto.CountryKindCountry {
		t.Fatalf("country = %+v", country)
	}

	done := msgs[len(msgs)-1].(dto.Done)
	if done.BatchesTotal != 1 || done.EntitiesTotal != 3 || done.ErrorsTotal != 1 || done.Source.Requests != 3 {
		t.Fatalf("done = %+v", done)
	}
}

func TestSyncCatalogRequiresFilters(t *testing.T) {
	msgs := runCommand(t, rplSource(), dto.JobSyncCatalog, dto.CatalogPayload{}, 200)
	f, ok := msgs[0].(dto.Failed)
	if !ok || f.Retryable {
		t.Fatalf("expected non-retryable failed, got %#v", msgs[0])
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

func TestSyncCatalogMarksContinentalLeaguesAsRegion(t *testing.T) {
	src := fakeSource{
		leagues: []sstats.LsLeague{
			{ID: "UcL00001", Name: "Champions League", Country: sstats.LsCountry{Num: "6", ID: "europe", Name: "Europe", Abbr2: "ww", Abbr3: "EUR"}},
		},
		seasons: map[string][]sstats.LsSeason{
			"UcL00001": {{UniqueID: "SeAs2026", TournamentID: "UcL00001", Years: "2026-2027", IsCurrentSeason: true}},
		},
	}
	msgs := runCommand(t, src, dto.JobSyncCatalog, dto.CatalogPayload{
		Filters: dto.CatalogFilters{LeagueIDs: []string{"UcL00001"}},
	}, 200)

	batch := msgs[0].(*dto.Batch)
	country := batch.Entities[0].Data.(dto.CountryData)
	if country.Code != "EUR" || country.Kind != dto.CountryKindRegion {
		t.Fatalf("country = %+v", country)
	}
	competition := batch.Entities[1].Data.(dto.CompetitionData)
	if competition.Scope != dto.ScopeContinental {
		t.Fatalf("scope = %q, want continental", competition.Scope)
	}
}
