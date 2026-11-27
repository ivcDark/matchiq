package jobs

import (
	"math"
	"sort"
	"strings"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

// Коды действий Flashfootball, встречающиеся в событиях summary (проверены на матчах РПЛ).
const (
	actYellow         = 1
	actRed            = 2
	actGoal           = 3
	actOwnGoal        = 4
	actPenaltyAwarded = 5
	actSubOut         = 6
	actSubIn          = 7
	actAssist         = 8
	actPenaltyGoal    = 10
	actPenaltyMissed  = 11
	actNotOnPitch     = 47
)

// periodPenalties — серия пенальти: не хроника матча, в match_events не пишется.
const periodPenalties = 4

func normalizeEvents(
	events []sstats.LsEvent,
	sides map[int]matchSide,
	coachIDs map[string]bool,
	profiles map[string]*sstats.LsPlayerProfile,
	reg *peopleRegistry,
) ([]dto.MatchEventData, []dto.EntityError) {
	out := []dto.MatchEventData{}
	var errs []dto.EntityError

	ordered := make([]sstats.LsEvent, len(events))
	copy(ordered, events)
	sort.SliceStable(ordered, func(i, j int) bool { return eventOrder(ordered, i) < eventOrder(ordered, j) })

	opposite := func(teamID string) string {
		if teamID == sides[1].teamID {
			return sides[2].teamID
		}
		if teamID == sides[2].teamID {
			return sides[1].teamID
		}

		return ""
	}

	for idx, e := range ordered {
		if e.Period != nil && int(*e.Period) == periodPenalties {
			continue
		}
		fail := func(msg string, ctx map[string]any) {
			if ctx == nil {
				ctx = map[string]any{}
			}
			ctx["source_event_id"] = e.SourceEventID
			errs = append(errs, dto.EntityError{Phase: dto.PhaseNormalize, Message: msg, Context: ctx})
		}

		byCode := map[int]*sstats.LsAction{}
		codes := make([]int, 0, len(e.Actions))
		for i := range e.Actions {
			code := int(e.Actions[i].ActionType)
			if _, dup := byCode[code]; !dup {
				byCode[code] = &e.Actions[i]
				codes = append(codes, code)
			}
		}
		sort.Ints(codes)

		var typ string
		var main, related *sstats.LsAction
		switch {
		case byCode[actPenaltyGoal] != nil:
			typ, main, related = dto.EventPenaltyGoal, byCode[actPenaltyGoal], byCode[actAssist]
		case byCode[actPenaltyMissed] != nil:
			typ, main = dto.EventPenaltyMissed, byCode[actPenaltyMissed]
		case byCode[actOwnGoal] != nil:
			typ, main = dto.EventOwnGoal, byCode[actOwnGoal]
		case byCode[actGoal] != nil:
			typ, main, related = dto.EventGoal, byCode[actGoal], byCode[actAssist]
		case byCode[actRed] != nil && byCode[actYellow] != nil:
			typ, main = dto.EventSecondYellow, byCode[actRed]
		case byCode[actRed] != nil:
			typ, main = dto.EventRed, byCode[actRed]
		case byCode[actYellow] != nil:
			typ, main = dto.EventYellow, byCode[actYellow]
		case byCode[actSubOut] != nil || byCode[actSubIn] != nil:
			typ, main, related = dto.EventSubstitution, byCode[actSubOut], byCode[actSubIn]
		default:
			if onlyCodes(codes, actPenaltyAwarded, actNotOnPitch, actAssist) {
				continue
			}
			fail("неизвестный тип события", map[string]any{"action_codes": codes})
			continue
		}

		teamID := e.TeamID
		if teamID == "" {
			teamID = sides[int(e.Team)].teamID
		}
		playerTeam := teamID
		if typ == dto.EventOwnGoal {
			playerTeam = opposite(teamID)
		}

		details := map[string]any{}
		if e.SourceEventID != "" {
			details["source_event_id"] = e.SourceEventID
		}
		reasonSrc := main
		if byCode[actYellow] != nil && typ != dto.EventSecondYellow {
			reasonSrc = byCode[actYellow]
		}
		if reasonSrc != nil {
			if reasonSrc.Reason != "" {
				details["reason"] = reasonSrc.Reason
			}
			if reasonSrc.ReasonCode != nil {
				details["reason_code"] = int(*reasonSrc.ReasonCode)
			}
			if reasonSrc.Comment != "" {
				details["comment"] = reasonSrc.Comment
			}
		}

		ev := dto.MatchEventData{
			TeamExternalID: teamID,
			Type:           typ,
			Minute:         int(e.Time),
			SortOrder:      idx,
			Details:        details,
		}
		if e.Minute != nil {
			ev.Minute = int(*e.Minute)
		}
		if e.InjuryTime > 0 {
			added := int(e.InjuryTime)
			ev.AddedMinute = &added
		}
		if e.SortOrder != nil {
			ev.SortOrder = int(*e.SortOrder)
		}
		ev.ScoreHome, ev.ScoreAway = intPtr(e.HomeScoreAfter), intPtr(e.AwayScoreAfter)

		if byCode[actNotOnPitch] != nil {
			details["not_on_pitch"] = true
			if main != nil && main.PlayerName != "" {
				details["person_name"] = main.PlayerName
			}
		} else {
			if main != nil {
				id, ok := registerEventPlayer(main, playerTeam, coachIDs, profiles, reg, details)
				if !ok {
					fail("у события нет опознанного игрока", map[string]any{"type": typ})
					continue
				}
				ev.PlayerExternalID = id
			}
			if related != nil {
				id, ok := registerEventPlayer(related, teamID, coachIDs, profiles, reg, nil)
				if !ok {
					fail("у события нет опознанного второго игрока", map[string]any{"type": typ})
					continue
				}
				ev.RelatedPlayerExternalID = id
			}
		}

		out = append(out, ev)
	}

	return out, errs
}

func eventOrder(events []sstats.LsEvent, i int) int {
	if events[i].SortOrder != nil {
		return int(*events[i].SortOrder)
	}

	return i
}

func onlyCodes(codes []int, allowed ...int) bool {
	for _, c := range codes {
		found := false
		for _, a := range allowed {
			if c == a {
				found = true
				break
			}
		}
		if !found {
			return false
		}
	}

	return true
}

// eventPlayerID — устойчивый id человека (Players.Id); playerId в действии — путь «slug/ID», его не используем.
func eventPlayerID(a *sstats.LsAction) string {
	return strings.TrimSpace(a.PlayerUniqueID)
}

// registerEventPlayer регистрирует игрока события. Тренер/не опознанный человек: id пустой, имя — в details.
func registerEventPlayer(
	a *sstats.LsAction,
	teamID string,
	coachIDs map[string]bool,
	profiles map[string]*sstats.LsPlayerProfile,
	reg *peopleRegistry,
	details map[string]any,
) (string, bool) {
	id := eventPlayerID(a)
	if id == "" {
		return "", false
	}
	if coachIDs[id] {
		if details != nil {
			details["not_on_pitch"] = true
			details["person_name"] = a.PlayerName
		}

		return "", true
	}
	if !reg.player(id, a.PlayerName, teamID, "", profiles[id]) {
		return "", false
	}

	return id, true
}

var teamStatPeriods = []struct{ key, name string }{
	{"fullTime", dto.StatsFullTime},
	{"firstHalf", dto.StatsFirstHalf},
	{"secondHalf", dto.StatsSecondHalf},
}

var teamStatCoreKeys = map[string]bool{
	"expectedGoals": true, "totalShots": true, "shotsOnTarget": true, "cornerKicks": true, "fouls": true,
	"offsides": true, "yellowCards": true, "redCards": true, "ballPossession": true, "totalPasses": true,
	"completedPasses": true, "goalkeeperSaves": true,
}

// teamStats строит строки командной статистики по периодам. xga — xG соперника за тот же период.
func teamStats(stats map[string]sstats.LsPeriodStats, homeTeamID, awayTeamID string) []dto.TeamStatsData {
	out := []dto.TeamStatsData{}
	for _, period := range teamStatPeriods {
		ps, ok := stats[period.key]
		if !ok || len(ps.Metrics) == 0 {
			continue
		}
		for _, side := range []struct {
			teamID string
			home   bool
		}{{homeTeamID, true}, {awayTeamID, false}} {
			out = append(out, teamPeriodStats(ps, period.name, side.teamID, side.home))
		}
	}

	return out
}

func pick(p sstats.LsPair, home bool) *float64 {
	v := p.Away
	if home {
		v = p.Home
	}
	if v == nil {
		return nil
	}
	f := float64(*v)
	if math.IsNaN(f) || math.IsInf(f, 0) {
		return nil
	}

	return &f
}

func teamPeriodStats(ps sstats.LsPeriodStats, period, teamID string, home bool) dto.TeamStatsData {
	metric := func(key string) *float64 { return pick(ps.Metrics[key], home) }
	rounded := func(key string) *int {
		v := metric(key)
		if v == nil {
			return nil
		}
		n := int(math.Round(*v))

		return &n
	}

	row := dto.TeamStatsData{
		TeamExternalID: teamID,
		Period:         period,
		XG:             metric("expectedGoals"),
		XGA:            pick(ps.Metrics["expectedGoals"], !home),
		Shots:          rounded("totalShots"),
		ShotsOnTarget:  rounded("shotsOnTarget"),
		Corners:        rounded("cornerKicks"),
		Fouls:          rounded("fouls"),
		Offsides:       rounded("offsides"),
		YellowCards:    rounded("yellowCards"),
		RedCards:       rounded("redCards"),
		PossessionPct:  metric("ballPossession"),
		Passes:         rounded("totalPasses"),
		Saves:          rounded("goalkeeperSaves"),
	}
	if total, done := metric("totalPasses"), metric("completedPasses"); total != nil && done != nil && *total > 0 {
		acc := math.Round(*done / *total * 10000) / 100
		row.PassAccuracyPct = &acc
	}

	extra := map[string]any{}
	for key := range ps.Metrics {
		if teamStatCoreKeys[key] {
			continue
		}
		if v := metric(key); v != nil {
			extra[snakeCase(key)] = numberValue(*v)
		}
	}
	if v := metric("completedPasses"); v != nil {
		extra["completed_passes"] = numberValue(*v)
	}
	other := map[string]string{}
	for label, pair := range ps.OtherStats {
		side := "away"
		if home {
			side = "home"
		}
		if v, ok := pair[side]; ok && v != "" {
			other[label] = v
		}
	}
	if len(other) > 0 {
		extra["other_stats"] = other
	}
	if len(extra) > 0 {
		row.Extra = extra
	}

	return row
}
