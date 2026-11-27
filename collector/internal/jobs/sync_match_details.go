package jobs

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"net/http"
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
