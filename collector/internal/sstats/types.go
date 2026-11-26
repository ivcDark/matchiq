package sstats

import (
	"bytes"
	"encoding/json"
	"strconv"
)

// FlexString принимает JSON-строку или число (SStats отдаёт id в обоих видах).
type FlexString string

func (f *FlexString) UnmarshalJSON(b []byte) error {
	b = bytes.TrimSpace(b)
	if string(b) == "null" {
		*f = ""
		return nil
	}
	if len(b) > 0 && b[0] == '"' {
		var s string
		if err := json.Unmarshal(b, &s); err != nil {
			return err
		}
		*f = FlexString(s)
		return nil
	}
	var n json.Number
	if err := json.Unmarshal(b, &n); err != nil {
		return err
	}
	*f = FlexString(n.String())
	return nil
}

func (f FlexString) String() string { return string(f) }

// FlexInt принимает число или числовую строку.
type FlexInt int

func (f *FlexInt) UnmarshalJSON(b []byte) error {
	var s FlexString
	if err := s.UnmarshalJSON(b); err != nil {
		return err
	}
	if s == "" {
		*f = 0
		return nil
	}
	n, err := strconv.Atoi(string(s))
	if err != nil {
		return err
	}
	*f = FlexInt(n)
	return nil
}

type envelope[T any] struct {
	Status  string `json:"status"`
	Message string `json:"message"`
	Data    T      `json:"data"`
}

type LsCountry struct {
	Num   FlexString `json:"num"`
	ID    string     `json:"id"`
	Name  string     `json:"name"`
	Abbr2 string     `json:"abbr2"`
	Abbr3 string     `json:"abbr3"`
}

type LsLeague struct {
	ID      string    `json:"id"`
	UID     string    `json:"uid"`
	Name    string    `json:"name"`
	AliasID string    `json:"aliasId"`
	LogoURL string    `json:"logoUrl"`
	Country LsCountry `json:"country"`
}

type LsSeason struct {
	UniqueID        string `json:"uniqueId"`
	TournamentID    string `json:"tournamentId"`
	IsCurrentSeason bool   `json:"isCurrentSeason"`
	Years           string `json:"years"`
}

type LsTeam struct {
	UniqueID  string     `json:"uniqueId"`
	Name      string     `json:"name"`
	ShortName string     `json:"shortName"`
	LogoURL   string     `json:"logoUrl"`
	CountryID FlexString `json:"countryId"`
	Country   *LsCountry `json:"country"`
}

type LsStage struct {
	ID        string   `json:"id"`
	SeasonID  string   `json:"seasonId"`
	Name      string   `json:"name"`
	StatsType string   `json:"statsType"`
	SortOrder *FlexInt `json:"sortOrder"`
}

type LsSeasonStructure struct {
	Season LsSeason  `json:"season"`
	Stages []LsStage `json:"stages"`
	Teams  []LsTeam  `json:"teams"`
}
