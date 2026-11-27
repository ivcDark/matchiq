package jobs

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

// SyncCatalog: соревнования и их сезоны по фильтрам (страна / часть названия / id лиг — числовые или flash).
// Эндпоинт: /Leagues (один запрос: все лиги вместе с сезонами). Ключ соревнования — числовой id SStats, сезона — uuid.
type SyncCatalog struct{}

func (SyncCatalog) Collect(ctx context.Context, src Source, cmd dto.Command) (*Output, error) {
	var p dto.CatalogPayload
	if err := json.Unmarshal(cmd.Payload, &p); err != nil {
		return nil, &PermanentError{Msg: "invalid sync_catalog payload: " + err.Error()}
	}
	if p.Filters.Empty() {
		return nil, &PermanentError{Msg: "sync_catalog requires at least one filter (country, league_name, league_ids)"}
	}

	leagues, err := src.Leagues(ctx)
	if err != nil {
		return nil, err
	}

	out := &Output{}
	owner := flashOwners(leagues)
	now := time.Now().UTC()

	for _, league := range leagues {
		if !matchLeague(league, p.Filters) {
			continue
		}
		apiID := league.ID.String()
		name := strings.TrimSpace(league.Name)
		if apiID == "" || name == "" {
			out.AddError(dto.EntityError{
				EntityType: dto.EntityCompetition, ExternalID: apiID, Phase: dto.PhaseNormalize,
				Message: "у лиги нет id или названия",
			})
			continue
		}
		flashID := leagueFlashID(league)
		if flashID != "" && owner[flashID] != apiID {
			out.AddError(dto.EntityError{
				EntityType: dto.EntityCompetition, ExternalID: apiID, Phase: dto.PhaseNormalize,
				Message: "flash-id лиги уже занят другой лигой SStats — пропущена",
				Context: map[string]any{"flash_id": flashID, "name": name, "owner_api_id": owner[flashID]},
			})
			continue
		}

		data := dto.CompetitionData{
			APIID:   apiID,
			FlashID: flashID,
			Name:    name,
			Type:    dto.CompetitionTypeLeague,
			Scope:   dto.ScopeDomestic,
			Refs:    leagueRefs(league, flashID),
		}
		if c := league.Country; c != nil && strings.TrimSpace(c.Code) != "" {
			data.CountryCode = strings.ToUpper(strings.TrimSpace(c.Code))
			data.CountryName = strings.TrimSpace(c.Name)
			if data.CountryCode == worldCountryCode {
				data.Scope = dto.ScopeContinental
			}
		}
		out.Add(dto.Entity{EntityType: dto.EntityCompetition, ExternalID: apiID, Data: data})

		addSeasons(league, apiID, flashID, now, out)
	}

	return out, nil
}

// worldCountryCode — «страна» международных турниров в основном API (UEFA и т.п.).
const worldCountryCode = "WW"

func leagueFlashID(l sstats.League) string {
	if id := strings.TrimSpace(l.FlashScoreID); id != "" {
		return id
	}

	return strings.TrimSpace(l.Mappings["flashscore"].First())
}

func leagueRefs(l sstats.League, flashID string) map[string]string {
	refs := map[string]string{}
	if flashID != "" {
		refs[dto.RefFlashscore] = flashID
	}
	if v := l.Mappings["pari"].First(); v != "" {
		refs[dto.RefPari] = v
	}
	if len(refs) == 0 {
		return nil
	}

	return refs
}

// flashOwners: flash-id → лига SStats, которой он принадлежит. Основной API иногда делит одно соревнование Flashscore
// на несколько лиг (осень/весна); владельцем считается лига с бо́льшим числом сезонов со slug Flashscore, затем меньший id.
func flashOwners(leagues []sstats.League) map[string]string {
	type candidate struct {
		apiID  string
		seeded int
	}
	best := map[string]candidate{}
	for _, l := range leagues {
		flashID, apiID := leagueFlashID(l), l.ID.String()
		if flashID == "" || apiID == "" {
			continue
		}
		c := candidate{apiID: apiID}
		for _, s := range l.Seasons {
			if s.FlashScoreID != nil && strings.TrimSpace(*s.FlashScoreID) != "" {
				c.seeded++
			}
		}
		cur, ok := best[flashID]
		if !ok || c.seeded > cur.seeded || (c.seeded == cur.seeded && lessNumeric(c.apiID, cur.apiID)) {
			best[flashID] = c
		}
	}

	owners := make(map[string]string, len(best))
	for flashID, c := range best {
		owners[flashID] = c.apiID
	}

	return owners
}

func lessNumeric(a, b string) bool {
	if len(a) != len(b) {
		return len(a) < len(b)
	}

	return a < b
}

// addSeasons: сезоны лиги. Текущий — последний начавшийся (по dateStart), иначе самый ранний из будущих.
func addSeasons(l sstats.League, apiID, flashID string, now time.Time, out *Output) {
	split := splitYears(l.Seasons)
	current := currentSeason(l.Seasons, now)

	for _, s := range l.Seasons {
		year := int(s.Year)
		if s.UID == "" || year <= 0 {
			out.AddError(dto.EntityError{
				EntityType: dto.EntitySeason, ExternalID: s.UID, Phase: dto.PhaseNormalize,
				Message: "у сезона нет uid или года", Context: map[string]any{"league_api_id": apiID},
			})
			continue
		}
		isCurrent := s.UID == current
		label := fmt.Sprintf("%d", year)
		if split {
			label = fmt.Sprintf("%d-%d", year, year+1)
		}
		out.Add(dto.Entity{
			EntityType: dto.EntitySeason,
			ExternalID: s.UID,
			Data: dto.SeasonData{
				APIID:                 s.UID,
				CompetitionExternalID: flashID,
				CompetitionAPIID:      apiID,
				Label:                 label,
				IsCurrent:             &isCurrent,
				StartsOn:              validDate(s.DateStart),
				EndsOn:                validDate(s.DateEnd),
			},
		})
	}
}

// splitYears: сезоны лиги идут «осень–весна» («2025-2026»), если хоть один сезон заканчивается в следующем году.
// dateEnd текущего сезона — последний известный матч, поэтому смотрим на все сезоны лиги, а не на один.
func splitYears(seasons []sstats.LeagueSeason) bool {
	for _, s := range seasons {
		end, err := time.Parse(dateLayout, validDate(s.DateEnd))
		if err == nil && int(s.Year) > 0 && end.Year() > int(s.Year) {
			return true
		}
	}

	return false
}

func currentSeason(seasons []sstats.LeagueSeason, now time.Time) string {
	var started, upcoming string
	var startedAt, upcomingAt time.Time
	for _, s := range seasons {
		start, err := time.Parse(dateLayout, validDate(s.DateStart))
		if s.UID == "" || err != nil {
			continue
		}
		if !start.After(now) {
			if started == "" || start.After(startedAt) {
				started, startedAt = s.UID, start
			}
		} else if upcoming == "" || start.Before(upcomingAt) {
			upcoming, upcomingAt = s.UID, start
		}
	}
	if started != "" {
		return started
	}

	return upcoming
}

func matchLeague(l sstats.League, f dto.CatalogFilters) bool {
	if len(f.LeagueIDs) > 0 {
		found := false
		for _, id := range f.LeagueIDs {
			id = strings.TrimSpace(id)
			if id != "" && (id == l.ID.String() || id == leagueFlashID(l)) {
				found = true
				break
			}
		}
		if !found {
			return false
		}
	}
	if f.Country != "" {
		want := strings.ToLower(strings.TrimSpace(f.Country))
		if l.Country == nil || (strings.ToLower(l.Country.Name) != want && strings.ToLower(l.Country.Code) != want) {
			return false
		}
	}
	if f.LeagueName != "" && !strings.Contains(strings.ToLower(l.Name), strings.ToLower(f.LeagueName)) {
		return false
	}

	return true
}

func firstNonEmpty(values ...string) string {
	for _, v := range values {
		if v != "" {
			return v
		}
	}

	return ""
}
