package jobs

import (
	"net/url"
	"strings"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

func countryEntity(c sstats.LsCountry) (dto.Entity, bool) {
	id := c.Num.String()
	if id == "" || strings.TrimSpace(c.Name) == "" {
		return dto.Entity{}, false
	}

	code := strings.ToUpper(strings.TrimSpace(c.Abbr2))
	kind := dto.CountryKindCountry
	if isRegion(c) {
		code = strings.ToUpper(strings.TrimSpace(c.Abbr3))
		if code == "" {
			code = strings.ToUpper(strings.TrimSpace(c.ID))
		}
		kind = dto.CountryKindRegion
	}

	return dto.Entity{
		EntityType: dto.EntityCountry,
		ExternalID: id,
		Data:       dto.CountryData{Code: code, Name: strings.TrimSpace(c.Name), Kind: kind},
	}, code != ""
}

// isRegion: SStats описывает континенты и «мир» (Europe, Asia, World) с abbr2 = "ww" либо без abbr2.
func isRegion(c sstats.LsCountry) bool {
	abbr2 := strings.ToLower(strings.TrimSpace(c.Abbr2))

	return abbr2 == "" || abbr2 == "ww"
}

// absoluteURL оставляет только абсолютные URL: SStats Ls отдаёт и «имена файлов», их не передаём.
func absoluteURL(raw string) string {
	u, err := url.Parse(raw)
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") {
		return ""
	}

	return raw
}

func stageType(statsType string) (string, bool) {
	switch strings.ToLower(statsType) {
	case "table":
		return dto.StageTypeLeague, true
	case "draw":
		return dto.StageTypeKnockout, true
	}

	return "", false
}
