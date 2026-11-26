package dto

import "encoding/json"

const (
	EntityCountry     = "country"
	EntityCompetition = "competition"
	EntitySeason      = "season"
	EntityStage       = "stage"
	EntityTeam        = "team"
)

// EntityRank задаёт порядок зависимостей: справочники → сезоны → стадии/команды.
var EntityRank = map[string]int{
	EntityCountry:     1,
	EntityCompetition: 2,
	EntitySeason:      3,
	EntityTeam:        4,
	EntityStage:       5,
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
	Filters CatalogFilters `json:"filters"`
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
	Competition      CompetitionRef `json:"competition"`
	SeasonExternalID string         `json:"season_external_id"`
	Context          json.RawMessage `json:"context,omitempty"`
}

type CompetitionRef struct {
	ID         int64  `json:"id"`
	ExternalID string `json:"external_id"`
}
