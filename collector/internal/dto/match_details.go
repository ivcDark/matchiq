package dto

import "encoding/json"

// Значения enum'ов совпадают с Laravel (MatchEventType, PlayerPosition, StatsPeriod, MatchSide).
const (
	EventGoal          = "goal"
	EventOwnGoal       = "own_goal"
	EventPenaltyGoal   = "penalty_goal"
	EventPenaltyMissed = "penalty_missed"
	EventYellow        = "yellow"
	EventSecondYellow  = "second_yellow"
	EventRed           = "red"
	EventSubstitution  = "substitution"

	PositionGK = "GK"
	PositionDF = "DF"
	PositionMF = "MF"
	PositionFW = "FW"

	StatsFullTime   = "full_time"
	StatsFirstHalf  = "first_half"
	StatsSecondHalf = "second_half"

	SideHome = "home"
	SideAway = "away"
)

// MatchDetailsPayload — payload sync_match_details. Матчи выбирает Laravel (фильтры competition/season/stage/limit),
// collector получает готовый список внешних id.
type MatchDetailsPayload struct {
	Competition      CompetitionRef  `json:"competition"`
	SeasonExternalID string          `json:"season_external_id,omitempty"`
	MatchExternalIDs []string        `json:"match_external_ids"`
	Context          json.RawMessage `json:"context,omitempty"`
}

// PlayerData — канонический игрок. TeamExternalID — команда матча, откуда игрок взят (запасная страна, если у профиля её нет).
type PlayerData struct {
	Name              string `json:"name"`
	ShortName         string `json:"short_name,omitempty"`
	CountryExternalID string `json:"country_external_id,omitempty"`
	TeamExternalID    string `json:"team_external_id,omitempty"`
	Position          string `json:"position,omitempty"`
	BirthDate         string `json:"birth_date,omitempty"`
	PhotoURL          string `json:"photo_url,omitempty"`
	// HasProfile: данные взяты из профиля источника. Без профиля (только имя из состава) Laravel не затирает уже известные поля.
	HasProfile bool `json:"has_profile"`
}

// CoachData — канонический тренер.
type CoachData struct {
	Name              string `json:"name"`
	CountryExternalID string `json:"country_external_id,omitempty"`
	TeamExternalID    string `json:"team_external_id,omitempty"`
	BirthDate         string `json:"birth_date,omitempty"`
	HasProfile        bool   `json:"has_profile"`
}

// MatchDetailsData — детали одного матча. Любая секция может быть пустой («источник не отдал»):
// пустая секция в Laravel ничего не удаляет.
type MatchDetailsData struct {
	Teams       []MatchTeamData        `json:"teams"`
	Lineup      []LineupEntryData      `json:"lineup"`
	Events      []MatchEventData       `json:"events"`
	TeamStats   []TeamStatsData        `json:"team_stats"`
	PlayerStats []PlayerMatchStatsData `json:"player_stats"`
}

// MatchTeamData — команда в матче: схема, средняя оценка состава и тренер.
type MatchTeamData struct {
	Side            string   `json:"side"`
	TeamExternalID  string   `json:"team_external_id"`
	CoachExternalID string   `json:"coach_external_id,omitempty"`
	Formation       string   `json:"formation,omitempty"`
	Rating          *float64 `json:"rating"`
}

// LineupEntryData — игрок в заявке на матч (стартер или запасной). Line — номер линии расстановки от вратаря
// (0 — вратарь), только у стартеров; координат x/y источник не отдаёт.
type LineupEntryData struct {
	TeamExternalID   string  `json:"team_external_id"`
	PlayerExternalID string  `json:"player_external_id"`
	IsStarter        bool    `json:"is_starter"`
	IsCaptain        bool    `json:"is_captain"`
	ShirtNumber      *int    `json:"shirt_number"`
	Position         *string `json:"position"`
	Line             *int    `json:"line"`
	SortOrder        int     `json:"sort_order"`
	MinuteIn         *int    `json:"minute_in"`
	MinuteOut        *int    `json:"minute_out"`
}

// MatchEventData — событие хроники. TeamExternalID — команда, которой засчитан эпизод (у автогола — получившая гол).
// PlayerExternalID — автор (при замене — ушедший), RelatedPlayerExternalID — ассистент (при замене — вышедший).
type MatchEventData struct {
	TeamExternalID          string         `json:"team_external_id,omitempty"`
	PlayerExternalID        string         `json:"player_external_id,omitempty"`
	RelatedPlayerExternalID string         `json:"related_player_external_id,omitempty"`
	Type                    string         `json:"type"`
	Minute                  int            `json:"minute"`
	AddedMinute             *int           `json:"added_minute"`
	ScoreHome               *int           `json:"score_home"`
	ScoreAway               *int           `json:"score_away"`
	SortOrder               int            `json:"sort_order"`
	Details                 map[string]any `json:"details,omitempty"`
}

// TeamStatsData — командная статистика за период. XGA — xG соперника. Всё, что не вошло в колонки, — в Extra.
type TeamStatsData struct {
	TeamExternalID  string         `json:"team_external_id"`
	Period          string         `json:"period"`
	XG              *float64       `json:"xg"`
	XGA             *float64       `json:"xga"`
	Shots           *int           `json:"shots"`
	ShotsOnTarget   *int           `json:"shots_on_target"`
	Corners         *int           `json:"corners"`
	Fouls           *int           `json:"fouls"`
	Offsides        *int           `json:"offsides"`
	YellowCards     *int           `json:"yellow_cards"`
	RedCards        *int           `json:"red_cards"`
	PossessionPct   *float64       `json:"possession_pct"`
	Passes          *int           `json:"passes"`
	PassAccuracyPct *float64       `json:"pass_accuracy_pct"`
	AerialsWon      *int           `json:"aerials_won"`
	Saves           *int           `json:"saves"`
	Extra           map[string]any `json:"extra,omitempty"`
}

// PlayerMatchStatsData — статистика игрока за матч; xGOT, прогрессивные действия и т.п. — в Extra.
// IsMOTM — отметка источника «лучший рейтинг матча».
type PlayerMatchStatsData struct {
	TeamExternalID   string         `json:"team_external_id"`
	PlayerExternalID string         `json:"player_external_id"`
	Minutes          *int           `json:"minutes"`
	Goals            *int           `json:"goals"`
	Assists          *int           `json:"assists"`
	PenaltiesScored  *int           `json:"penalties_scored"`
	Shots            *int           `json:"shots"`
	ShotsOnTarget    *int           `json:"shots_on_target"`
	XG               *float64       `json:"xg"`
	XA               *float64       `json:"xa"`
	YellowCards      *int           `json:"yellow_cards"`
	RedCards         *int           `json:"red_cards"`
	Rating           *float64       `json:"rating"`
	IsMOTM           bool           `json:"is_motm"`
	Extra            map[string]any `json:"extra,omitempty"`
}
