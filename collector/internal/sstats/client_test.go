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

func TestGameParsesNullableSectionsAndNotFound(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/Games/1577610":
			_, _ = w.Write([]byte(`{"status":"OK","data":{
				"game":{"id":1577610,"flashId":"IPBoDE9D","status":3,"statusName":"First Half","elapsed":42,"homeResult":1,"awayResult":0,"homeHTResult":null,
					"homeTeam":{"id":3985,"flashId":"OC7sNO6b"},"awayTeam":{"id":1087,"flashId":null}},
				"statistics":{"totalShotsHome":null,"totalShotsAway":7,"expectedGoalsHome":1.5},
				"lineups":{"homeFormation":"4-3-3","awayFormation":null},
				"lineupPlayers":[{"teamId":3985,"playerId":null,"playerName":"Ilya","number":"9","position":"M","grid":null,"startXI":true}],
				"playerStats":null,
				"events":[{"id":8836955,"teamId":3985,"elapsed":28,"extra":null,"type":1,"name":"Normal Goal","player":{"id":null,"name":null},"assistPlayer":null},
					{"id":8836957,"teamId":"3985","elapsed":"26","extra":2,"type":3,"name":"Substitution 1","player":{"id":342299,"name":"N. Bozov"},"assistPlayer":{"id":478098,"name":"V. Churilov"}}]}}`))
		default:
			w.WriteHeader(http.StatusBadRequest)
			_, _ = w.Write([]byte(`{"status":"error","data":null,"message":"Матч с id = 1 не найден"}`))
		}
	}))
	defer srv.Close()

	s := NewClient(Options{BaseURL: srv.URL, APIKey: "k"}).Session()
	g, err := s.Game(context.Background(), "1577610")
	if err != nil {
		t.Fatal(err)
	}
	if g.Game.ID != "1577610" || g.Game.FlashID != "IPBoDE9D" || *g.Game.Elapsed != 42 || g.Game.HomeHT != nil || g.Game.AwayTeam.FlashID != "" || g.Game.AwayTeam.ID != "1087" {
		t.Fatalf("game = %+v", g.Game)
	}
	if g.Statistics["totalShotsHome"] != nil || g.Statistics["totalShotsAway"] != 7.0 || g.PlayerStats != nil {
		t.Fatalf("statistics = %+v", g.Statistics)
	}
	if g.Lineups == nil || *g.Lineups.HomeFormation != "4-3-3" || g.Lineups.AwayFormation != nil {
		t.Fatalf("lineups = %+v", g.Lineups)
	}
	if len(g.LineupPlayers) != 1 || g.LineupPlayers[0].PlayerID != "" || *g.LineupPlayers[0].Number != 9 {
		t.Fatalf("lineup players = %+v", g.LineupPlayers)
	}
	goal, sub := g.Events[0], g.Events[1]
	if goal.Player == nil || goal.Player.ID != "" || goal.Extra != nil || goal.AssistPlayer != nil {
		t.Fatalf("goal = %+v", goal)
	}
	if sub.TeamID != "3985" || sub.Elapsed != 26 || *sub.Extra != 2 || sub.AssistPlayer.ID != "478098" {
		t.Fatalf("substitution = %+v", sub)
	}

	_, err = s.Game(context.Background(), "1")
	var statusErr *StatusError
	if !errors.As(err, &statusErr) || statusErr.Code != http.StatusBadRequest || Retryable(err) {
		t.Fatalf("err = %v", err)
	}
	if s.Requests() != 2 {
		t.Fatalf("400 must not be retried, requests = %d", s.Requests())
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
