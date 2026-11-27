package dto

import "encoding/json"

const (
	EntityCountry     = "country"
	EntityCompetition = "competition"
	EntitySeason      = "season"
	EntityStage       = "stage"
	EntityStageGroup  = "stage_group"
	EntityStageTeam   = "stage_team"
	EntityRound       = "round"
	EntityTeam        = "team"
	EntityMatch       = "match"
	EntityPlayer      = "player"
	EntityCoach       = "coach"
	// EntityMatchDetails — составы, события и статистика одного матча (дочерние сущности внутри data).
	EntityMatchDetails = "match_details"
	// EntitySquad — актуальный состав клуба: external_id = внешний id команды, в data — внешние id игроков.
	EntitySquad = "squad"
	// EntityExternalRefs — дополнительные идентификаторы уже известной сущности (числовой id SStats, flashscore, pari).
	// external_id = id сущности у провайдера sstats (flash-id), в data — тип сущности и ids по кодам провайдеров.
	EntityExternalRefs = "external_refs"
)

// Коды провайдеров идентификаторов в external_ids (совпадают с source_providers.code в Laravel).
const (
	RefSstatsAPI  = "sstats_api"
	RefFlashscore = "flashscore"
	RefPari       = "pari"
)

// EntityRank задаёт порядок зависимостей: справочники → сезоны → стадии/команды → матчи.
var EntityRank = map[string]int{
	EntityCountry:      1,
	EntityCompetition:  2,
	EntitySeason:       3,
	EntityTeam:         4,
	EntityStage:        5,
	EntityStageGroup:   6,
	EntityRound:        6,
	EntityStageTeam:    7,
	EntityMatch:        8,
	EntityPlayer:       9,
	EntityCoach:        9,
	EntityMatchDetails: 10,
	EntitySquad:        10,
	EntityExternalRefs: 11,
}

// Значения enum'ов совпадают с Laravel (CountryKind, CompetitionType, CompetitionScope, StageType).
const (
	CountryKindCountry = "country"
	CountryKindRegion  = "region"

	CompetitionTypeLeague = "league"

	ScopeDomestic    = "domestic"
	ScopeContinental = "continental"

	StageTypeLeague   = "league"
	StageTypeGroup    = "group"
	StageTypeKnockout = "knockout"

	RoundTypeMatchday      = "matchday"
	RoundTypeKnockoutRound = "knockout_round"
)

type CountryData struct {
	Code string `json:"code"`
	Name string `json:"name"`
	Kind string `json:"kind"`
}

// CompetitionData — соревнование из /Leagues. APIID — числовой id SStats (провайдер sstats_api); FlashID — flash-id,
// он же ключ Ls-эндпоинтов (провайдер sstats), у части лиг пуст. Страна задаётся кодом и названием основного API.
// Refs — справочные id (flashscore, pari).
type CompetitionData struct {
	APIID       string            `json:"api_id"`
	FlashID     string            `json:"flash_id,omitempty"`
	CountryCode string            `json:"country_code,omitempty"`
	CountryName string            `json:"country_name,omitempty"`
	Name        string            `json:"name"`
	Type        string            `json:"type"`
	Scope       string            `json:"scope"`
	LogoURL     string            `json:"logo_url,omitempty"`
	Refs        map[string]string `json:"refs,omitempty"`
}

// SeasonData — сезон соревнования. Из /Leagues: APIID (uuid, провайдер sstats_api), CompetitionAPIID, даты, IsCurrent.
// Из Ls (sync_structure, когда у сезона ещё нет Ls-id): competition_external_id и label, IsCurrent = nil — не трогать.
type SeasonData struct {
	APIID                 string `json:"api_id,omitempty"`
	CompetitionExternalID string `json:"competition_external_id,omitempty"`
	CompetitionAPIID      string `json:"competition_api_id,omitempty"`
	Label                 string `json:"label"`
	IsCurrent             *bool  `json:"is_current,omitempty"`
	StartsOn              string `json:"starts_on,omitempty"`
	EndsOn                string `json:"ends_on,omitempty"`
}

type StageData struct {
	SeasonExternalID string `json:"season_external_id"`
	Name             string `json:"name"`
	Type             string `json:"type"`
	StageOrder       int    `json:"stage_order"`
	HasStandings     bool   `json:"has_standings"`
}

// StageGroupData — группа стадии (одна из нескольких таблиц стадии).
type StageGroupData struct {
	StageExternalID string `json:"stage_external_id"`
	Name            string `json:"name"`
	GroupOrder      int    `json:"group_order"`
}

// StageTeamData — участие команды в стадии; GroupName пуст, если стадия без групп.
type StageTeamData struct {
	StageExternalID string `json:"stage_external_id"`
	TeamExternalID  string `json:"team_external_id"`
	GroupName       string `json:"group_name,omitempty"`
}

// RoundData — тур или раунд стадии. Code — канонический код раунда плей-офф (R32, QF, F, …), если распознан.
type RoundData struct {
	StageExternalID string `json:"stage_external_id"`
	Number          int    `json:"number"`
	Name            string `json:"name"`
	Type            string `json:"type"`
	Code            string `json:"code,omitempty"`
}

// TeamData — команда. Из Ls-структуры (sync_structure): ключ — flash-id, страна — id страны Ls. Из /Games/list
// (sync_fixtures): APIID — числовой id SStats (ключ, провайдер sstats_api), FlashID может быть пуст, страна — кодом.
type TeamData struct {
	APIID             string            `json:"api_id,omitempty"`
	FlashID           string            `json:"flash_id,omitempty"`
	SeasonExternalID  string            `json:"season_external_id,omitempty"`
	CountryExternalID string            `json:"country_external_id,omitempty"`
	CountryCode       string            `json:"country_code,omitempty"`
	CountryName       string            `json:"country_name,omitempty"`
	Name              string            `json:"name"`
	ShortName         string            `json:"short_name,omitempty"`
	LogoURL           string            `json:"logo_url,omitempty"`
	Refs              map[string]string `json:"refs,omitempty"`
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

// StructurePayload — payload sync_structure. Сезон без Ls-id (заведён из /Leagues) задаётся SeasonLabel: collector
// находит его Ls-id через /Ls/Seasons по flash-id соревнования.
type StructurePayload struct {
	Competition      CompetitionRef  `json:"competition"`
	SeasonExternalID string          `json:"season_external_id"`
	SeasonLabel      string          `json:"season_label,omitempty"`
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
	PeriodHalfTime   = "half_time"
	PeriodSecondHalf = "second_half"
	PeriodExtraTime  = "extra_time"
	PeriodPenalties  = "penalties"
)

// MatchData — канонический матч из /Games/list. Каждая ссылка — парой id: flash-id (провайдер sstats, может быть пуст)
// и числовой id / uuid SStats (провайдер sstats_api); Laravel ищет по любому. Этап и id тура /Games/list не отдаёт:
// stage/round_external_id пусты, если их не дал запасной /Ls/List. Счёт доп. времени не передаётся, пенальти — только
// из уже запрошенного /Ls/List (ключей нет — Laravel их не трогает). Refs — справочные id матча (flashscore, pari).
type MatchData struct {
	APIID              string            `json:"api_id"`
	FlashID            string            `json:"flash_id,omitempty"`
	SeasonExternalID   string            `json:"season_external_id,omitempty"`
	SeasonAPIID        string            `json:"season_api_id,omitempty"`
	StageExternalID    string            `json:"stage_external_id,omitempty"`
	RoundExternalID    string            `json:"round_external_id,omitempty"`
	RoundName          string            `json:"round_name,omitempty"`
	RoundNumber        *int              `json:"round_number"`
	HomeTeamExternalID string            `json:"home_team_external_id,omitempty"`
	AwayTeamExternalID string            `json:"away_team_external_id,omitempty"`
	HomeTeamAPIID      string            `json:"home_team_api_id"`
	AwayTeamAPIID      string            `json:"away_team_api_id"`
	HomeTeamName       string            `json:"home_team_name,omitempty"`
	AwayTeamName       string            `json:"away_team_name,omitempty"`
	KickoffAt          string            `json:"kickoff_at"`
	Status             string            `json:"status"`
	Period             *string           `json:"period"`
	HomeGoals          *int              `json:"home_goals"`
	AwayGoals          *int              `json:"away_goals"`
	HomeGoalsHT        *int              `json:"home_goals_ht"`
	AwayGoalsHT        *int              `json:"away_goals_ht"`
	HomePens           *int              `json:"home_pens,omitempty"`
	AwayPens           *int              `json:"away_pens,omitempty"`
	Refs               map[string]string `json:"refs,omitempty"`
	// Odds — доматчевые коэффициенты закрытия линии; пусто — Laravel сохранённые не трогает.
	Odds []MatchOddData `json:"odds,omitempty"`
}

// MatchOddData — коэффициент исхода рынка SStats (marketId: 1 — 1X2, 5 — тотал 2.5 и т.д.; outcome — как в источнике).
type MatchOddData struct {
	MarketID   int     `json:"market_id"`
	MarketName string  `json:"market_name,omitempty"`
	Outcome    string  `json:"outcome"`
	Value      float64 `json:"value"`
}

// FixturesPayload — payload sync_fixtures. Окно дат — включительно с обеих сторон (UTC, YYYY-MM-DD).
// Сезон для /Games/list — SeasonAPIID (uuid) либо LeagueAPIID + SeasonYear. LsStages — сезон многоэтапный: этап и тур
// матчей добрать из /Ls/List по SeasonExternalID (Ls-id). /Ls/List запрашивается и без LsStages, если у команды матча
// в /Games/list нет flashId.
type FixturesPayload struct {
	Competition      CompetitionRef  `json:"competition"`
	SeasonExternalID string          `json:"season_external_id,omitempty"`
	SeasonAPIID      string          `json:"season_api_id,omitempty"`
	LeagueAPIID      string          `json:"league_api_id,omitempty"`
	SeasonYear       int             `json:"season_year,omitempty"`
	StageExternalID  string          `json:"stage_external_id,omitempty"`
	LsStages         bool            `json:"ls_stages,omitempty"`
	Window           *FixturesWindow `json:"window,omitempty"`
	Context          json.RawMessage `json:"context,omitempty"`
}

type FixturesWindow struct {
	From string `json:"from,omitempty"`
	To   string `json:"to,omitempty"`
}

// ExternalRefsData — идентификаторы сущности в других источниках. EntityType — match, team или competition.
type ExternalRefsData struct {
	EntityType string            `json:"entity_type"`
	Refs       map[string]string `json:"refs"`
}
