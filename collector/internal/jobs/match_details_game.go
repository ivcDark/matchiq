package jobs

import (
	"math"
	"sort"
	"strconv"
	"strings"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

// Типы событий /Games/{id}.
const (
	gameEventGoal = 1
	gameEventCard = 2
	gameEventSub  = 3
	gameEventVAR  = 4
)

// normalizeGame собирает канонические детали матча из ответа /Games/{id}. Матч (и команды) в payload названы flash-id.
// Игроки названы числовыми id источника (people_source = sstats_api) вместе с именем: сопоставление с БД делает Laravel.
// Пустой ответ (идущий матч без событий, статистики и составов) — не ошибка: в details остаётся состояние матча.
func normalizeGame(matchID string, g sstats.GameFull) (*dto.MatchDetailsData, []dto.EntityError) {
	var errs []dto.EntityError
	bad := func(msg string, ctx map[string]any) {
		errs = append(errs, dto.EntityError{Phase: dto.PhaseNormalize, Message: msg, Context: ctx})
	}

	game := g.Game
	if game.FlashID != "" && game.FlashID != matchID {
		bad("Games вернул другой матч", map[string]any{"returned_flash_id": game.FlashID})
		return nil, errs
	}
	homeKey, awayKey := game.HomeTeam.ID.String(), game.AwayTeam.ID.String()
	if homeKey == "" || awayKey == "" || homeKey == awayKey {
		bad("в Games нет id команд хозяев или гостей", nil)
		return nil, errs
	}

	// flashId команды у SStats бывает пустым: тогда команду Laravel находит по стороне (side) матча.
	teams := map[string]gameTeam{
		homeKey: {side: dto.SideHome, flashID: game.HomeTeam.FlashID},
		awayKey: {side: dto.SideAway, flashID: game.AwayTeam.FlashID},
	}

	data := &dto.MatchDetailsData{
		PeopleSource: dto.PeopleSStatsAPI,
		Teams:        []dto.MatchTeamData{},
		Lineup:       []dto.LineupEntryData{},
		Events:       []dto.MatchEventData{},
		TeamStats:    []dto.TeamStatsData{},
		PlayerStats:  []dto.PlayerMatchStatsData{},
	}
	data.State = gameState(game)

	events, eventErrs := gameEvents(g.Events, teams, data.State)
	data.Events = events
	errs = append(errs, eventErrs...)

	subIn, subOut := substitutionMinutes(g.Events)
	captains := captainIDs(g.PlayerStats)
	playerTeam := map[string]gameTeam{}
	names := map[string]string{}
	formations := map[string]string{}
	if g.Lineups != nil {
		formations[dto.SideHome] = trimmed(g.Lineups.HomeFormation)
		formations[dto.SideAway] = trimmed(g.Lineups.AwayFormation)
	}
	withLineup := map[string]bool{}

	for i, lp := range g.LineupPlayers {
		team, ok := teams[lp.TeamID.String()]
		if !ok {
			bad("команда участника состава не играет в матче", map[string]any{"team_id": lp.TeamID.String(), "name": lp.PlayerName})
			continue
		}
		id, name := lp.PlayerID.String(), strings.TrimSpace(lp.PlayerName)
		if id == "" && name == "" {
			continue
		}
		if id != "" {
			playerTeam[id] = team
			names[id] = name
		}
		withLineup[team.side] = true
		data.Lineup = append(data.Lineup, gameLineupEntry(lp, team, id, name, i, captains[id], subIn[id], subOut[id]))
	}

	for i := range data.Lineup {
		data.Lineup[i].SortOrder = i
	}

	best := bestPlayer(g.PlayerStats, playerTeam)
	for _, ps := range g.PlayerStats {
		id := anyID(ps["playerId"])
		team, ok := playerTeam[id]
		if id == "" || !ok {
			continue
		}
		data.PlayerStats = append(data.PlayerStats, gamePlayerStats(ps, id, names[id], team, best.isBest(id)))
	}

	data.TeamStats = gameTeamStats(g.Statistics, teams[homeKey], teams[awayKey])

	for _, t := range []gameTeam{teams[homeKey], teams[awayKey]} {
		if formations[t.side] == "" && !withLineup[t.side] {
			continue
		}
		data.Teams = append(data.Teams, dto.MatchTeamData{Side: t.side, TeamExternalID: t.flashID, Formation: formations[t.side]})
	}

	return data, errs
}

type gameTeam struct {
	side    string
	flashID string
}

func trimmed(s *string) string {
	if s == nil {
		return ""
	}

	return strings.TrimSpace(*s)
}

func flexIntPtr(v *sstats.FlexInt) *int {
	if v == nil {
		return nil
	}
	n := int(*v)

	return &n
}

// gameState: статус, период, минута и счёт. Неизвестное название статуса — без status (Laravel статус не меняет).
func gameState(g sstats.GameInfo) *dto.MatchStateData {
	state := &dto.MatchStateData{
		HomeGoals:   flexIntPtr(g.HomeResult),
		AwayGoals:   flexIntPtr(g.AwayResult),
		HomeGoalsHT: flexIntPtr(g.HomeHT),
		AwayGoalsHT: flexIntPtr(g.AwayHT),
	}
	state.Status, state.Period = gameStatus(g.StatusName)
	if state.Status == dto.MatchLive {
		state.Minute = flexIntPtr(g.Elapsed)
	}
	if state.Status == dto.MatchScheduled {
		state.HomeGoals, state.AwayGoals, state.HomeGoalsHT, state.AwayGoalsHT = nil, nil, nil, nil
	}

	return state
}

// gameStatus по statusName (числовые коды /Games SStats не документированы): Not Started, First Half, Halftime,
// Second Half, Extra Time…, Penalt…, Finished*, Postponed, Cancelled, Abandoned, Technical Loss.
func gameStatus(name string) (string, *string) {
	period := func(p string) *string { return &p }
	n := strings.ToLower(strings.TrimSpace(name))

	switch {
	case n == "":
		return "", nil
	case n == "not started":
		return dto.MatchScheduled, nil
	case n == "first half":
		return dto.MatchLive, period(dto.PeriodFirstHalf)
	case n == "halftime" || n == "half time":
		return dto.MatchLive, period(dto.PeriodHalfTime)
	case n == "second half":
		return dto.MatchLive, period(dto.PeriodSecondHalf)
	case strings.HasPrefix(n, "finished") || n == "technical loss":
		return dto.MatchFinished, nil
	case strings.HasPrefix(n, "extra time"):
		return dto.MatchLive, period(dto.PeriodExtraTime)
	case strings.HasPrefix(n, "penalt"):
		return dto.MatchLive, period(dto.PeriodPenalties)
	case n == "postponed":
		return dto.MatchPostponed, nil
	case n == "cancelled" || n == "canceled":
		return dto.MatchCancelled, nil
	case n == "abandoned":
		return dto.MatchAbandoned, nil
	}

	return "", nil
}

func gameEvents(events []sstats.GameEvent, teams map[string]gameTeam, state *dto.MatchStateData) ([]dto.MatchEventData, []dto.EntityError) {
	out := []dto.MatchEventData{}
	var errs []dto.EntityError

	ordered := make([]sstats.GameEvent, len(events))
	copy(ordered, events)
	sort.SliceStable(ordered, func(i, j int) bool {
		a, b := ordered[i], ordered[j]
		if a.Elapsed != b.Elapsed {
			return a.Elapsed < b.Elapsed
		}
		if ea, eb := extraOf(a), extraOf(b); ea != eb {
			return ea < eb
		}

		return eventSeq(a) < eventSeq(b)
	})

	home, away := 0, 0
	for _, e := range ordered {
		fail := func(msg string, ctx map[string]any) {
			if ctx == nil {
				ctx = map[string]any{}
			}
			ctx["source_event_id"] = e.ID.String()
			errs = append(errs, dto.EntityError{Phase: dto.PhaseNormalize, Message: msg, Context: ctx})
		}

		if int(e.Type) == gameEventVAR {
			continue
		}

		typ, ok := gameEventType(e)
		if !ok {
			fail("неизвестный тип события", map[string]any{"type": int(e.Type), "name": e.Name})
			continue
		}
		team, ok := teams[e.TeamID.String()]
		if !ok {
			fail("команда события не играет в матче", map[string]any{"team_id": e.TeamID.String()})
			continue
		}

		ev := dto.MatchEventData{
			Side:           team.side,
			TeamExternalID: team.flashID,
			Type:           typ,
			Minute:         int(e.Elapsed),
			SortOrder:      len(out),
			Details:        map[string]any{"source_event_id": e.ID.String(), "name": e.Name},
		}
		if e.Extra != nil && *e.Extra > 0 {
			added := int(*e.Extra)
			ev.AddedMinute = &added
		}
		if e.Player != nil {
			ev.PlayerExternalID, ev.PlayerName = e.Player.ID.String(), strings.TrimSpace(e.Player.Name)
		}
		if e.AssistPlayer != nil && (typ == dto.EventGoal || typ == dto.EventPenaltyGoal || typ == dto.EventSubstitution) {
			related := dto.MatchEventData{}
			related.PlayerExternalID, related.PlayerName = e.AssistPlayer.ID.String(), strings.TrimSpace(e.AssistPlayer.Name)
			if related.PlayerExternalID != ev.PlayerExternalID || related.PlayerName != ev.PlayerName {
				ev.RelatedPlayerExternalID, ev.RelatedPlayerName = related.PlayerExternalID, related.PlayerName
			}
		}

		switch typ {
		case dto.EventGoal, dto.EventPenaltyGoal, dto.EventOwnGoal:
			if team.side == dto.SideHome {
				home++
			} else {
				away++
			}
		}
		ev.ScoreHome, ev.ScoreAway = intp(home), intp(away)

		out = append(out, ev)
	}

	// Отменённые VAR голы источник оставляет в хронике, и бегущий счёт расходится с итоговым: тогда счёт у событий не пишем.
	if state == nil || state.HomeGoals == nil || state.AwayGoals == nil || *state.HomeGoals != home || *state.AwayGoals != away {
		for i := range out {
			out[i].ScoreHome, out[i].ScoreAway = nil, nil
		}
	}

	return out, errs
}

func intp(n int) *int { return &n }

func extraOf(e sstats.GameEvent) int {
	if e.Extra == nil {
		return 0
	}

	return int(*e.Extra)
}

func eventSeq(e sstats.GameEvent) int {
	n, _ := strconv.Atoi(e.ID.String())

	return n
}

// gameEventType: имена событий SStats — Normal Goal, Penalty, Own Goal, Missed Penalty, Yellow Card, Red Card, Substitution N.
func gameEventType(e sstats.GameEvent) (string, bool) {
	name := strings.ToLower(strings.TrimSpace(e.Name))

	switch int(e.Type) {
	case gameEventGoal:
		switch {
		case strings.Contains(name, "own"):
			return dto.EventOwnGoal, true
		case strings.Contains(name, "missed"):
			return dto.EventPenaltyMissed, true
		case strings.Contains(name, "penalty"):
			return dto.EventPenaltyGoal, true
		}

		return dto.EventGoal, true
	case gameEventCard:
		switch {
		case strings.Contains(name, "second yellow"):
			return dto.EventSecondYellow, true
		case strings.Contains(name, "red"):
			return dto.EventRed, true
		case strings.Contains(name, "yellow"):
			return dto.EventYellow, true
		}
	case gameEventSub:
		return dto.EventSubstitution, true
	}

	return "", false
}

// substitutionMinutes: числовой id игрока → минута выхода на поле / ухода с поля по событиям замен.
func substitutionMinutes(events []sstats.GameEvent) (in, out map[string]int) {
	in, out = map[string]int{}, map[string]int{}
	for _, e := range events {
		if int(e.Type) != gameEventSub {
			continue
		}
		minute := int(e.Elapsed) + extraOf(e)
		if e.Player != nil && e.Player.ID != "" {
			out[e.Player.ID.String()] = minute
		}
		if e.AssistPlayer != nil && e.AssistPlayer.ID != "" {
			in[e.AssistPlayer.ID.String()] = minute
		}
	}

	return in, out
}

func gamePosition(pos *string) *string {
	if pos == nil {
		return nil
	}
	p := func(v string) *string { return &v }

	switch strings.ToUpper(strings.TrimSpace(*pos)) {
	case "G", "GK":
		return p(dto.PositionGK)
	case "D", "DF":
		return p(dto.PositionDF)
	case "M", "MF":
		return p(dto.PositionMF)
	case "F", "FW", "A":
		return p(dto.PositionFW)
	}

	return nil
}

func gameLineupEntry(lp sstats.GameLineupPlayer, team gameTeam, id, name string, index int, captain bool, minuteIn, minuteOut int) dto.LineupEntryData {
	starter := lp.StartXI != nil && *lp.StartXI
	entry := dto.LineupEntryData{
		Side:             team.side,
		TeamExternalID:   team.flashID,
		PlayerExternalID: id,
		PlayerName:       name,
		IsStarter:        starter,
		IsCaptain:        captain,
		Position:         gamePosition(lp.Position),
		SortOrder:        index,
	}
	if lp.Number != nil && *lp.Number >= 0 && *lp.Number < 1000 {
		n := int(*lp.Number)
		entry.ShirtNumber = &n
	}
	// grid «ряд:место»: ряд 1 — вратарь, у Laravel линия считается от нуля.
	if starter && lp.Grid != nil {
		if row, _, ok := strings.Cut(*lp.Grid, ":"); ok {
			if n, err := strconv.Atoi(strings.TrimSpace(row)); err == nil && n >= 1 {
				line := n - 1
				entry.Line = &line
			}
		}
	}
	if !starter && minuteIn > 0 {
		entry.MinuteIn = &minuteIn
	}
	if minuteOut > 0 {
		entry.MinuteOut = &minuteOut
	}

	return entry
}

func anyID(v any) string {
	switch t := v.(type) {
	case string:
		return strings.TrimSpace(t)
	case float64:
		return strconv.FormatInt(int64(t), 10)
	}

	return ""
}

// bestRating — лучший рейтинг матча: «лучший игрок» только если он единственный.
type bestRating struct {
	id    string
	valid bool
}

func (b bestRating) isBest(id string) bool { return b.valid && b.id == id }

func captainIDs(stats []map[string]any) map[string]bool {
	captains := map[string]bool{}
	for _, s := range stats {
		if id := anyID(s["playerId"]); id != "" && s["capitan"] == true {
			captains[id] = true
		}
	}

	return captains
}

// bestPlayer — игрок с лучшим рейтингом среди участников составов; при ничьей лучшего нет.
func bestPlayer(stats []map[string]any, inLineup map[string]gameTeam) bestRating {
	var best bestRating
	top := math.Inf(-1)
	for _, s := range stats {
		id := anyID(s["playerId"])
		if _, ok := inLineup[id]; !ok {
			continue
		}
		r := anyFloat(s["rating"])
		if r == nil || *r <= 0 {
			continue
		}
		switch {
		case *r > top:
			top, best = *r, bestRating{id: id, valid: true}
		case *r == top:
			best.valid = false
		}
	}

	return best
}

var gamePlayerCoreKeys = map[string]bool{
	"playerId": true, "minutes": true, "goalsTotal": true, "goalsAssists": true, "penaltyScored": true,
	"shotsTotal": true, "shotsOn": true, "cardsYellow": true, "cardsRed": true, "rating": true, "capitan": true,
}

func gamePlayerStats(s map[string]any, id, name string, team gameTeam, motm bool) dto.PlayerMatchStatsData {
	out := dto.PlayerMatchStatsData{
		Side:             team.side,
		TeamExternalID:   team.flashID,
		PlayerExternalID: id,
		PlayerName:       name,
		Minutes:          anyInt(s["minutes"]),
		Goals:            anyInt(s["goalsTotal"]),
		Assists:          anyInt(s["goalsAssists"]),
		PenaltiesScored:  anyInt(s["penaltyScored"]),
		Shots:            anyInt(s["shotsTotal"]),
		ShotsOnTarget:    anyInt(s["shotsOn"]),
		YellowCards:      anyInt(s["cardsYellow"]),
		RedCards:         anyInt(s["cardsRed"]),
		Rating:           anyFloat(s["rating"]),
		IsMOTM:           motm,
	}

	extra := map[string]any{}
	for key, value := range s {
		if gamePlayerCoreKeys[key] {
			continue
		}
		switch v := value.(type) {
		case float64:
			extra[snakeCase(key)] = numberValue(v)
		case bool:
			if v {
				extra[snakeCase(key)] = true
			}
		}
	}
	if len(extra) > 0 {
		out.Extra = extra
	}

	return out
}

var gameTeamStatCoreKeys = map[string]bool{
	"expectedGoals": true, "totalShots": true, "shotsOnGoal": true, "cornerKicks": true, "fouls": true,
	"offsides": true, "yellowCards": true, "redCards": true, "ballPossession": true, "totalPasses": true,
	"passesAccurate": true, "goalkeeperSaves": true,
}

// gameTeamStats: плоская статистика /Games (поля «...Home» / «...Away») — одна строка full_time на команду.
// Если источник не передал ни одной метрики (идущий матч), строк нет.
func gameTeamStats(stats map[string]any, homeTeam, awayTeam gameTeam) []dto.TeamStatsData {
	if len(stats) == 0 {
		return []dto.TeamStatsData{}
	}

	metrics := func(suffix string) map[string]float64 {
		m := map[string]float64{}
		for key, value := range stats {
			if !strings.HasSuffix(key, suffix) {
				continue
			}
			if f := anyFloat(value); f != nil {
				m[strings.TrimSuffix(key, suffix)] = *f
			}
		}

		return m
	}
	home, away := metrics("Home"), metrics("Away")
	if len(home) == 0 && len(away) == 0 {
		return []dto.TeamStatsData{}
	}

	return []dto.TeamStatsData{
		gameTeamPeriod(home, away, homeTeam),
		gameTeamPeriod(away, home, awayTeam),
	}
}

func gameTeamPeriod(own, opponent map[string]float64, team gameTeam) dto.TeamStatsData {
	get := func(m map[string]float64, key string) *float64 {
		v, ok := m[key]
		if !ok {
			return nil
		}

		return &v
	}
	rounded := func(key string) *int {
		v := get(own, key)
		if v == nil {
			return nil
		}
		n := int(math.Round(*v))

		return &n
	}

	row := dto.TeamStatsData{
		Side:           team.side,
		TeamExternalID: team.flashID,
		Period:         dto.StatsFullTime,
		XG:             get(own, "expectedGoals"),
		XGA:            get(opponent, "expectedGoals"),
		Shots:          rounded("totalShots"),
		ShotsOnTarget:  rounded("shotsOnGoal"),
		Corners:        rounded("cornerKicks"),
		Fouls:          rounded("fouls"),
		Offsides:       rounded("offsides"),
		YellowCards:    rounded("yellowCards"),
		RedCards:       rounded("redCards"),
		PossessionPct:  get(own, "ballPossession"),
		Passes:         rounded("totalPasses"),
		Saves:          rounded("goalkeeperSaves"),
	}
	if total, done := get(own, "totalPasses"), get(own, "passesAccurate"); total != nil && done != nil && *total > 0 {
		acc := math.Round(*done / *total * 10000) / 100
		row.PassAccuracyPct = &acc
	}

	extra := map[string]any{}
	for key, v := range own {
		if gameTeamStatCoreKeys[key] {
			continue
		}
		extra[snakeCase(key)] = numberValue(v)
	}
	if v := get(own, "passesAccurate"); v != nil {
		extra["completed_passes"] = numberValue(*v)
	}
	if len(extra) > 0 {
		row.Extra = extra
	}

	return row
}
