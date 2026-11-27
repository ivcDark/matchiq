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

	// PeopleSStatsAPI: игроки в деталях матча названы числовыми id /Games (а не flash-id); Laravel сопоставляет их с игроками БД.
	PeopleSStatsAPI = "sstats_api"
)

// MatchDetailsPayload — payload sync_match_details. Матчи выбирает Laravel (фильтры competition/season/stage/limit),
// collector получает готовый список внешних id.
type MatchDetailsPayload struct {
	Competition      CompetitionRef `json:"competition"`
	SeasonExternalID string         `json:"season_external_id,omitempty"`
	MatchExternalIDs []string       `json:"match_external_ids"`
	// MatchAPIIDs: flash-id матча → числовой id SStats (external_ids провайдера sstats_api) для GET /Games/{id}.
	// Матча нет в карте — запрашиваем по flash-id (эндпоинт принимает оба).
	MatchAPIIDs map[string]string `json:"match_api_ids,omitempty"`
	Context     json.RawMessage   `json:"context,omitempty"`
}

// PlayerData — канонический игрок. TeamExternalID — команда, откуда игрок взят (запасная страна, если у профиля её нет;
// у people_source = sstats_api — ещё и круг поиска по имени среди уже известных игроков).
type PlayerData struct {
	// PeopleSource = sstats_api: external_id — числовой id /Players (а не flash-id провайдера sstats).
	PeopleSource string `json:"people_source,omitempty"`
	// FlashID — flash-id того же человека в Ls (если сопоставлен): связь с игроками, заведёнными раньше из Ls.
	FlashID           string `json:"flash_id,omitempty"`
	Name              string `json:"name"`
	ShortName         string `json:"short_name,omitempty"`
	FirstName         string `json:"first_name,omitempty"`
	LastName          string `json:"last_name,omitempty"`
	CountryExternalID string `json:"country_external_id,omitempty"`
	// Nationality — английское название страны (основной API не отдаёт id страны игрока).
	Nationality    string `json:"nationality,omitempty"`
	TeamExternalID string `json:"team_external_id,omitempty"`
	Position       string `json:"position,omitempty"`
	BirthDate      string `json:"birth_date,omitempty"`
	HeightCM       *int   `json:"height_cm,omitempty"`
	PhotoURL       string `json:"photo_url,omitempty"`
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
	// PeopleSource: чьими идентификаторами названы игроки в lineup/events/player_stats (пусто — flash-id провайдера sstats).
	PeopleSource string                 `json:"people_source,omitempty"`
	State        *MatchStateData        `json:"state,omitempty"`
	Teams        []MatchTeamData        `json:"teams"`
	Lineup       []LineupEntryData      `json:"lineup"`
	Events       []MatchEventData       `json:"events"`
	TeamStats    []TeamStatsData        `json:"team_stats"`
	PlayerStats  []PlayerMatchStatsData `json:"player_stats"`
}

// MatchStateData — текущее состояние матча из шапки /Games/{id}. Пустые (null) поля Laravel не трогает.
// Minute заполняется у идущего матча; у остальных статусов Laravel его сбрасывает.
type MatchStateData struct {
	Status      string  `json:"status,omitempty"`
	Period      *string `json:"period,omitempty"`
	Minute      *int    `json:"minute"`
	HomeGoals   *int    `json:"home_goals"`
	AwayGoals   *int    `json:"away_goals"`
	HomeGoalsHT *int    `json:"home_goals_ht"`
	AwayGoalsHT *int    `json:"away_goals_ht"`
	// Голы доп. времени: счёт матча (до серии пенальти) минус счёт основного времени; только у матчей с доп. временем.
	HomeGoalsET *int `json:"home_goals_et,omitempty"`
	AwayGoalsET *int `json:"away_goals_et,omitempty"`
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
	Side             string  `json:"side,omitempty"`
	TeamExternalID   string  `json:"team_external_id"`
	PlayerExternalID string  `json:"player_external_id"`
	PlayerName       string  `json:"player_name,omitempty"`
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
	Side                    string         `json:"side,omitempty"`
	TeamExternalID          string         `json:"team_external_id,omitempty"`
	PlayerExternalID        string         `json:"player_external_id,omitempty"`
	RelatedPlayerExternalID string         `json:"related_player_external_id,omitempty"`
	PlayerName              string         `json:"player_name,omitempty"`
	RelatedPlayerName       string         `json:"related_player_name,omitempty"`
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
	Side            string         `json:"side,omitempty"`
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
	Side             string         `json:"side,omitempty"`
	TeamExternalID   string         `json:"team_external_id"`
	PlayerExternalID string         `json:"player_external_id"`
	PlayerName       string         `json:"player_name,omitempty"`
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

// SquadsPayload — payload sync_squads. Команды выбирает Laravel (команды сезона соревнования или явный список),
// collector получает готовый список внешних id команд.
type SquadsPayload struct {
	Competition      CompetitionRef `json:"competition"`
	SeasonExternalID string         `json:"season_external_id,omitempty"`
	// TeamExternalIDs — числовые id команд SStats (ключ /Players/list).
	TeamExternalIDs []string `json:"team_external_ids"`
	// TeamFlashIDs: числовой id → flash-id команды для запасного /Ls/Players (только позиция игрока).
	TeamFlashIDs map[string]string `json:"team_flash_ids,omitempty"`
	Context      json.RawMessage   `json:"context,omitempty"`
}

// SquadData — актуальный состав команды. Команда и игроки названы числовыми id SStats (people_source = sstats_api);
// сами игроки идут отдельными сущностями player.
type SquadData struct {
	PeopleSource   string            `json:"people_source,omitempty"`
	TeamExternalID string            `json:"team_external_id"`
	Players        []SquadPlayerData `json:"players"`
}

// SquadPlayerData — игрок состава. Absences — текущие травмы и дисквалификации (unavailabilities /Players/list);
// nil — источник ничего не сообщил.
type SquadPlayerData struct {
	PlayerExternalID string        `json:"player_external_id"`
	Position         string        `json:"position,omitempty"`
	Absences         []AbsenceData `json:"absences,omitempty"`
}

// AbsenceData — отсутствие игрока. Type — enum Laravel AbsenceType (injury, suspension, doubtful); Reason — typeName источника.
type AbsenceData struct {
	Type           string `json:"type"`
	Reason         string `json:"reason,omitempty"`
	Since          string `json:"since,omitempty"`
	ExpectedReturn string `json:"expected_return,omitempty"`
}

// Значения enum'а Laravel AbsenceType.
const (
	AbsenceInjury     = "injury"
	AbsenceSuspension = "suspension"
	AbsenceDoubtful   = "doubtful"
)
