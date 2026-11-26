package jobs

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

// SyncCatalog: страны, соревнования и их сезоны по фильтрам (страна / часть названия / id лиг).
// Эндпоинты: /Ls/Leagues, /Ls/Seasons.
type SyncCatalog struct{}

func (SyncCatalog) Collect(ctx context.Context, src Source, cmd dto.Command) (*Output, error) {
	var p dto.CatalogPayload
	if err := json.Unmarshal(cmd.Payload, &p); err != nil {
		return nil, &PermanentError{Msg: "invalid sync_catalog payload: " + err.Error()}
	}
	if p.Filters.Empty() {
		return nil, &PermanentError{Msg: "sync_catalog requires at least one filter (country, league_name, league_ids)"}
	}

	leagues, err := src.Leagues(ctx, p.Filters.LeagueName)
	if err != nil {
		return nil, err
	}

	out := &Output{}
	seenCountry := map[string]bool{}

	for _, league := range leagues {
		if !matchLeague(league, p.Filters) {
			continue
		}
		if league.ID == "" || strings.TrimSpace(league.Name) == "" {
			out.AddError(dto.EntityError{
				EntityType: dto.EntityCompetition, ExternalID: league.ID, Phase: dto.PhaseNormalize,
				Message: "у лиги нет id или названия",
			})
			continue
		}

		scope := dto.ScopeDomestic
		countryExt := ""
		if country, ok := countryEntity(league.Country); ok {
			countryExt = country.ExternalID
			if !seenCountry[countryExt] {
				seenCountry[countryExt] = true
				out.Add(country)
			}
			if isRegion(league.Country) {
				scope = dto.ScopeContinental
			}
		}

		out.Add(dto.Entity{
			EntityType: dto.EntityCompetition,
			ExternalID: league.ID,
			Data: dto.CompetitionData{
				CountryExternalID: countryExt,
				Name:              strings.TrimSpace(league.Name),
				Type:              dto.CompetitionTypeLeague,
				Scope:             scope,
				LogoURL:           absoluteURL(league.LogoURL),
			},
		})

		if err := collectSeasons(ctx, src, league.ID, out); err != nil {
			return nil, err
		}
	}

	return out, nil
}

func collectSeasons(ctx context.Context, src Source, leagueID string, out *Output) error {
	seasons, err := src.Seasons(ctx, leagueID)
	if err != nil {
		if sstats.Retryable(err) || errors.Is(err, sstats.ErrUnauthorized) {
			return err
		}
		out.AddError(dto.EntityError{
			EntityType: dto.EntityCompetition, ExternalID: leagueID, Phase: dto.PhaseFetch,
			Message: fmt.Sprintf("не удалось загрузить сезоны: %v", err),
		})

		return nil
	}

	for _, s := range seasons {
		if s.UniqueID == "" || strings.TrimSpace(s.Years) == "" {
			out.AddError(dto.EntityError{
				EntityType: dto.EntitySeason, ExternalID: s.UniqueID, Phase: dto.PhaseNormalize,
				Message: "у сезона нет id или подписи лет", Context: map[string]any{"league_id": leagueID},
			})
			continue
		}
		out.Add(dto.Entity{
			EntityType: dto.EntitySeason,
			ExternalID: s.UniqueID,
			Data: dto.SeasonData{
				CompetitionExternalID: leagueID,
				Label:                 strings.TrimSpace(s.Years),
				IsCurrent:             s.IsCurrentSeason,
			},
		})
	}

	return nil
}

func matchLeague(l sstats.LsLeague, f dto.CatalogFilters) bool {
	if len(f.LeagueIDs) > 0 {
		found := false
		for _, id := range f.LeagueIDs {
			if id == l.ID {
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
		c := l.Country
		if strings.ToLower(c.Name) != want && strings.ToLower(c.ID) != want && strings.ToLower(c.Abbr2) != want {
			return false
		}
	}
	if f.LeagueName != "" && !strings.Contains(strings.ToLower(l.Name), strings.ToLower(f.LeagueName)) {
		return false
	}

	return true
}
