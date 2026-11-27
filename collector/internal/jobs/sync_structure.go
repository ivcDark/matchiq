package jobs

import (
	"context"
	"encoding/json"
	"fmt"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

// SyncStructure: стадии, группы, раунды, команды сезона и их участие в стадиях.
// Эндпоинты (Ls-fallback: у основного API нет этапов, групп и туров — /Seasons/standings отдаёт только таблицу без
// id этапов и групп): /Ls/SeasonStructure и (для стадий с группами) /Ls/Standings — состав групп; /Ls/Seasons — только
// когда сезон заведён из /Leagues и его Ls-id ещё неизвестен.
type SyncStructure struct{}

func (SyncStructure) Collect(ctx context.Context, src Source, cmd dto.Command) (*Output, error) {
	var p dto.StructurePayload
	if err := json.Unmarshal(cmd.Payload, &p); err != nil {
		return nil, &PermanentError{Msg: "invalid sync_structure payload: " + err.Error()}
	}
	if p.SeasonExternalID == "" && (p.SeasonLabel == "" || p.Competition.ExternalID == "") {
		return nil, &PermanentError{Msg: "sync_structure requires season_external_id or season_label + competition.external_id"}
	}

	out := &Output{}
	if p.SeasonExternalID == "" {
		id, err := lsSeasonID(ctx, src, p.Competition.ExternalID, p.SeasonLabel)
		if err != nil {
			return nil, err
		}
		p.SeasonExternalID = id
		out.Add(dto.Entity{
			EntityType: dto.EntitySeason,
			ExternalID: id,
			Data:       dto.SeasonData{CompetitionExternalID: p.Competition.ExternalID, Label: p.SeasonLabel},
		})
	}

	structure, err := src.SeasonStructure(ctx, p.SeasonExternalID)
	if err != nil {
		return nil, err
	}

	seenCountry := map[string]bool{}

	tables := tablesByStage(structure.StandingTables)
	groupOf, standingsErr := map[string]string{}, error(nil)
	if hasGroupStages(tables) {
		groupOf, standingsErr = groupMembership(ctx, src, p.SeasonExternalID)
		if standingsErr != nil {
			out.AddError(dto.EntityError{
				EntityType: dto.EntityStageGroup, Phase: dto.PhaseFetch,
				Message: "не удалось получить состав групп (/Ls/Standings): " + standingsErr.Error(),
			})
		}
	}
	stageTypes := map[string]string{}

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
		if typ == dto.StageTypeLeague && len(tables[st.ID]) >= 2 {
			typ = dto.StageTypeGroup
		}
		stageTypes[st.ID] = typ
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
				HasStandings:     typ == dto.StageTypeLeague || typ == dto.StageTypeGroup,
			},
		})

		if typ == dto.StageTypeGroup {
			for gi, table := range tables[st.ID] {
				name := strings.TrimSpace(table.Name)
				if name == "" {
					continue
				}
				out.Add(dto.Entity{
					EntityType: dto.EntityStageGroup,
					ExternalID: table.ID.String(),
					Data:       dto.StageGroupData{StageExternalID: st.ID, Name: name, GroupOrder: gi + 1},
				})
			}
		}
	}

	for _, r := range structure.Rounds {
		typ, ok := stageTypes[r.StageID]
		if !ok || r.ID == "" {
			continue
		}
		number, hasNumber := roundOrdinal(r)
		if !hasNumber || strings.TrimSpace(r.Name) == "" {
			continue
		}
		roundType, code := dto.RoundTypeMatchday, ""
		if typ == dto.StageTypeKnockout {
			roundType, code = dto.RoundTypeKnockoutRound, knockoutRoundCode(r.Name)
		}
		out.Add(dto.Entity{
			EntityType: dto.EntityRound,
			ExternalID: r.ID.String(),
			Data: dto.RoundData{
				StageExternalID: r.StageID,
				Number:          number,
				Name:            strings.TrimSpace(r.Name),
				Type:            roundType,
				Code:            code,
			},
		})
	}

	seenStageTeam := map[string]bool{}
	for _, stt := range structure.StageTeams {
		key := stt.StageID + ":" + stt.TeamID
		if stt.StageID == "" || stt.TeamID == "" || seenStageTeam[key] {
			continue
		}
		if _, ok := stageTypes[stt.StageID]; !ok {
			continue
		}
		seenStageTeam[key] = true
		out.Add(dto.Entity{
			EntityType: dto.EntityStageTeam,
			ExternalID: key,
			Data: dto.StageTeamData{
				StageExternalID: stt.StageID,
				TeamExternalID:  stt.TeamID,
				GroupName:       groupOf[key],
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

// lsSeasonID находит Ls-id сезона по flash-id лиги и подписи лет («2026-2027» — как years у /Ls/Seasons).
func lsSeasonID(ctx context.Context, src Source, leagueFlashID, label string) (string, error) {
	seasons, err := src.Seasons(ctx, leagueFlashID)
	if err != nil {
		return "", err
	}
	want := strings.ReplaceAll(strings.TrimSpace(label), " ", "")
	for _, s := range seasons {
		if s.UniqueID != "" && strings.ReplaceAll(strings.TrimSpace(s.Years), " ", "") == want {
			return s.UniqueID, nil
		}
	}

	return "", &PermanentError{Msg: fmt.Sprintf("sync_structure: в /Ls/Seasons лиги %s нет сезона %s", leagueFlashID, label)}
}

func tablesByStage(tables []sstats.LsStandingTable) map[string][]sstats.LsStandingTable {
	byStage := map[string][]sstats.LsStandingTable{}
	for _, t := range tables {
		byStage[t.StageID] = append(byStage[t.StageID], t)
	}
	for _, list := range byStage {
		sort.SliceStable(list, func(i, j int) bool { return tableOrder(list[i]) < tableOrder(list[j]) })
	}

	return byStage
}

func tableOrder(t sstats.LsStandingTable) int {
	if t.SortOrder == nil {
		return 0
	}

	return int(*t.SortOrder)
}

func hasGroupStages(tables map[string][]sstats.LsStandingTable) bool {
	for _, list := range tables {
		if len(list) >= 2 {
			return true
		}
	}

	return false
}

// groupMembership: "stageId:teamId" → название группы по строкам таблиц /Ls/Standings.
func groupMembership(ctx context.Context, src Source, seasonID string) (map[string]string, error) {
	standings, err := src.Standings(ctx, seasonID)
	if err != nil {
		return map[string]string{}, err
	}
	groupOf := map[string]string{}
	for _, s := range standings {
		name := strings.TrimSpace(s.Table.Name)
		for _, row := range s.Rows {
			if name != "" && row.TeamID != "" {
				groupOf[s.Table.StageID+":"+row.TeamID] = name
			}
		}
	}

	return groupOf, nil
}

// roundOrdinal: номер тура таблицы либо порядковый номер раунда плей-офф (sortOrder).
func roundOrdinal(r sstats.LsRound) (int, bool) {
	if r.Number != nil && *r.Number >= 1 {
		return int(*r.Number), true
	}
	if r.SortOrder != nil && *r.SortOrder >= 1 {
		return int(*r.SortOrder), true
	}

	return 0, false
}

var roundFractionRe = regexp.MustCompile(`^1/(\d+)-?\s*finals?$`)

// knockoutRoundCode сводит названия раундов SStats («1/16-finals», «Semi-finals») к RoundCode Laravel.
func knockoutRoundCode(name string) string {
	n := strings.ToLower(strings.TrimSpace(name))
	switch n {
	case "final":
		return "F"
	case "semi-finals", "semi-final", "semifinals":
		return "SF"
	case "quarter-finals", "quarter-final", "quarterfinals":
		return "QF"
	case "3rd place", "third place", "3rd place final":
		return "THIRD"
	}
	if m := roundFractionRe.FindStringSubmatch(n); m != nil {
		if d, err := strconv.Atoi(m[1]); err == nil {
			switch d {
			case 8:
				return "R16"
			case 16:
				return "R32"
			case 32:
				return "R64"
			}
		}
	}

	return ""
}
