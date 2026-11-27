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

// SyncFixtures: расписание, результаты и коэффициенты матчей сезона за окно дат. Эндпоинт: /Games/list.
// Запасной /Ls/List (по Ls-сезону, за то же окно) запрашивается, только если без него не обойтись: этап и тур
// многоэтапного сезона (payload.ls_stages) или flash-id команды, которого нет в /Games/list (иначе команда задвоится).
// Раз запрос уже сделан, из него же берётся счёт серии пенальти (homeResultAP/awayResultAP).
type SyncFixtures struct{}

const dateLayout = "2006-01-02"

func (SyncFixtures) Collect(ctx context.Context, src Source, cmd dto.Command) (*Output, error) {
	var p dto.FixturesPayload
	if err := json.Unmarshal(cmd.Payload, &p); err != nil {
		return nil, &PermanentError{Msg: "invalid sync_fixtures payload: " + err.Error()}
	}
	if p.SeasonAPIID == "" && (p.LeagueAPIID == "" || p.SeasonYear == 0) {
		return nil, &PermanentError{Msg: "sync_fixtures requires season_api_id or league_api_id + season_year"}
	}

	query := sstats.GamesQuery{SeasonUID: p.SeasonAPIID, LeagueID: p.LeagueAPIID, Year: p.SeasonYear}
	if p.Window != nil {
		var err error
		if query.From, query.To, err = parseWindow(*p.Window); err != nil {
			return nil, &PermanentError{Msg: "invalid sync_fixtures window: " + err.Error()}
		}
	}

	games, err := src.Games(ctx, query)
	if err != nil {
		return nil, err
	}

	out := &Output{}
	ls, lsOK, err := lsGames(ctx, src, p, query, games, out)
	if err != nil {
		return nil, err
	}
	fillTeamFlashIDs(games, ls)

	teams := map[string]bool{}
	for _, g := range games {
		if needsTeamFlash(p) && (strings.TrimSpace(g.Home.FlashID) == "" || strings.TrimSpace(g.Away.FlashID) == "") {
			msg := "у команды матча нет flashId, а /Ls/List недоступен"
			if lsOK {
				msg = "у команды матча нет flashId ни в /Games/list, ни в записи матча /Ls/List"
			}
			out.AddError(dto.EntityError{
				EntityType: dto.EntityMatch, ExternalID: g.ID.String(), Phase: dto.PhaseNormalize,
				Message: msg + ": матч пропущен, чтобы не завести дубль команды",
				Context: map[string]any{"home_team_id": g.Home.ID.String(), "away_team_id": g.Away.ID.String()},
			})
			continue
		}
		entity, nerr := normalizeListGame(g, p, ls)
		if nerr != nil {
			nerr.EntityType = dto.EntityMatch
			nerr.ExternalID = g.ID.String()
			out.AddError(*nerr)
			continue
		}
		for _, t := range []sstats.GameTeamRef{g.Home, g.Away} {
			if id := t.ID.String(); !teams[id] {
				teams[id] = true
				out.Add(teamEntity(t))
			}
		}
		out.Add(entity)
	}

	return out, nil
}

// lsGame — то, что /Ls/List знает о матче (по flash-id матча) и чего нет в /Games/list.
type lsGame struct {
	stageID, roundID   string
	homeTeam, awayTeam string
	homePens, awayPens *int
}

// needsTeamFlash: команду без flashId можно опознать только через /Ls/List, а для него нужен Ls-сезон.
func needsTeamFlash(p dto.FixturesPayload) bool { return p.SeasonExternalID != "" }

// lsGames запрашивает /Ls/List, только если он нужен: этапы многоэтапного сезона или flash-id команды.
// Сбой не валит прогон (кроме auth/лимита): ok=false, матчи уходят без этапа (Laravel берёт этап у известного матча).
// Матч, команде которого flash-id не нашёлся (сбой Ls, у матча нет flashId или записи в /Ls/List), пропускается:
// в сезоне с Ls-структурой команда почти наверняка уже заведена, и новая по числовому id стала бы дублем.
func lsGames(ctx context.Context, src Source, p dto.FixturesPayload, q sstats.GamesQuery, games []sstats.Game, out *Output) (map[string]lsGame, bool, error) {
	if p.SeasonExternalID == "" {
		return nil, false, nil
	}
	needStages := p.LsStages && p.StageExternalID == ""
	needTeams := false
	for _, g := range games {
		if strings.TrimSpace(g.Home.FlashID) == "" || strings.TrimSpace(g.Away.FlashID) == "" {
			needTeams = true
			break
		}
	}
	if !needStages && !needTeams {
		return nil, true, nil
	}

	fixtures, err := src.Fixtures(ctx, sstats.FixturesQuery{SeasonID: p.SeasonExternalID, From: q.From, To: q.To})
	if err != nil {
		if fatalFetch(err) {
			return nil, false, err
		}
		out.AddError(dto.EntityError{
			EntityType: dto.EntityMatch, Phase: dto.PhaseFetch,
			Message: "не удалось получить /Ls/List (этапы, flash-id команд): " + err.Error(),
			Context: map[string]any{"endpoint": "Ls/List"},
		})

		return nil, false, nil
	}

	ls := make(map[string]lsGame, len(fixtures))
	for _, f := range fixtures {
		if f.ID == "" {
			continue
		}
		g := lsGame{homeTeam: strings.TrimSpace(f.HomeTeamID), awayTeam: strings.TrimSpace(f.AwayTeamID)}
		if needStages {
			g.stageID, g.roundID = strings.TrimSpace(f.StageID), f.RoundID.String()
		}
		g.homePens, g.awayPens = intPtr(f.HomeResultAP), intPtr(f.AwayResultAP)
		ls[f.ID] = g
	}

	return ls, true, nil
}

// fillTeamFlashIDs дописывает командам без flashId Ls-id из записи того же матча в /Ls/List — по нему Laravel
// находит команду, уже заведённую из Ls-структуры. Один id команды — один flash-id на весь прогон.
func fillTeamFlashIDs(games []sstats.Game, ls map[string]lsGame) {
	if len(ls) == 0 {
		return
	}
	known := map[string]string{}
	for _, g := range games {
		f, ok := ls[strings.TrimSpace(g.FlashID)]
		if !ok {
			continue
		}
		for _, side := range []struct {
			team   sstats.GameTeamRef
			lsTeam string
		}{{g.Home, f.homeTeam}, {g.Away, f.awayTeam}} {
			if id := side.team.ID.String(); id != "" && strings.TrimSpace(side.team.FlashID) == "" && side.lsTeam != "" {
				known[id] = side.lsTeam
			}
		}
	}
	for i := range games {
		for _, t := range []*sstats.GameTeamRef{&games[i].Home, &games[i].Away} {
			if strings.TrimSpace(t.FlashID) == "" && known[t.ID.String()] != "" {
				t.FlashID = known[t.ID.String()]
			}
		}
	}
}

// teamEntity — команда матча, ключ — числовой id SStats; flash-id и mappings — для связи с командами из Ls-структуры.
func teamEntity(t sstats.GameTeamRef) dto.Entity {
	data := dto.TeamData{
		APIID:   t.ID.String(),
		FlashID: strings.TrimSpace(t.FlashID),
		Name:    strings.TrimSpace(t.Name),
		Refs:    refsOf(t.FlashID, t.Mappings),
	}
	if c := t.Country; c != nil && strings.TrimSpace(c.Code) != "" {
		data.CountryCode = strings.ToUpper(strings.TrimSpace(c.Code))
		data.CountryName = strings.TrimSpace(c.Name)
	}
	data.LogoURL = absoluteURL(t.LogoURL)

	return dto.Entity{EntityType: dto.EntityTeam, ExternalID: data.APIID, Data: data}
}

// refsOf — справочные id (flashscore, pari). Свой числовой id SStats — ключ сущности, в refs не входит.
func refsOf(flashID string, mappings map[string]sstats.FlexStrings) map[string]string {
	refs := map[string]string{}
	if v := mappings["flashscore"].First(); v != "" {
		refs[dto.RefFlashscore] = v
	} else if v := strings.TrimSpace(flashID); v != "" {
		refs[dto.RefFlashscore] = v
	}
	if v := mappings["pari"].First(); v != "" {
		refs[dto.RefPari] = v
	}
	if len(refs) == 0 {
		return nil
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

func normalizeListGame(g sstats.Game, p dto.FixturesPayload, ls map[string]lsGame) (dto.Entity, *dto.EntityError) {
	bad := func(msg string, ctx map[string]any) (dto.Entity, *dto.EntityError) {
		return dto.Entity{}, &dto.EntityError{Phase: dto.PhaseNormalize, Message: msg, Context: ctx}
	}

	apiID := g.ID.String()
	if apiID == "" {
		return bad("у матча нет id", nil)
	}
	homeID, awayID := g.Home.ID.String(), g.Away.ID.String()
	if homeID == "" || awayID == "" || homeID == awayID {
		return bad("у матча нет id команды хозяев или гостей", nil)
	}

	kickoff, ok := gameKickoff(g)
	if !ok {
		return bad("не удалось разобрать время начала матча", map[string]any{"raw_date": g.Date, "raw_date_utc": g.DateUTC.String()})
	}

	status, period, ok := listStatus(g)
	if !ok {
		raw := map[string]any{"raw_status_name": g.StatusName}
		if g.Status != nil {
			raw["raw_status"] = int(*g.Status)
		}
		return bad("неизвестный статус матча", raw)
	}

	flashID := strings.TrimSpace(g.FlashID)
	data := dto.MatchData{
		APIID:              apiID,
		FlashID:            flashID,
		SeasonExternalID:   p.SeasonExternalID,
		SeasonAPIID:        firstNonEmpty(g.Season.UID, p.SeasonAPIID),
		StageExternalID:    p.StageExternalID,
		RoundName:          strings.TrimSpace(g.RoundName),
		RoundNumber:        roundNumber(g.RoundName),
		HomeTeamExternalID: strings.TrimSpace(g.Home.FlashID),
		AwayTeamExternalID: strings.TrimSpace(g.Away.FlashID),
		HomeTeamAPIID:      homeID,
		AwayTeamAPIID:      awayID,
		HomeTeamName:       strings.TrimSpace(g.Home.Name),
		AwayTeamName:       strings.TrimSpace(g.Away.Name),
		KickoffAt:          kickoff.Format(time.RFC3339),
		Status:             status,
		Period:             period,
		Refs:               refsOf(flashID, g.Mappings),
		Odds:               gameOdds(g.Odds),
	}
	lsg, inLs := ls[flashID]
	inLs = inLs && flashID != ""
	if inLs && data.StageExternalID == "" && lsg.stageID != "" {
		data.StageExternalID, data.RoundExternalID = lsg.stageID, lsg.roundID
	}
	if status == dto.MatchLive || status == dto.MatchFinished {
		data.HomeGoals, data.AwayGoals = intPtr(g.HomeResult), intPtr(g.AwayResult)
		data.HomeGoalsHT, data.AwayGoalsHT = intPtr(g.HomeHTResult), intPtr(g.AwayHTResult)
	}
	if inLs && status == dto.MatchFinished && lsg.homePens != nil && lsg.awayPens != nil {
		data.HomePens, data.AwayPens = lsg.homePens, lsg.awayPens
	}

	return dto.Entity{EntityType: dto.EntityMatch, ExternalID: apiID, Data: data}, nil
}

// gameOdds — доматчевые коэффициенты закрытия из /Games/list (рынок SStats → исход → коэффициент).
func gameOdds(bets []sstats.GameBet) []dto.MatchOddData {
	var out []dto.MatchOddData
	for _, b := range bets {
		market := int(b.MarketID)
		if market <= 0 {
			continue
		}
		for _, o := range b.Odds {
			name := strings.TrimSpace(o.Name)
			if name == "" || o.Value <= 1 {
				continue
			}
			out = append(out, dto.MatchOddData{
				MarketID:   market,
				MarketName: strings.TrimSpace(b.MarketName),
				Outcome:    name,
				Value:      float64(o.Value),
			})
		}
	}

	return out
}

func gameKickoff(g sstats.Game) (time.Time, bool) {
	if n, err := strconv.ParseInt(g.DateUTC.String(), 10, 64); err == nil && n > 0 {
		return time.Unix(n, 0).UTC(), true
	}
	if t, err := time.Parse(time.RFC3339, g.Date); err == nil {
		return t.UTC(), true
	}

	return time.Time{}, false
}

// listStatus — статус по коду /Games (таблица статусов SStats), без кода — по названию (как в sync_match_details).
func listStatus(g sstats.Game) (string, *string, bool) {
	if g.Status != nil {
		return gameStatusCode(int(*g.Status))
	}
	status, period := gameStatus(g.StatusName)

	return status, period, status != ""
}

// gameStatusCode сводит коды статусов /Games SStats к enum MatchStatus и, где известно, к MatchPeriod.
func gameStatusCode(code int) (string, *string, bool) {
	period := func(p string) *string { return &p }

	switch code {
	case 1, 2:
		return dto.MatchScheduled, nil, true
	case 3:
		return dto.MatchLive, period(dto.PeriodFirstHalf), true
	case 4:
		return dto.MatchLive, period(dto.PeriodHalfTime), true
	case 5:
		return dto.MatchLive, period(dto.PeriodSecondHalf), true
	case 6, 11:
		return dto.MatchLive, period(dto.PeriodExtraTime), true
	case 7:
		return dto.MatchLive, period(dto.PeriodPenalties), true
	case 12, 19:
		return dto.MatchLive, nil, true
	case 8, 9, 10, 17, 18:
		return dto.MatchFinished, nil, true
	case 13:
		return dto.MatchAbandoned, nil, true
	case 14:
		return dto.MatchPostponed, nil, true
	case 15:
		return dto.MatchCancelled, nil, true
	}

	return "", nil, false
}

// roundNumberRe: «Round 5» (Ls), «Regular Season - 5», «League Stage - 5», «Group Stage - 5» (/Games).
var roundNumberRe = regexp.MustCompile(`(?i)^(?:round|matchday|week|gameweek|regular season|league stage|league phase|group stage)\s*-?\s*(\d{1,3})$`)

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
