package jobs

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

// maxSquadTeamsPerRun — защита от «все команды БД» одной командой (1+ запрос к источнику на команду).
const maxSquadTeamsPerRun = 200

// SyncSquads: актуальные составы клубов по списку внешних id команд.
// Эндпоинт: /Ls/Players?teamId= (каталог людей по текущей команде профиля; ProfileUpdatedAt может быть null).
// Тренеры (role = Coach) пропускаются. Состав команды публикуется только если он получен целиком и не пуст:
// Laravel по нему закрывает заявки игроков, которых в составе больше нет.
type SyncSquads struct{}

func (SyncSquads) Collect(ctx context.Context, src Source, cmd dto.Command) (*Output, error) {
	var p dto.SquadsPayload
	if err := json.Unmarshal(cmd.Payload, &p); err != nil {
		return nil, &PermanentError{Msg: "invalid sync_squads payload: " + err.Error()}
	}

	ids := uniqueIDs(p.TeamExternalIDs)
	if len(ids) == 0 {
		return nil, &PermanentError{Msg: "sync_squads requires team_external_ids"}
	}
	if len(ids) > maxSquadTeamsPerRun {
		return nil, &PermanentError{Msg: fmt.Sprintf("sync_squads: не более %d команд за прогон, получено %d", maxSquadTeamsPerRun, len(ids))}
	}

	out := &Output{}
	reg := newPeopleRegistry()
	var squads []dto.Entity

	for _, teamID := range ids {
		people, err := src.Players(ctx, teamID)
		if err != nil {
			if fatalFetch(err) {
				return nil, err
			}
			out.AddError(dto.EntityError{
				EntityType: dto.EntitySquad, ExternalID: teamID, Phase: dto.PhaseFetch,
				Message: "не удалось получить /Ls/Players: " + err.Error(),
			})
			continue
		}

		squad := dto.SquadData{TeamExternalID: teamID, Players: []dto.SquadPlayerData{}}
		seen := map[string]bool{}
		for _, person := range people {
			if isCoachRole(person.Role) {
				continue
			}
			id := strings.TrimSpace(person.ID)
			if id == "" || seen[id] {
				continue
			}
			if !reg.player(id, person.Name, teamID, squadPosition(person), squadProfile(person)) {
				out.AddError(dto.EntityError{
					EntityType: dto.EntityPlayer, ExternalID: id, Phase: dto.PhaseNormalize,
					Message: "у игрока нет имени", Context: map[string]any{"team_id": teamID},
				})
				continue
			}
			seen[id] = true
			squad.Players = append(squad.Players, dto.SquadPlayerData{PlayerExternalID: id, Position: squadPosition(person)})
		}

		if len(squad.Players) == 0 {
			out.AddError(dto.EntityError{
				EntityType: dto.EntitySquad, ExternalID: teamID, Phase: dto.PhaseNormalize,
				Message: "источник не вернул игроков команды",
			})
			continue
		}
		squads = append(squads, dto.Entity{EntityType: dto.EntitySquad, ExternalID: teamID, Data: squad})
	}

	for _, e := range reg.entities() {
		out.Add(e)
	}
	for _, e := range squads {
		out.Add(e)
	}

	return out, nil
}

func isCoachRole(role *string) bool {
	return role != nil && strings.EqualFold(strings.TrimSpace(*role), "coach")
}

func squadPosition(p sstats.LsSquadPlayer) string {
	if p.Role == nil {
		return ""
	}

	return profilePosition(*p.Role)
}

// squadProfile — профиль для реестра людей; nil, пока источник не получил полный профиль (ProfileUpdatedAt = null).
func squadProfile(p sstats.LsSquadPlayer) *sstats.LsPlayerProfile {
	if p.ProfileUpdatedAt == nil || strings.TrimSpace(*p.ProfileUpdatedAt) == "" {
		return nil
	}
	str := func(s *string) string {
		if s == nil {
			return ""
		}

		return *s
	}

	return &sstats.LsPlayerProfile{
		ID:        p.ID,
		Name:      p.Name,
		ShortName: str(p.ShortName),
		Role:      str(p.Role),
		Birthdate: str(p.Birthdate),
		PhotoURL:  str(p.PhotoURL),
		Country:   p.Country,
	}
}
