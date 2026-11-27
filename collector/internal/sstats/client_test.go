package sstats

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func TestLeaguesSendsAPIKeyAndParsesFlexIDs(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/Ls/Leagues" || r.URL.Query().Get("apikey") != "secret" || r.URL.Query().Get("name") != "Premier" {
			t.Errorf("unexpected request %s", r.URL.String())
		}
		_, _ = w.Write([]byte(`{"status":"OK","data":[{"id":"AbCd1234","name":"Premier League","country":{"num":189,"id":"russia","name":"Russia","abbr2":"ru"}}]}`))
	}))
	defer srv.Close()

	s := NewClient(Options{BaseURL: srv.URL, APIKey: "secret"}).Session()
	leagues, err := s.Leagues(context.Background(), "Premier")
	if err != nil {
		t.Fatal(err)
	}
	if len(leagues) != 1 || leagues[0].Country.Num.String() != "189" || s.Requests() != 1 {
		t.Fatalf("leagues=%+v requests=%d", leagues, s.Requests())
	}
}

func TestRetriesOnServerErrorAndCountsRequests(t *testing.T) {
	var calls atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if calls.Add(1) < 3 {
			w.WriteHeader(http.StatusBadGateway)
			return
		}
		_, _ = w.Write([]byte(`{"status":"OK","data":[]}`))
	}))
	defer srv.Close()

	s := NewClient(Options{BaseURL: srv.URL, RetryBaseDelay: time.Millisecond}).Session()
	if _, err := s.Seasons(context.Background(), "x"); err != nil {
		t.Fatal(err)
	}
	if s.Requests() != 3 {
		t.Fatalf("requests = %d", s.Requests())
	}
}

func TestUnauthorizedIsNotRetried(t *testing.T) {
	var calls atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		w.WriteHeader(http.StatusUnauthorized)
	}))
	defer srv.Close()

	s := NewClient(Options{BaseURL: srv.URL, RetryBaseDelay: time.Millisecond}).Session()
	_, err := s.Seasons(context.Background(), "x")
	if !errors.Is(err, ErrUnauthorized) || calls.Load() != 1 || Retryable(err) {
		t.Fatalf("err=%v calls=%d", err, calls.Load())
	}
}

func TestRateLimitedCounted(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusTooManyRequests)
	}))
	defer srv.Close()

	s := NewClient(Options{BaseURL: srv.URL, MaxRetries: 1, RetryBaseDelay: time.Millisecond}).Session()
	_, err := s.Seasons(context.Background(), "x")
	if !Retryable(err) || s.RateLimited() != 2 {
		t.Fatalf("err=%v rateLimited=%d", err, s.RateLimited())
	}
}

func TestFixturesPaginatesAndSendsFilters(t *testing.T) {
	var offsets []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		q := r.URL.Query()
		if r.URL.Path != "/Ls/List" || q.Get("SeasonId") != "SeAs2026" || q.Get("StageId") != "StG00001" ||
			q.Get("From") != "2026-08-01T00:00:00" || q.Get("To") != "2026-09-01T00:00:00" || q.Get("TimeZone") != "0" {
			t.Errorf("unexpected request %s", r.URL.String())
		}
		offsets = append(offsets, q.Get("Offset"))
		n := 1000
		if q.Get("Offset") == "1000" {
			n = 2
		}
		items := make([]string, n)
		for i := range items {
			items[i] = `{"id":"m","homeResult":null,"awayResult":"2","status":3,"dateUtc":1784912400}`
		}
		_, _ = w.Write([]byte(`{"status":"OK","data":[` + strings.Join(items, ",") + `]}`))
	}))
	defer srv.Close()

	s := NewClient(Options{BaseURL: srv.URL}).Session()
	got, err := s.Fixtures(context.Background(), FixturesQuery{
		SeasonID: "SeAs2026", StageID: "StG00001",
		From: time.Date(2026, 8, 1, 0, 0, 0, 0, time.UTC), To: time.Date(2026, 9, 1, 0, 0, 0, 0, time.UTC),
	})
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 1002 || len(offsets) != 2 || offsets[1] != "1000" {
		t.Fatalf("matches=%d offsets=%v", len(got), offsets)
	}
	if got[0].HomeResult != nil || got[0].AwayResult == nil || int(*got[0].AwayResult) != 2 || got[0].Status != 3 {
		t.Fatalf("decoded = %+v", got[0])
	}
}

func TestGameInfoParsesStatisticsAndAPIStatusError(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Query().Get("id") {
		case "Mat00001":
			if r.URL.Path != "/Ls/GameInfo" {
				t.Errorf("unexpected path %s", r.URL.Path)
			}
			_, _ = w.Write([]byte(`{"status":"OK","data":{"game":{"id":"Mat00001","homeTeamId":"A","awayTeamId":"B"},"events":[{"time":"12","actions":[{"actionType":3}]}],
				"statistics":{"fullTime":{"metrics":[{"id":1}],"expectedGoals":{"home":"1.5","away":0.25},"otherStats":{"Goal kicks":{"home":"8","away":"11"}}}}}}`))
		default:
			_, _ = w.Write([]byte(`{"status":"error","message":"match not found"}`))
		}
	}))
	defer srv.Close()

	s := NewClient(Options{BaseURL: srv.URL, APIKey: "k"}).Session()
	info, err := s.GameInfo(context.Background(), "Mat00001")
	if err != nil {
		t.Fatal(err)
	}
	full := info.Statistics["fullTime"]
	xg := full.Metrics["expectedGoals"]
	if info.Game.HomeTeamID != "A" || len(info.Events) != 1 || info.Events[0].Time != 12 || xg.Home == nil || *xg.Home != 1.5 || *xg.Away != 0.25 {
		t.Fatalf("info = %+v", info)
	}
	if _, isMetric := full.Metrics["metrics"]; isMetric || full.OtherStats["Goal kicks"]["away"] != "11" {
		t.Fatalf("statistics = %+v", full)
	}

	_, err = s.GameInfo(context.Background(), "Nope0000")
	var apiErr *APIError
	if !errors.As(err, &apiErr) || !strings.Contains(err.Error(), "match not found") {
		t.Fatalf("err = %v", err)
	}
	if s.Requests() != 2 {
		t.Fatalf("status=error must not be retried, requests = %d", s.Requests())
	}
}

func TestPlayersPagesByTeamAndParsesNullableProfile(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		q := r.URL.Query()
		if r.URL.Path != "/Ls/Players" || q.Get("teamId") != "vsXou9m7" || q.Get("limit") != "1000" || q.Get("offset") != "0" {
			t.Errorf("unexpected request %s", r.URL.String())
		}
		_, _ = w.Write([]byte(`{"status":"OK","data":[
			{"id":"0UR7zPPo","name":"Kirill Obonin","role":"Defender","birthdate":"2006-07-05","country":{"num":158,"id":"russia","name":"Russia","abbr2":"ru"},"profileUpdatedAt":"2026-11-25T14:50:54+03:00"},
			{"id":"8QFFlwyr","name":"Pedrinho","role":null,"birthdate":null,"country":null,"profileUpdatedAt":null}]}`))
	}))
	defer srv.Close()

	s := NewClient(Options{BaseURL: srv.URL}).Session()
	people, err := s.Players(context.Background(), "vsXou9m7")
	if err != nil {
		t.Fatal(err)
	}
	if len(people) != 2 || people[0].ProfileUpdatedAt == nil || people[1].ProfileUpdatedAt != nil || people[1].Role != nil || s.Requests() != 1 {
		t.Fatalf("people = %+v requests=%d", people, s.Requests())
	}
}

func TestGamesByFlashIDsChunksAndParsesMappings(t *testing.T) {
	var calls atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		q := r.URL.Query()
		if r.URL.Path != "/Games/list" || q.Get("Limit") != "100" || q.Get("apikey") != "secret" {
			t.Errorf("unexpected request %s", r.URL.String())
		}
		ids := strings.Split(q.Get("FlashId"), ",")
		if len(ids) > 100 {
			t.Errorf("chunk too large: %d", len(ids))
		}
		_, _ = w.Write([]byte(`{"status":"OK","data":[{"id":1577610,"flashId":"IPBoDE9D","mappings":{"flashscore":"IPBoDE9D","pari":"70159794"},
			"homeTeam":{"id":3985,"flashId":"OC7sNO6b","mappings":{"flashscore":["OC7sNO6b"],"pari":["420773"]}},
			"awayTeam":{"id":6811,"flashId":null,"mappings":{}},
			"season":{"league":{"id":236,"flashScoreId":"jizXHcsM","mappings":{"pari":["80073"]}}}}]}`))
	}))
	defer srv.Close()

	ids := make([]string, 250)
	for i := range ids {
		ids[i] = "Id" + strings.Repeat("x", 6)
	}
	s := NewClient(Options{BaseURL: srv.URL, APIKey: "secret"}).Session()
	games, err := s.GamesByFlashIDs(context.Background(), ids)
	if err != nil {
		t.Fatal(err)
	}
	if calls.Load() != 3 || len(games) != 3 {
		t.Fatalf("calls=%d games=%d", calls.Load(), len(games))
	}
	g := games[0]
	if g.ID.String() != "1577610" || g.Mappings["pari"].First() != "70159794" || g.Home.ID.String() != "3985" ||
		g.Home.Mappings["flashscore"].First() != "OC7sNO6b" || g.Away.FlashID != "" || g.Season.League.Mappings["pari"].First() != "80073" {
		t.Fatalf("game = %+v", g)
	}
}
