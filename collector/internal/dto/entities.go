package dto

import "encoding/json"

const (
	EntityCountry     = "country"
	EntityCompetition = "competition"
	EntitySeason      = "season"
	EntityStage       = "stage"
	EntityTeam        = "team"
	EntityMatch       = "match"
)

// EntityRank задаёт порядок зависимостей: справочники → сезоны → стадии/команды → матчи.
var EntityRank = map[string]int{
	EntityCountry:     1,
	EntityCompetition: 2,
	EntitySeason:      3,
	EntityTeam:        4,
	EntityStage:       5,
	EntityMatch:       6,
}

// Значения enum'ов совпадают с Laravel (CountryKind, CompetitionType, CompetitionScope, StageType).
const (
	CountryKindCountry = "country"
	CountryKindRegion  = "region"

	CompetitionTypeLeague = "league"

	ScopeDomestic    = "domestic"
	ScopeContinental = "continental"

	StageTypeLeague   = "league"
	StageTypeKnockout = "knockout"
)

type CountryData struct {
	Code string `json:"code"`
	Name string `json:"name"`
	Kind string `json:"kind"`
}

type CompetitionData struct {
	CountryExternalID string `json:"country_external_id,omitempty"`
	Name              string `json:"name"`
	Type              string `json:"type"`
	Scope             string `json:"scope"`
	LogoURL           string `json:"logo_url,omitempty"`
}

type SeasonData struct {
	CompetitionExternalID string `json:"competition_external_id"`
	Label                 string `json:"label"`
	IsCurrent             bool   `json:"is_current"`
}

type StageData struct {
	SeasonExternalID string `json:"season_external_id"`
	Name             string `json:"name"`
	Type             string `json:"type"`
	StageOrder       int    `json:"stage_order"`
	HasStandings     bool   `json:"has_standings"`
}

type TeamData struct {
	SeasonExternalID  string `json:"season_external_id,omitempty"`
	CountryExternalID string `json:"country_external_id,omitempty"`
	Name              string `json:"name"`
	ShortName         string `json:"short_name,omitempty"`
	LogoURL           string `json:"logo_url,omitempty"`
}

// CatalogPayload — payload sync_catalog.
type CatalogPayload struct {
	Filters CatalogFilters  `json:"filters"`
	Context json.RawMessage `json:"context,omitempty"`
}

type CatalogFilters struct {
	Country    string   `json:"country"`
	LeagueName string   `json:"league_name"`
	LeagueIDs  []string `json:"league_ids"`
}

func (f CatalogFilters) Empty() bool {
	return f.Country == "" && f.LeagueName == "" && len(f.LeagueIDs) == 0
}

// StructurePayload — payload sync_structure.
type StructurePayload struct {
	Competition      CompetitionRef  `json:"competition"`
	SeasonExternalID string          `json:"season_external_id"`
	Context          json.RawMessage `json:"context,omitempty"`
}

type CompetitionRef struct {
	ID         int64  `json:"id"`
	ExternalID string `json:"external_id"`
}

// Значения enum'ов совпадают с Laravel (MatchStatus, MatchPeriod).
const (
	MatchScheduled = "scheduled"
	MatchLive      = "live"
	MatchFinished  = "finished"
	MatchPostponed = "postponed"
	MatchCancelled = "cancelled"
	MatchAbandoned = "abandoned"

	PeriodFirstHalf  = "first_half"
	PeriodSecondHalf = "second_half"
	PeriodExtraTime  = "extra_time"
	PeriodPenalties  = "penalties"
)

// MatchData — канонический матч. Ссылки — только внешние id источника; счёт nil, пока неизвестен.
type MatchData struct {
	CompetitionExternalID string  `json:"competition_external_id,omitempty"`
	SeasonExternalID      string  `json:"season_external_id"`
	StageExternalID       string  `json:"stage_external_id"`
	RoundName             string  `json:"round_name,omitempty"`
	RoundNumber           *int    `json:"round_number"`
	HomeTeamExternalID    string  `json:"home_team_external_id"`
	AwayTeamExternalID    string  `json:"away_team_external_id"`
	KickoffAt             string  `json:"kickoff_at"`
	Status                string  `json:"status"`
	Period                *string `json:"period"`
	HomeGoals             *int    `json:"home_goals"`
	AwayGoals             *int    `json:"away_goals"`
	HomeGoalsHT           *int    `json:"home_goals_ht"`
	AwayGoalsHT           *int    `json:"away_goals_ht"`
	HomeGoalsET           *int    `json:"home_goals_et"`
	AwayGoalsET           *int    `json:"away_goals_et"`
	HomePens              *int    `json:"home_pens"`
	AwayPens              *int    `json:"away_pens"`
}

// FixturesPayload — payload sync_fixtures. Окно дат — включительно с обеих сторон (UTC, YYYY-MM-DD).
type FixturesPayload struct {
	Competition      CompetitionRef  `json:"competition"`
	SeasonExternalID string          `json:"season_external_id"`
	StageExternalID  string          `json:"stage_external_id,omitempty"`
	Window           *FixturesWindow `json:"window,omitempty"`
	Context          json.RawMessage `json:"context,omitempty"`
}

type FixturesWindow struct {
	From string `json:"from,omitempty"`
	To   string `json:"to,omitempty"`
}
