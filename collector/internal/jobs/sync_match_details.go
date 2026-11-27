package jobs

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"net/http"
	"sort"
	"strings"
	"time"
	"unicode"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

// maxMatchDetailsPerRun защищает от случайного «весь сезон» одной командой (один запрос к источнику на матч).
const maxMatchDetailsPerRun = 500

// SyncMatchDetails: счёт/статус, события, статистика и составы матчей по списку внешних id.
// Единственный эндпоинт — GET /Games/{id} (числовой id SStats из payload.match_api_ids, иначе flash-id).
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
	for _, id := range ids {
		apiID := strings.TrimSpace(p.MatchAPIIDs[id])
		if apiID == "" {
			apiID = id
		}

		game, err := src.Game(ctx, apiID)
		if err != nil {
			if fatalFetch(err) {
				return nil, err
			}
			out.AddError(fetchError(id, "Games/"+apiID, err))
			continue
		}

		data, errs := normalizeGame(id, game)
		for _, e := range errs {
			e.EntityType, e.ExternalID = dto.EntityMatchDetails, id
			out.AddError(e)
		}
		if data != nil {
			out.Add(dto.Entity{EntityType: dto.EntityMatchDetails, ExternalID: id, Data: *data})
		}
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
