package jobs

import (
	"context"
	"encoding/json"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

// SyncFixtures: расписание и результаты матчей сезона/стадии за окно дат. Эндпоинт: /Ls/List.
type SyncFixtures struct{}

const dateLayout = "2006-01-02"

func (SyncFixtures) Collect(ctx context.Context, src Source, cmd dto.Command) (*Output, error) {
	var p dto.FixturesPayload
	if err := json.Unmarshal(cmd.Payload, &p); err != nil {
		return nil, &PermanentError{Msg: "invalid sync_fixtures payload: " + err.Error()}
	}
	if p.SeasonExternalID == "" {
		return nil, &PermanentError{Msg: "sync_fixtures requires season_external_id"}
	}

	query := sstats.FixturesQuery{SeasonID: p.SeasonExternalID, StageID: p.StageExternalID}
	if p.Window != nil {
		var err error
		if query.From, query.To, err = parseWindow(*p.Window); err != nil {
			return nil, &PermanentError{Msg: "invalid sync_fixtures window: " + err.Error()}
		}
	}

	fixtures, err := src.Fixtures(ctx, query)
	if err != nil {
		return nil, err
	}

	out := &Output{}
	var flashIDs []string
	for _, f := range fixtures {
		entity, nerr := normalizeFixture(f, p)
		if nerr != nil {
			nerr.EntityType = dto.EntityMatch
			nerr.ExternalID = f.ID
			out.AddError(*nerr)
			continue
		}
		out.Add(entity)
		flashIDs = append(flashIDs, f.ID)
	}

	if err := collectExternalRefs(ctx, src, flashIDs, out); err != nil {
		return nil, err
	}

	return out, nil
}

// collectExternalRefs добавляет числовой id SStats, flashscore и pari матчей, команд и лиг (/Games/list).
// Расписание уже получено, поэтому сбой этого запроса не валит прогон: он попадает в errors, а auth/лимит запросов — нет.
func collectExternalRefs(ctx context.Context, src Source, flashIDs []string, out *Output) error {
	if len(flashIDs) == 0 {
		return nil
	}
	games, err := src.GamesByFlashIDs(ctx, flashIDs)
	if err != nil {
		if fatalFetch(err) {
			return err
		}
		out.AddError(dto.EntityError{
			EntityType: dto.EntityExternalRefs,
			Phase:      dto.PhaseFetch,
			Message:    "не удалось получить Games/list (идентификаторы matches/teams/competitions): " + err.Error(),
			Context:    map[string]any{"endpoint": "Games/list"},
		})
		return nil
	}

	seen := map[string]bool{}
	add := func(entityType, key string, refs map[string]string) {
		if key == "" || refs[dto.RefSstatsAPI] == "" || seen[entityType+":"+key] {
			return
		}
		seen[entityType+":"+key] = true
		out.Add(dto.Entity{EntityType: dto.EntityExternalRefs, ExternalID: key, Data: dto.ExternalRefsData{EntityType: entityType, Refs: refs}})
	}
	for _, g := range games {
		add(dto.EntityMatch, g.FlashID, refsOf(g.ID.String(), g.FlashID, g.Mappings))
		add(dto.EntityTeam, g.Home.FlashID, refsOf(g.Home.ID.String(), g.Home.FlashID, g.Home.Mappings))
		add(dto.EntityTeam, g.Away.FlashID, refsOf(g.Away.ID.String(), g.Away.FlashID, g.Away.Mappings))
		league := g.Season.League
		add(dto.EntityCompetition, league.FlashScoreID, refsOf(league.ID.String(), league.FlashScoreID, league.Mappings))
	}

	return nil
}

func refsOf(sstatsID, flashID string, mappings map[string]sstats.FlexStrings) map[string]string {
	refs := map[string]string{dto.RefSstatsAPI: sstatsID}
	if v := mappings["flashscore"].First(); v != "" {
		refs[dto.RefFlashscore] = v
	} else if flashID != "" {
		refs[dto.RefFlashscore] = flashID
	}
	if v := mappings["pari"].First(); v != "" {
		refs[dto.RefPari] = v
	}

	return refs
}

// parseWindow превращает включительные даты в полуоткрытый интервал [from, to+1д) для SStats.
func parseWindow(w dto.FixturesWindow) (from, to time.Time, err error) {
	if w.From != "" {
		if from, err = time.Parse(dateLayout, w.From); err != nil {
			return from, to, fmt.Errorf("from: %w", err)
		}
	}
	if w.To != "" {
		if to, err = time.Parse(dateLayout, w.To); err != nil {
			return from, to, fmt.Errorf("to: %w", err)
		}
		to = to.AddDate(0, 0, 1)
	}
	if !from.IsZero() && !to.IsZero() && !from.Before(to) {
		return from, to, fmt.Errorf("from позже to")
	}

	return from, to, nil
}

func normalizeFixture(f sstats.LsFixture, p dto.FixturesPayload) (dto.Entity, *dto.EntityError) {
	bad := func(msg string, ctx map[string]any) (dto.Entity, *dto.EntityError) {
		return dto.Entity{}, &dto.EntityError{Phase: dto.PhaseNormalize, Message: msg, Context: ctx}
	}

	if f.ID == "" {
		return bad("у матча нет id", nil)
	}
	if f.HomeTeamID == "" || f.AwayTeamID == "" {
		return bad("у матча нет id команды хозяев или гостей", nil)
	}
	stageID := f.StageID
	if stageID == "" {
		stageID = p.StageExternalID
	}
	if stageID == "" {
		return bad("у матча нет стадии", nil)
	}
	seasonID := f.SeasonID
	if seasonID == "" {
		seasonID = p.SeasonExternalID
	}

	kickoff, ok := kickoffTime(f)
	if !ok {
		return bad("не удалось разобрать время начала матча", map[string]any{"raw_date": f.Date, "raw_date_utc": f.DateUTC.String()})
	}

	status, period, ok := matchStatus(int(f.Status))
	if !ok {
		return bad("неизвестный статус матча", map[string]any{"raw_status": int(f.Status), "raw_status_name": f.StatusName})
	}

	data := dto.MatchData{
		CompetitionExternalID: f.TournamentID,
		SeasonExternalID:      seasonID,
		StageExternalID:       stageID,
		RoundExternalID:       f.RoundID.String(),
		RoundName:             strings.TrimSpace(f.RoundName),
		RoundNumber:           roundNumber(f.RoundName),
		HomeTeamExternalID:    f.HomeTeamID,
		AwayTeamExternalID:    f.AwayTeamID,
		KickoffAt:             kickoff.Format(time.RFC3339),
		Status:                status,
		Period:                period,
		HomeGoalsHT:           intPtr(f.HomeResultHT),
		AwayGoalsHT:           intPtr(f.AwayResultHT),
		HomeGoalsET:           intPtr(f.HomeResultET),
		AwayGoalsET:           intPtr(f.AwayResultET),
		HomePens:              intPtr(f.HomeResultAP),
		AwayPens:              intPtr(f.AwayResultAP),
	}
	if status == dto.MatchLive || status == dto.MatchFinished {
		data.HomeGoals = totalGoals(f.HomeResultFT, f.HomeResultET, f.HomeResult)
		data.AwayGoals = totalGoals(f.AwayResultFT, f.AwayResultET, f.AwayResult)
	}

	return dto.Entity{EntityType: dto.EntityMatch, ExternalID: f.ID, Data: data}, nil
}

func kickoffTime(f sstats.LsFixture) (time.Time, bool) {
	if t, err := time.Parse(time.RFC3339, f.Date); err == nil {
		return t.UTC(), true
	}
	if n, err := strconv.ParseInt(f.DateUTC.String(), 10, 64); err == nil && n > 0 {
		return time.Unix(n, 0).UTC(), true
	}

	return time.Time{}, false
}

// matchStatus сводит статусы SStats (/Ls/List) к enum MatchStatus и, где известно, к MatchPeriod.
func matchStatus(code int) (string, *string, bool) {
	period := func(p string) *string { return &p }

	switch code {
	case 1:
		return dto.MatchScheduled, nil, true
	case 12:
		return dto.MatchLive, period(dto.PeriodFirstHalf), true
	case 13:
		return dto.MatchLive, period(dto.PeriodSecondHalf), true
	case 6:
		return dto.MatchLive, period(dto.PeriodExtraTime), true
	case 7:
		return dto.MatchLive, period(dto.PeriodPenalties), true
	case 2, 38, 45, 42, 46:
		return dto.MatchLive, nil, true
	case 3, 10, 11, 9, 54:
		return dto.MatchFinished, nil, true
	case 43:
		return dto.MatchPostponed, nil, true
	case 36, 37:
		return dto.MatchAbandoned, nil, true
	case 5:
		return dto.MatchCancelled, nil, true
	}

	return "", nil, false
}

var roundNumberRe = regexp.MustCompile(`(?i)^(?:round|matchday|week|gameweek)\s+(\d{1,3})$`)

func roundNumber(name string) *int {
	m := roundNumberRe.FindStringSubmatch(strings.TrimSpace(name))
	if m == nil {
		return nil
	}
	n, err := strconv.Atoi(m[1])
	if err != nil || n < 1 {
		return nil
	}

	return &n
}

func intPtr(v *sstats.FlexInt) *int {
	if v == nil {
		return nil
	}
	n := int(*v)

	return &n
}

// totalGoals — голы после основного и доп. времени без серии пенальти (семантика matches.home_goals).
func totalGoals(ft, et, shown *sstats.FlexInt) *int {
	if ft == nil {
		return intPtr(shown)
	}
	n := int(*ft)
	if et != nil {
		n += int(*et)
	}

	return &n
}
