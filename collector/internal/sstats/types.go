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

// FlexStrings принимает строку или массив строк (mappings команд и лиг — массивы, матча — строка).
type FlexStrings []string

func (f *FlexStrings) UnmarshalJSON(b []byte) error {
	b = bytes.TrimSpace(b)
	if string(b) == "null" {
		*f = nil
		return nil
	}
	if len(b) > 0 && b[0] == '[' {
		var list []FlexString
		if err := json.Unmarshal(b, &list); err != nil {
			return err
		}
		out := make([]string, 0, len(list))
		for _, v := range list {
			out = append(out, v.String())
		}
		*f = out
		return nil
	}
	var s FlexString
	if err := s.UnmarshalJSON(b); err != nil {
		return err
	}
	if s == "" {
		*f = nil
		return nil
	}
	*f = FlexStrings{s.String()}
	return nil
}

// First — первое непустое значение.
func (f FlexStrings) First() string {
	for _, v := range f {
		if v != "" {
			return v
		}
	}

	return ""
}

// GameRef — матч из /Games/list: числовой id SStats и идентификаторы в других источниках (mappings).
type GameRef struct {
	ID       FlexString             `json:"id"`
	FlashID  string                 `json:"flashId"`
	Mappings map[string]FlexStrings `json:"mappings"`
	Home     GameTeamRef            `json:"homeTeam"`
	Away     GameTeamRef            `json:"awayTeam"`
	Season   struct {
		League GameLeagueRef `json:"league"`
	} `json:"season"`
}

type GameTeamRef struct {
	ID       FlexString             `json:"id"`
	FlashID  string                 `json:"flashId"`
	Mappings map[string]FlexStrings `json:"mappings"`
}

type GameLeagueRef struct {
	ID           FlexString             `json:"id"`
	FlashScoreID string                 `json:"flashScoreId"`
	Mappings     map[string]FlexStrings `json:"mappings"`
}

// GameFull — /Games/{id}: матч целиком (счёт, статус, события, статистика, составы). Любой раздел может быть null.
type GameFull struct {
	Game          GameInfo           `json:"game"`
	Statistics    map[string]any     `json:"statistics"`
	Lineups       *GameLineups       `json:"lineups"`
	LineupPlayers []GameLineupPlayer `json:"lineupPlayers"`
	PlayerStats   []map[string]any   `json:"playerStats"`
	Events        []GameEvent        `json:"events"`
}

// GameInfo — шапка матча /Games/{id}. Числовые id команд (events/lineupPlayers ссылаются на них); flashId команд — ключ ingest.
type GameInfo struct {
	ID         FlexString  `json:"id"`
	FlashID    string      `json:"flashId"`
	Status     *FlexInt    `json:"status"`
	StatusName string      `json:"statusName"`
	Elapsed    *FlexInt    `json:"elapsed"`
	HomeResult *FlexInt    `json:"homeResult"`
	AwayResult *FlexInt    `json:"awayResult"`
	HomeHT     *FlexInt    `json:"homeHTResult"`
	AwayHT     *FlexInt    `json:"awayHTResult"`
	HomeTeam   GameTeamRef `json:"homeTeam"`
	AwayTeam   GameTeamRef `json:"awayTeam"`
}

type GameLineups struct {
	HomeFormation *string `json:"homeFormation"`
	AwayFormation *string `json:"awayFormation"`
}

type GamePerson struct {
	ID   FlexString `json:"id"`
	Name string     `json:"name"`
}

// GameEvent — событие хроники: type 1 гол, 2 карточка, 3 замена (player — ушёл, assistPlayer — вышел), 4 решение VAR.
type GameEvent struct {
	ID           FlexString  `json:"id"`
	TeamID       FlexString  `json:"teamId"`
	Elapsed      FlexInt     `json:"elapsed"`
	Extra        *FlexInt    `json:"extra"`
	Type         FlexInt     `json:"type"`
	Name         string      `json:"name"`
	Player       *GamePerson `json:"player"`
	AssistPlayer *GamePerson `json:"assistPlayer"`
}

type GameLineupPlayer struct {
	TeamID     FlexString `json:"teamId"`
	PlayerID   FlexString `json:"playerId"`
	PlayerName string     `json:"playerName"`
	Number     *FlexInt   `json:"number"`
	Position   *string    `json:"position"`
	Grid       *string    `json:"grid"`
	StartXI    *bool      `json:"startXI"`
}
