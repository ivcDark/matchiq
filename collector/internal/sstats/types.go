package sstats

import (
	"bytes"
	"encoding/json"
	"strconv"
	"time"
)

// FlexString принимает JSON-строку или число (SStats отдаёт id в обоих видах).
type FlexString string

func (f *FlexString) UnmarshalJSON(b []byte) error {
	b = bytes.TrimSpace(b)
	if string(b) == "null" {
		*f = ""
		return nil
	}
	if len(b) > 0 && b[0] == '"' {
		var s string
		if err := json.Unmarshal(b, &s); err != nil {
			return err
		}
		*f = FlexString(s)
		return nil
	}
	var n json.Number
	if err := json.Unmarshal(b, &n); err != nil {
		return err
	}
	*f = FlexString(n.String())
	return nil
}

func (f FlexString) String() string { return string(f) }

// FlexInt принимает число или числовую строку.
type FlexInt int

func (f *FlexInt) UnmarshalJSON(b []byte) error {
	var s FlexString
	if err := s.UnmarshalJSON(b); err != nil {
		return err
	}
	if s == "" {
		*f = 0
		return nil
	}
	n, err := strconv.Atoi(string(s))
	if err != nil {
		return err
	}
	*f = FlexInt(n)
	return nil
}

type envelope[T any] struct {
	Status  string `json:"status"`
	Message string `json:"message"`
	Data    T      `json:"data"`
}

type LsCountry struct {
	Num   FlexString `json:"num"`
	ID    string     `json:"id"`
	Name  string     `json:"name"`
	Abbr2 string     `json:"abbr2"`
	Abbr3 string     `json:"abbr3"`
}

type LsLeague struct {
	ID      string    `json:"id"`
	UID     string    `json:"uid"`
	Name    string    `json:"name"`
	AliasID string    `json:"aliasId"`
	LogoURL string    `json:"logoUrl"`
	Country LsCountry `json:"country"`
}

type LsSeason struct {
	UniqueID        string `json:"uniqueId"`
	TournamentID    string `json:"tournamentId"`
	IsCurrentSeason bool   `json:"isCurrentSeason"`
	Years           string `json:"years"`
}

type LsTeam struct {
	UniqueID  string     `json:"uniqueId"`
	Name      string     `json:"name"`
	ShortName string     `json:"shortName"`
	LogoURL   string     `json:"logoUrl"`
	CountryID FlexString `json:"countryId"`
	Country   *LsCountry `json:"country"`
}

type LsStage struct {
	ID        string   `json:"id"`
	SeasonID  string   `json:"seasonId"`
	Name      string   `json:"name"`
	StatsType string   `json:"statsType"`
	SortOrder *FlexInt `json:"sortOrder"`
}

// LsRound — тур или раунд стадии (kind 1 — тур таблицы, kind 2 — раунд плей-офф).
type LsRound struct {
	ID        FlexString `json:"id"`
	StageID   string     `json:"stageId"`
	Name      string     `json:"name"`
	Number    *FlexInt   `json:"number"`
	SortOrder *FlexInt   `json:"sortOrder"`
	Kind      FlexInt    `json:"kind"`
}

// LsStandingTable — таблица стадии; несколько таблиц на стадию — это группы.
type LsStandingTable struct {
	ID        FlexString `json:"id"`
	StageID   string     `json:"stageId"`
	Name      string     `json:"name"`
	SortOrder *FlexInt   `json:"sortOrder"`
}

type LsStageTeam struct {
	StageID string `json:"stageId"`
	TeamID  string `json:"teamId"`
}

type LsSeasonStructure struct {
	Season         LsSeason          `json:"season"`
	Stages         []LsStage         `json:"stages"`
	Teams          []LsTeam          `json:"teams"`
	Rounds         []LsRound         `json:"rounds"`
	StandingTables []LsStandingTable `json:"standingTables"`
	StageTeams     []LsStageTeam     `json:"stageTeams"`
}

// LsStandings — таблица стадии из /Ls/Standings: состав группы задаётся строками таблицы.
type LsStandings struct {
	Table LsStandingTable  `json:"table"`
	Rows  []LsStandingsRow `json:"rows"`
}

type LsStandingsRow struct {
	TeamID string `json:"teamId"`
}

// LsFixture — матч из /Ls/List (используются только нужные каноническому DTO поля).
type LsFixture struct {
	ID           string     `json:"id"`
	TournamentID string     `json:"tournamentId"`
	SeasonID     string     `json:"seasonId"`
	StageID      string     `json:"stageId"`
	RoundID      FlexString `json:"roundId"`
	RoundName    string     `json:"roundName"`
	HomeTeamID   string     `json:"homeTeamId"`
	AwayTeamID   string     `json:"awayTeamId"`
	Date         string     `json:"date"`
	DateUTC      FlexString `json:"dateUtc"`
	Status       FlexInt    `json:"status"`
	StatusName   string     `json:"statusName"`
	LiveStage    *FlexInt   `json:"liveStage"`

	HomeResult   *FlexInt `json:"homeResult"`
	AwayResult   *FlexInt `json:"awayResult"`
	HomeResultHT *FlexInt `json:"homeResultHT"`
	AwayResultHT *FlexInt `json:"awayResultHT"`
	HomeResultFT *FlexInt `json:"homeResultFT"`
	AwayResultFT *FlexInt `json:"awayResultFT"`
	HomeResultET *FlexInt `json:"homeResultET"`
	AwayResultET *FlexInt `json:"awayResultET"`
	HomeResultAP *FlexInt `json:"homeResultAP"`
	AwayResultAP *FlexInt `json:"awayResultAP"`
}

// FixturesQuery — фильтры /Ls/List. Нулевые значения не отправляются.
type FixturesQuery struct {
	SeasonID string
	StageID  string
	// From включительно, To исключительно (UTC).
	From time.Time
	To   time.Time
}

// FlexFloat принимает число или числовую строку; пустое значение даёт 0.
type FlexFloat float64

func (f *FlexFloat) UnmarshalJSON(b []byte) error {
	var s FlexString
	if err := s.UnmarshalJSON(b); err != nil {
		return err
	}
	if s == "" {
		*f = 0
		return nil
	}
	n, err := strconv.ParseFloat(string(s), 64)
	if err != nil {
		return err
	}
	*f = FlexFloat(n)
	return nil
}

// LsGameInfo — /Ls/GameInfo: события и командная статистика матча (нужные каноническому DTO разделы).
type LsGameInfo struct {
	Game       LsGameRef                `json:"game"`
	Events     []LsEvent                `json:"events"`
	Statistics map[string]LsPeriodStats `json:"statistics"`
}

type LsGameRef struct {
	ID         string  `json:"id"`
	HomeTeamID string  `json:"homeTeamId"`
	AwayTeamID string  `json:"awayTeamId"`
	Status     FlexInt `json:"status"`
}

// LsEvent — событие summary: одно или несколько действий в одну минуту.
type LsEvent struct {
	TeamID         string     `json:"teamId"`
	SourceEventID  string     `json:"sourceEventId"`
	Period         *FlexInt   `json:"period"`
	SortOrder      *FlexInt   `json:"sortOrder"`
	HomeScoreAfter *FlexInt   `json:"homeScoreAfter"`
	AwayScoreAfter *FlexInt   `json:"awayScoreAfter"`
	Time           FlexInt    `json:"time"`
	Minute         *FlexInt   `json:"minute"`
	InjuryTime     FlexInt    `json:"injuryTime"`
	PenaltyAttempt *FlexInt   `json:"penaltyAttempt"`
	Team           FlexInt    `json:"team"`
	Actions        []LsAction `json:"actions"`
}

type LsAction struct {
	PlayerUniqueID string   `json:"playerUniqueId"`
	PlayerName     string   `json:"playerName"`
	ActionType     FlexInt  `json:"actionType"`
	ActionName     string   `json:"actionName"`
	Reason         string   `json:"reason"`
	ReasonCode     *FlexInt `json:"reasonCode"`
	Comment        string   `json:"comment"`
}

// LsPair — значение командной метрики для хозяев и гостей (nil — источник не передал).
type LsPair struct {
	Home *FlexFloat `json:"home"`
	Away *FlexFloat `json:"away"`
}

// LsPeriodStats — командная статистика одного периода: именованные метрики {home, away} и otherStats.
type LsPeriodStats struct {
	Metrics    map[string]LsPair
	OtherStats map[string]map[string]string
}

func (p *LsPeriodStats) UnmarshalJSON(b []byte) error {
	var raw map[string]json.RawMessage
	if err := json.Unmarshal(b, &raw); err != nil {
		return err
	}
	p.Metrics = make(map[string]LsPair, len(raw))
	p.OtherStats = nil
	for key, value := range raw {
		switch key {
		case "metrics":
			continue
		case "otherStats":
			var other map[string]map[string]string
			if err := json.Unmarshal(value, &other); err == nil {
				p.OtherStats = other
			}
			continue
		}
		var pair LsPair
		if err := json.Unmarshal(value, &pair); err != nil {
			continue
		}
		if pair.Home != nil || pair.Away != nil {
			p.Metrics[key] = pair
		}
	}

	return nil
}

// LsGameLineups — /Ls/GameLineups: составы со статистикой игроков и профили людей.
type LsGameLineups struct {
	Lineups []LsLineup        `json:"lineups"`
	Players []LsPlayerProfile `json:"players"`
}

type LsLineup struct {
	TeamID        string           `json:"teamId"`
	Side          FlexInt          `json:"side"`
	Formation     *string          `json:"formation"`
	AverageRating *FlexFloat       `json:"averageRating"`
	Players       []LsLineupPlayer `json:"players"`
}

// Kind участника состава (LsDetailsMappers).
const (
	LineupStarter       = 0
	LineupSubstitute    = 1
	LineupCoach         = 2
	LineupMissing       = 3
	LineupUnsureMissing = 4
)

type LsLineupPlayer struct {
	PlayerID      string         `json:"playerId"`
	PlayerName    string         `json:"playerName"`
	Kind          FlexInt        `json:"kind"`
	ShirtNumber   *string        `json:"shirtNumber"`
	IsCaptain     bool           `json:"isCaptain"`
	IsGoalkeeper  *bool          `json:"isGoalkeeper"`
	Position      *string        `json:"position"`
	FormationLine *FlexInt       `json:"formationLine"`
	FormationRow  *FlexInt       `json:"formationRow"`
	SortOrder     *FlexInt       `json:"sortOrder"`
	SubInTime     *string        `json:"subInTime"`
	SubOutTime    *string        `json:"subOutTime"`
	Statistics    map[string]any `json:"statistics"`
}

type LsPlayerProfile struct {
	ID        string     `json:"id"`
	Name      string     `json:"name"`
	ShortName string     `json:"shortName"`
	Role      string     `json:"role"`
	Birthdate string     `json:"birthdate"`
	PhotoURL  string     `json:"photoUrl"`
	Country   *LsCountry `json:"country"`
}

// LsSquadPlayer — запись /Ls/Players (каталог людей): игрок или тренер текущей команды профиля.
// ProfileUpdatedAt == nil — полный профиль ещё не получен (человек известен только из матчей).
type LsSquadPlayer struct {
	ID               string     `json:"id"`
	Name             string     `json:"name"`
	ShortName        *string    `json:"shortName"`
	Role             *string    `json:"role"`
	Birthdate        *string    `json:"birthdate"`
	PhotoURL         *string    `json:"photoUrl"`
	Country          *LsCountry `json:"country"`
	CurrentTeamID    *string    `json:"currentTeamId"`
	ProfileUpdatedAt *string    `json:"profileUpdatedAt"`
}
