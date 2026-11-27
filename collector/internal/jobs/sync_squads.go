package jobs

import (
	"context"
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
	"time"
	"unicode"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

// maxSquadTeamsPerRun — защита от «все команды БД» одной командой (1–2 запроса к источнику на команду).
const maxSquadTeamsPerRun = 200

// SyncSquads: актуальные составы клубов по списку числовых id команд SStats.
// Эндпоинт: /Players/list?teamId= (игроки без позиции и номера). Позиция — из запасного /Ls/Players?teamId=<flash-id>:
// человек Ls сопоставляется с игроком по дате рождения и фамилии; его flash-id уходит Laravel для связи с игроками,
// заведёнными раньше из Ls. Состав команды публикуется только если /Players/list получен целиком и не пуст:
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
	players := map[string]dto.Entity{}
	var order []string
	var squads []dto.Entity
	today := time.Now().UTC().Format(dateLayout)

	for _, teamID := range ids {
		people, err := src.Players(ctx, teamID)
		if err != nil {
			if fatalFetch(err) {
				return nil, err
			}
			out.AddError(dto.EntityError{
				EntityType: dto.EntitySquad, ExternalID: teamID, Phase: dto.PhaseFetch,
				Message: "не удалось получить /Players/list: " + err.Error(),
			})
			continue
		}

		ls, err := lsSquad(ctx, src, teamID, strings.TrimSpace(p.TeamFlashIDs[teamID]), out)
		if err != nil {
			return nil, err
		}

		squad := dto.SquadData{PeopleSource: dto.PeopleSStatsAPI, TeamExternalID: teamID, Players: []dto.SquadPlayerData{}}
		seen := map[string]bool{}
		for _, person := range people {
			id := person.ID.String()
			if id == "" || seen[id] {
				continue
			}
			data := playerData(person, teamID)
			if data.Name == "" {
				out.AddError(dto.EntityError{
					EntityType: dto.EntityPlayer, ExternalID: id, Phase: dto.PhaseNormalize,
					Message: "у игрока нет имени", Context: map[string]any{"team_id": teamID},
				})
				continue
			}
			if match := ls.find(person); match != nil {
				data.FlashID = match.ID
				data.Position = profilePosition(deref(match.Role))
			}
			seen[id] = true
			if _, ok := players[id]; !ok {
				order = append(order, id)
			}
			players[id] = dto.Entity{EntityType: dto.EntityPlayer, ExternalID: id, Data: data}
			squad.Players = append(squad.Players, dto.SquadPlayerData{
				PlayerExternalID: id,
				Position:         data.Position,
				Absences:         absences(person.Unavailabilities, today),
			})
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

	for _, id := range order {
		out.Add(players[id])
	}
	for _, e := range squads {
		out.Add(e)
	}

	return out, nil
}

func playerData(p sstats.Player, teamID string) dto.PlayerData {
	first, last := strings.TrimSpace(deref(p.FirstName)), strings.TrimSpace(deref(p.LastName))
	name := strings.TrimSpace(first + " " + last)
	if first == "" || last == "" {
		name = strings.TrimSpace(p.Name)
	}
	data := dto.PlayerData{
		PeopleSource:   dto.PeopleSStatsAPI,
		Name:           name,
		ShortName:      strings.TrimSpace(p.Name),
		FirstName:      first,
		LastName:       last,
		Nationality:    strings.TrimSpace(deref(p.Nationality)),
		TeamExternalID: teamID,
		BirthDate:      validDate(deref(p.BirthDate)),
		PhotoURL:       absoluteURL(deref(p.PhotoURL)),
		HasProfile:     true,
	}
	if n, err := strconv.Atoi(strings.TrimSpace(p.Height.String())); err == nil && n >= 140 && n <= 230 {
		data.HeightCM = &n
	}
	if data.ShortName == data.Name {
		data.ShortName = ""
	}

	return data
}

// absences — действующие отсутствия игрока (без даты окончания или с окончанием не раньше сегодняшнего дня).
func absences(items []sstats.PlayerUnavailability, today string) []dto.AbsenceData {
	var out []dto.AbsenceData
	for _, u := range items {
		end := validDate(deref(u.End))
		if end != "" && end < today {
			continue
		}
		out = append(out, dto.AbsenceData{
			Type:           absenceType(u.TypeName),
			Reason:         strings.TrimSpace(u.TypeName),
			Since:          validDate(deref(u.Start)),
			ExpectedReturn: end,
		})
	}

	return out
}

// absenceType по typeName источника: дисквалификации и «под вопросом» распознаются по словам, остальное — травма.
func absenceType(name string) string {
	n := strings.ToLower(name)
	switch {
	case strings.Contains(n, "suspen"), strings.Contains(n, "card"), strings.Contains(n, "ban"), strings.Contains(n, "disqual"):
		return dto.AbsenceSuspension
	case strings.Contains(n, "doubt"), strings.Contains(n, "question"):
		return dto.AbsenceDoubtful
	}

	return dto.AbsenceInjury
}

// lsPeople — люди команды из /Ls/Players (без тренеров) для поиска позиции игрока.
type lsPeople []sstats.LsSquadPlayer

// lsSquad: сбой запасного источника не валит состав (кроме auth/лимита): игроки уйдут без позиции.
func lsSquad(ctx context.Context, src Source, teamID, flashID string, out *Output) (lsPeople, error) {
	if flashID == "" {
		return nil, nil
	}
	people, err := src.LsPlayers(ctx, flashID)
	if err != nil {
		if fatalFetch(err) {
			return nil, err
		}
		out.AddError(dto.EntityError{
			EntityType: dto.EntitySquad, ExternalID: teamID, Phase: dto.PhaseFetch,
			Message: "не удалось получить позиции игроков из /Ls/Players: " + err.Error(),
			Context: map[string]any{"endpoint": "Ls/Players", "team_flash_id": flashID},
		})

		return nil, nil
	}

	var res lsPeople
	for _, person := range people {
		if strings.TrimSpace(person.ID) != "" && !isCoachRole(person.Role) {
			res = append(res, person)
		}
	}

	return res, nil
}

// find — единственный человек Ls с той же фамилией и датой рождения; без даты рождения у любой стороны —
// с той же фамилией и первой буквой имени. Иначе — единственный в составе с той же датой рождения и хотя бы одним общим
// словом имени (у бразильцев в Ls часто только игровое имя: «Wendel» против «Marcus Wendel Valle da Silva»).
// Неоднозначно — nil.
func (ls lsPeople) find(p sstats.Player) *sstats.LsSquadPlayer {
	surnames := nameTokens(firstNonEmpty(deref(p.LastName), lastWord(p.Name)))
	if len(surnames) == 0 {
		return nil
	}
	birth := validDate(deref(p.BirthDate))
	initial := firstLetter(firstNonEmpty(deref(p.FirstName), p.Name))

	var found *sstats.LsSquadPlayer
	for i := range ls {
		person := &ls[i]
		tokens := nameTokens(person.Name + " " + deref(person.ShortName))
		if !overlaps(surnames, tokens) {
			continue
		}
		lsBirth := validDate(deref(person.Birthdate))
		if birth != "" && lsBirth != "" {
			if birth != lsBirth {
				continue
			}
		} else if initial == "" || !hasInitial(tokens, surnames, initial) {
			continue
		}
		if found != nil {
			return nil
		}
		found = person
	}
	if found != nil || birth == "" {
		return found
	}

	var sameBirth []*sstats.LsSquadPlayer
	for i := range ls {
		if validDate(deref(ls[i].Birthdate)) == birth {
			sameBirth = append(sameBirth, &ls[i])
		}
	}
	if len(sameBirth) != 1 {
		return nil
	}
	words := significant(nameTokens(deref(p.FirstName) + " " + deref(p.LastName) + " " + p.Name))
	if !overlaps(words, significant(nameTokens(sameBirth[0].Name+" "+deref(sameBirth[0].ShortName)))) {
		return nil
	}

	return sameBirth[0]
}

// significant — слова имени без коротких частиц (da, dos, de): по ним одних совпадений недостаточно.
func significant(tokens []string) []string {
	var out []string
	for _, t := range tokens {
		if len([]rune(t)) >= 3 {
			out = append(out, t)
		}
	}

	return out
}

func isCoachRole(role *string) bool {
	return role != nil && strings.EqualFold(strings.TrimSpace(*role), "coach")
}

func deref(s *string) string {
	if s == nil {
		return ""
	}

	return *s
}

func lastWord(name string) string {
	f := strings.Fields(name)
	if len(f) == 0 {
		return ""
	}

	return f[len(f)-1]
}

func firstLetter(s string) string {
	for _, t := range nameTokens(s) {
		return t[:1]
	}

	return ""
}

// hasInitial: среди слов имени Ls, кроме фамилии, есть начинающееся на букву имени игрока.
func hasInitial(tokens, surnames []string, initial string) bool {
	for _, t := range tokens {
		if !contains(surnames, t) && strings.HasPrefix(t, initial) {
			return true
		}
	}

	return false
}

func overlaps(a, b []string) bool {
	for _, x := range a {
		if len(x) >= 2 && contains(b, x) {
			return true
		}
	}

	return false
}

func contains(list []string, v string) bool {
	for _, x := range list {
		if x == v {
			return true
		}
	}

	return false
}

// nameTokens — слова имени в нижнем регистре без диакритики и точек («W. Barrios Terán» → w, barrios, teran).
func nameTokens(s string) []string {
	var b strings.Builder
	for _, r := range strings.ToLower(s) {
		if f, ok := latinFold[r]; ok {
			b.WriteString(f)
			continue
		}
		if unicode.IsLetter(r) {
			b.WriteRune(r)
		} else {
			b.WriteRune(' ')
		}
	}

	return strings.Fields(b.String())
}

var latinFold = map[rune]string{
	'á': "a", 'à': "a", 'â': "a", 'ä': "a", 'ã': "a", 'å': "a", 'ā': "a", 'ă': "a", 'ą': "a",
	'ç': "c", 'ć': "c", 'č': "c", 'ď': "d", 'đ': "d",
	'é': "e", 'è': "e", 'ê': "e", 'ë': "e", 'ē': "e", 'ę': "e", 'ě': "e",
	'í': "i", 'ì': "i", 'î': "i", 'ï': "i", 'ī': "i", 'ı': "i",
	'ñ': "n", 'ń': "n", 'ň': "n",
	'ó': "o", 'ò': "o", 'ô': "o", 'ö': "o", 'õ': "o", 'ø': "o", 'ō': "o", 'ő': "o",
	'ř': "r", 'ś': "s", 'š': "s", 'ş': "s", 'ș': "s", 'ť': "t", 'ţ': "t", 'ț': "t",
	'ú': "u", 'ù': "u", 'û': "u", 'ü': "u", 'ū': "u", 'ů': "u", 'ű': "u",
	'ý': "y", 'ÿ': "y", 'ź': "z", 'ż': "z", 'ž': "z", 'ß': "ss", 'ł': "l", 'æ': "ae", 'œ': "oe",
}
