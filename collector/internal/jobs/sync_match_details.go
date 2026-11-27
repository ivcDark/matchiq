package jobs

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"net/http"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
	"unicode"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

// maxMatchDetailsPerRun защищает от случайного «весь сезон» одной командой (2 запроса к источнику на матч).
const maxMatchDetailsPerRun = 500

// SyncMatchDetails: составы, события и статистика матчей по списку внешних id.
// Эндпоинты: /Ls/GameInfo (события, командная статистика) и /Ls/GameLineups (составы, статистика игроков, профили).
type SyncMatchDetails struct{}

func (SyncMatchDetails) Collect(ctx context.Context, src Source, cmd dto.Command) (*Output, error) {
	var p dto.MatchDetailsPayload
	if err := json.Unmarshal(cmd.Payload, &p); err != nil {
		return nil, &PermanentError{Msg: "invalid sync_match_details payload: " + err.Error()}
	}

	ids := uniqueIDs(p.MatchExternalIDs)
	if len(ids) == 0 {
		return nil, &PermanentError{Msg: "sync_match_details requires match_external_ids"}
	}
	if len(ids) > maxMatchDetailsPerRun {
		return nil, &PermanentError{Msg: fmt.Sprintf("sync_match_details: не более %d матчей за прогон, получено %d", maxMatchDetailsPerRun, len(ids))}
	}

	out := &Output{}
	reg := newPeopleRegistry()
	var details []dto.Entity

	for _, id := range ids {
		info, err := src.GameInfo(ctx, id)
		if err != nil {
			if fatalFetch(err) {
				return nil, err
			}
			out.AddError(fetchError(id, "GameInfo", err))
			continue
		}
		lineups, err := src.GameLineups(ctx, id)
		if err != nil {
			if fatalFetch(err) {
				return nil, err
			}
			out.AddError(fetchError(id, "GameLineups", err))
			continue
		}

		data, errs := normalizeMatchDetails(id, info, lineups, reg)
		for _, e := range errs {
			e.EntityType, e.ExternalID = dto.EntityMatchDetails, id
			out.AddError(e)
		}
		if data != nil {
			details = append(details, dto.Entity{EntityType: dto.EntityMatchDetails, ExternalID: id, Data: *data})
		}
	}

	for _, e := range reg.entities() {
		out.Add(e)
	}
	for _, e := range details {
		out.Add(e)
	}

	return out, nil
}

// fatalFetch — ошибки, после которых продолжать бессмысленно: ключ, лимит запросов, отмена.
func fatalFetch(err error) bool {
	var se *sstats.StatusError
	if errors.As(err, &se) && se.Code == http.StatusTooManyRequests {
		return true
	}

	return errors.Is(err, sstats.ErrUnauthorized) || errors.Is(err, sstats.ErrRateLimited) ||
		errors.Is(err, context.Canceled) || errors.Is(err, context.DeadlineExceeded)
}

func fetchError(id, endpoint string, err error) dto.EntityError {
	return dto.EntityError{
		EntityType: dto.EntityMatchDetails,
		ExternalID: id,
		Phase:      dto.PhaseFetch,
		Message:    "не удалось получить " + endpoint + ": " + err.Error(),
		Context:    map[string]any{"endpoint": endpoint},
	}
}

func uniqueIDs(in []string) []string {
	seen := make(map[string]bool, len(in))
	out := make([]string, 0, len(in))
	for _, id := range in {
		id = strings.TrimSpace(id)
		if id == "" || seen[id] {
			continue
		}
		seen[id] = true
		out = append(out, id)
	}

	return out
}

// peopleRegistry собирает игроков, тренеров и страны, на которые ссылаются составы, события и статистика, без дублей.
type peopleRegistry struct {
	players   map[string]dto.Entity
	coaches   map[string]dto.Entity
	countries map[string]dto.Entity
	order     []string
}

func newPeopleRegistry() *peopleRegistry {
	return &peopleRegistry{
		players:   map[string]dto.Entity{},
		coaches:   map[string]dto.Entity{},
		countries: map[string]dto.Entity{},
	}
}

func (r *peopleRegistry) entities() []dto.Entity {
	var out []dto.Entity
	for _, id := range sortedKeys(r.countries) {
		out = append(out, r.countries[id])
	}
	for _, id := range sortedKeys(r.coaches) {
		out = append(out, r.coaches[id])
	}
	for _, id := range sortedKeys(r.players) {
		out = append(out, r.players[id])
	}

	return out
}

func sortedKeys(m map[string]dto.Entity) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)

	return keys
}

func (r *peopleRegistry) country(c *sstats.LsCountry) string {
	if c == nil {
		return ""
	}
	e, ok := countryEntity(*c)
	if !ok {
		return ""
	}
	if _, seen := r.countries[e.ExternalID]; !seen {
		r.countries[e.ExternalID] = e
	}

	return e.ExternalID
}

func (r *peopleRegistry) player(id, fallbackName, teamID, positionHint string, profile *sstats.LsPlayerProfile) bool {
	if seen, ok := r.players[id]; ok && (profile == nil || seen.Data.(dto.PlayerData).HasProfile) {
		return true
	}
	data := dto.PlayerData{TeamExternalID: teamID, Position: positionHint, HasProfile: profile != nil}
	if profile != nil {
		data.Name = strings.TrimSpace(profile.Name)
		data.ShortName = strings.TrimSpace(profile.ShortName)
		data.CountryExternalID = r.country(profile.Country)
		data.BirthDate = validDate(profile.Birthdate)
		data.PhotoURL = absoluteURL(profile.PhotoURL)
		if pos := profilePosition(profile.Role); pos != "" {
			data.Position = pos
		}
	}
	if data.Name == "" {
		data.Name = strings.TrimSpace(fallbackName)
	}
	if data.Name == "" {
		return false
	}
	r.players[id] = dto.Entity{EntityType: dto.EntityPlayer, ExternalID: id, Data: data}

	return true
}

func (r *peopleRegistry) coach(id, fallbackName, teamID string, profile *sstats.LsPlayerProfile) bool {
	if seen, ok := r.coaches[id]; ok && (profile == nil || seen.Data.(dto.CoachData).HasProfile) {
		return true
	}
	data := dto.CoachData{TeamExternalID: teamID, HasProfile: profile != nil}
	if profile != nil {
		data.Name = strings.TrimSpace(profile.Name)
		data.CountryExternalID = r.country(profile.Country)
		data.BirthDate = validDate(profile.Birthdate)
	}
	if data.Name == "" {
		data.Name = strings.TrimSpace(fallbackName)
	}
	if data.Name == "" {
		return false
	}
	r.coaches[id] = dto.Entity{EntityType: dto.EntityCoach, ExternalID: id, Data: data}

	return true
}

func validDate(raw string) string {
	raw = strings.TrimSpace(raw)
	if len(raw) >= 10 {
		raw = raw[:10]
	}
	if _, err := time.Parse(dateLayout, raw); err != nil {
		return ""
	}

	return raw
}

func profilePosition(role string) string {
	switch strings.ToLower(strings.TrimSpace(role)) {
	case "goalkeeper":
		return dto.PositionGK
	case "defender":
		return dto.PositionDF
	case "midfielder":
		return dto.PositionMF
	case "forward":
		return dto.PositionFW
	}

	return ""
}

// lineupPosition сводит матчевые позиции SStats (Centre back, Winger, Striker…) к GK/DF/MF/FW.
func lineupPosition(position *string, isGoalkeeper *bool) *string {
	pos := func(p string) *string { return &p }
	if isGoalkeeper != nil && *isGoalkeeper {
		return pos(dto.PositionGK)
	}
	if position == nil {
		return nil
	}
	switch strings.ToLower(strings.TrimSpace(*position)) {
	case "goalkeeper":
		return pos(dto.PositionGK)
	case "defender", "centre back", "center back", "fullback", "full back", "wingback", "wing back", "sweeper":
		return pos(dto.PositionDF)
	case "midfielder", "defensive midfielder", "attacking midfielder", "central midfielder", "winger", "wide midfielder":
		return pos(dto.PositionMF)
	case "forward", "striker", "centre forward", "center forward", "second striker":
		return pos(dto.PositionFW)
	}

	return nil
}

type matchSide struct {
	side   string
	teamID string
}

// normalizeMatchDetails собирает канонические детали матча. nil, если источник не отдал ничего пригодного.
func normalizeMatchDetails(matchID string, info sstats.LsGameInfo, lineups sstats.LsGameLineups, reg *peopleRegistry) (*dto.MatchDetailsData, []dto.EntityError) {
	var errs []dto.EntityError
	bad := func(msg string, ctx map[string]any) {
		errs = append(errs, dto.EntityError{Phase: dto.PhaseNormalize, Message: msg, Context: ctx})
	}

	game := info.Game
	if game.ID != "" && game.ID != matchID {
		bad("GameInfo вернул другой матч", map[string]any{"returned_id": game.ID})
		return nil, errs
	}
	if game.HomeTeamID == "" || game.AwayTeamID == "" {
		bad("в GameInfo нет id команд хозяев или гостей", nil)
		return nil, errs
	}
	sides := map[int]matchSide{
		1: {side: dto.SideHome, teamID: game.HomeTeamID},
		2: {side: dto.SideAway, teamID: game.AwayTeamID},
	}

	profiles := make(map[string]*sstats.LsPlayerProfile, len(lineups.Players))
	for i := range lineups.Players {
		profiles[lineups.Players[i].ID] = &lineups.Players[i]
	}
	coachIDs := map[string]bool{}
	for id, p := range profiles {
		if strings.EqualFold(p.Role, "coach") {
			coachIDs[id] = true
		}
	}

	data := &dto.MatchDetailsData{
		Teams:       []dto.MatchTeamData{},
		Lineup:      []dto.LineupEntryData{},
		Events:      []dto.MatchEventData{},
		TeamStats:   []dto.TeamStatsData{},
		PlayerStats: []dto.PlayerMatchStatsData{},
	}

	for _, l := range lineups.Lineups {
		side, ok := sides[int(l.Side)]
		if !ok {
			bad("у состава неизвестная сторона", map[string]any{"side": int(l.Side)})
			continue
		}
		if l.TeamID != "" && l.TeamID != side.teamID {
			bad("команда состава не совпадает с командой матча", map[string]any{"lineup_team_id": l.TeamID, "match_team_id": side.teamID, "side": side.side})
			continue
		}

		team := dto.MatchTeamData{Side: side.side, TeamExternalID: side.teamID}
		if l.Formation != nil {
			team.Formation = strings.TrimSpace(*l.Formation)
		}
		if l.AverageRating != nil && *l.AverageRating > 0 {
			v := float64(*l.AverageRating)
			team.Rating = &v
		}

		for i, lp := range l.Players {
			if lp.PlayerID == "" {
				bad("у участника состава нет playerId", map[string]any{"name": lp.PlayerName})
				continue
			}
			switch int(lp.Kind) {
			case sstats.LineupCoach:
				coachIDs[lp.PlayerID] = true
				if team.CoachExternalID == "" && reg.coach(lp.PlayerID, lp.PlayerName, side.teamID, profiles[lp.PlayerID]) {
					team.CoachExternalID = lp.PlayerID
				}
			case sstats.LineupStarter, sstats.LineupSubstitute:
				position := lineupPosition(lp.Position, lp.IsGoalkeeper)
				hint := ""
				if position != nil {
					hint = *position
				}
				if !reg.player(lp.PlayerID, lp.PlayerName, side.teamID, hint, profiles[lp.PlayerID]) {
					bad("у игрока нет имени", map[string]any{"player_id": lp.PlayerID})
					continue
				}
				data.Lineup = append(data.Lineup, lineupEntry(lp, side.teamID, position, i))
				if stats := playerStats(lp, side.teamID); stats != nil {
					data.PlayerStats = append(data.PlayerStats, *stats)
				}
			}
		}
		data.Teams = append(data.Teams, team)
	}

	events, eventErrs := normalizeEvents(info.Events, sides, coachIDs, profiles, reg)
	data.Events = events
	errs = append(errs, eventErrs...)
	data.TeamStats = teamStats(info.Statistics, game.HomeTeamID, game.AwayTeamID)

	if len(data.Lineup) == 0 && len(data.Events) == 0 && len(data.TeamStats) == 0 {
		bad("источник не отдал ни составов, ни событий, ни статистики", nil)
		return nil, errs
	}

	return data, errs
}

var minuteRe = regexp.MustCompile(`^(\d{1,3})(?:\+(\d{1,2}))?$`)

// parseMinute: «46» → 46, «45+2» → 47 (прошедшие минуты матча).
func parseMinute(raw *string) *int {
	if raw == nil {
		return nil
	}
	m := minuteRe.FindStringSubmatch(strings.TrimSpace(*raw))
	if m == nil {
		return nil
	}
	n, _ := strconv.Atoi(m[1])
	if m[2] != "" {
		extra, _ := strconv.Atoi(m[2])
		n += extra
	}

	return &n
}

func lineupEntry(lp sstats.LsLineupPlayer, teamID string, position *string, index int) dto.LineupEntryData {
	entry := dto.LineupEntryData{
		TeamExternalID:   teamID,
		PlayerExternalID: lp.PlayerID,
		IsStarter:        int(lp.Kind) == sstats.LineupStarter,
		IsCaptain:        lp.IsCaptain,
		Position:         position,
		SortOrder:        index,
		MinuteIn:         parseMinute(lp.SubInTime),
		MinuteOut:        parseMinute(lp.SubOutTime),
	}
	if lp.SortOrder != nil {
		entry.SortOrder = int(*lp.SortOrder)
	}
	if lp.ShirtNumber != nil {
		if n, err := strconv.Atoi(strings.TrimSpace(*lp.ShirtNumber)); err == nil && n >= 0 && n < 1000 {
			entry.ShirtNumber = &n
		}
	}
	// SStats отдаёт номер линии расстановки (0 — вратарь) в formationRow; formationLine у всех стартеров одинаков.
	if entry.IsStarter && lp.FormationRow != nil {
		line := int(*lp.FormationRow)
		entry.Line = &line
	}

	return entry
}

var playerCoreKeys = map[string]bool{
	"lineupPlayerId": true, "minutesPlayed": true, "goals": true, "assists": true, "goalsPenalty": true,
	"totalShots": true, "shotsOnTarget": true, "expectedGoals": true, "expectedAssists": true,
	"yellowCards": true, "redCards": true, "rating": true, "isBestRating": true, "extraData": true,
}

func playerStats(lp sstats.LsLineupPlayer, teamID string) *dto.PlayerMatchStatsData {
	s := lp.Statistics
	if len(s) == 0 {
		return nil
	}
	out := &dto.PlayerMatchStatsData{
		TeamExternalID:   teamID,
		PlayerExternalID: lp.PlayerID,
		Minutes:          anyInt(s["minutesPlayed"]),
		Goals:            anyInt(s["goals"]),
		Assists:          anyInt(s["assists"]),
		PenaltiesScored:  anyInt(s["goalsPenalty"]),
		Shots:            anyInt(s["totalShots"]),
		ShotsOnTarget:    anyInt(s["shotsOnTarget"]),
		XG:               anyFloat(s["expectedGoals"]),
		XA:               anyFloat(s["expectedAssists"]),
		YellowCards:      anyInt(s["yellowCards"]),
		RedCards:         anyInt(s["redCards"]),
		Rating:           anyFloat(s["rating"]),
		IsMOTM:           s["isBestRating"] == true,
	}

	extra := map[string]any{}
	for key, value := range s {
		if playerCoreKeys[key] {
			continue
		}
		switch v := value.(type) {
		case float64:
			extra[snakeCase(key)] = numberValue(v)
		case bool:
			if v {
				extra[snakeCase(key)] = true
			}
		case string:
			if key == "bonuses" && v != "" {
				extra["bonuses"] = strings.Split(v, ",")
			}
		}
	}
	if len(extra) > 0 {
		out.Extra = extra
	}

	return out
}

func anyFloat(v any) *float64 {
	f, ok := v.(float64)
	if !ok || math.IsNaN(f) || math.IsInf(f, 0) {
		return nil
	}

	return &f
}

func anyInt(v any) *int {
	f := anyFloat(v)
	if f == nil {
		return nil
	}
	n := int(math.Round(*f))

	return &n
}

// numberValue отдаёт целые как int, дробные как есть — чтобы jsonb не раздувался «2.0».
func numberValue(f float64) any {
	if f == math.Trunc(f) && math.Abs(f) < 1e9 {
		return int(f)
	}

	return f
}

func snakeCase(s string) string {
	runes := []rune(s)
	var b strings.Builder
	for i, r := range runes {
		if unicode.IsUpper(r) {
			prevLower := i > 0 && (unicode.IsLower(runes[i-1]) || unicode.IsDigit(runes[i-1]))
			nextLower := i+1 < len(runes) && unicode.IsLower(runes[i+1])
			if i > 0 && (prevLower || (unicode.IsUpper(runes[i-1]) && nextLower)) {
				b.WriteByte('_')
			}
			b.WriteRune(unicode.ToLower(r))
			continue
		}
		b.WriteRune(r)
	}

	return b.String()
}
