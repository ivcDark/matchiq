package jobs

import (
	"context"
	"encoding/json"
	"strings"

	"github.com/ivcDark/matchiq/collector/internal/dto"
)

// SyncStructure: стадии и команды сезона. Эндпоинт: /Ls/SeasonStructure.
type SyncStructure struct{}

func (SyncStructure) Collect(ctx context.Context, src Source, cmd dto.Command) (*Output, error) {
	var p dto.StructurePayload
	if err := json.Unmarshal(cmd.Payload, &p); err != nil {
		return nil, &PermanentError{Msg: "invalid sync_structure payload: " + err.Error()}
	}
	if p.SeasonExternalID == "" {
		return nil, &PermanentError{Msg: "sync_structure requires season_external_id"}
	}

	structure, err := src.SeasonStructure(ctx, p.SeasonExternalID)
	if err != nil {
		return nil, err
	}

	out := &Output{}
	seenCountry := map[string]bool{}

	for i, st := range structure.Stages {
		if st.ID == "" || strings.TrimSpace(st.Name) == "" {
			out.AddError(dto.EntityError{
				EntityType: dto.EntityStage, ExternalID: st.ID, Phase: dto.PhaseNormalize,
				Message: "у стадии нет id или названия",
			})
			continue
		}
		typ, ok := stageType(st.StatsType)
		if !ok {
			out.AddError(dto.EntityError{
				EntityType: dto.EntityStage, ExternalID: st.ID, Phase: dto.PhaseNormalize,
				Message: "неизвестный тип стадии", Context: map[string]any{"raw_stats_type": st.StatsType},
			})
			continue
		}
		order := i + 1
		if st.SortOrder != nil {
			order = int(*st.SortOrder) + 1
		}
		out.Add(dto.Entity{
			EntityType: dto.EntityStage,
			ExternalID: st.ID,
			Data: dto.StageData{
				SeasonExternalID: p.SeasonExternalID,
				Name:             strings.TrimSpace(st.Name),
				Type:             typ,
				StageOrder:       order,
				HasStandings:     typ == dto.StageTypeLeague,
			},
		})
	}

	for _, t := range structure.Teams {
		if t.UniqueID == "" || strings.TrimSpace(t.Name) == "" {
			out.AddError(dto.EntityError{
				EntityType: dto.EntityTeam, ExternalID: t.UniqueID, Phase: dto.PhaseNormalize,
				Message: "у команды нет id или названия",
			})
			continue
		}
		countryExt := t.CountryID.String()
		if t.Country != nil {
			if country, ok := countryEntity(*t.Country); ok {
				countryExt = country.ExternalID
				if !seenCountry[countryExt] {
					seenCountry[countryExt] = true
					out.Add(country)
				}
			}
		}
		out.Add(dto.Entity{
			EntityType: dto.EntityTeam,
			ExternalID: t.UniqueID,
			Data: dto.TeamData{
				SeasonExternalID:  p.SeasonExternalID,
				CountryExternalID: countryExt,
				Name:              strings.TrimSpace(t.Name),
				ShortName:         strings.TrimSpace(t.ShortName),
				LogoURL:           absoluteURL(t.LogoURL),
			},
		})
	}

	return out, nil
}
