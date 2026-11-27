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

func TestLeaguesSendsAPIKeyAndParsesSeasons(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/Leagues" || r.URL.Query().Get("apikey") != "secret" {
			t.Errorf("unexpected request %s", r.URL.String())
		}
		_, _ = w.Write([]byte(`{"status":"OK","data":[{"mappings":{"flashscore":["jizXHcsM"],"pari":["80073"]},"id":236,"name":"First League",
			"country":{"code":"RU","name":"Russia"},"flashScoreId":"jizXHcsM",
			"seasons":[{"uid":"0159bf63-71ef-11f1-9836-3cecef730a49","year":2026,"dateStart":"2026-07-11T00:00:00","dateEnd":"2026-12-06T00:00:00","flashScoreId":null}]},
			{"mappings":{},"id":651,"name":"Second League - Group 1","country":{"code":"RU","name":"Russia"},"seasons":null}]}`))
	}))
	defer srv.Close()

	s := NewClient(Options{BaseURL: srv.URL, APIKey: "secret"}).Session()
	leagues, err := s.Leagues(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if len(leagues) != 2 || leagues[0].ID.String() != "236" || leagues[0].Country.Code != "RU" || leagues[0].Mappings["pari"].First() != "80073" ||
		len(leagues[0].Seasons) != 1 || int(leagues[0].Seasons[0].Year) != 2026 || leagues[0].Seasons[0].FlashScoreID != nil ||
		leagues[1].FlashScoreID != "" || leagues[1].Seasons != nil || s.Requests() != 1 {
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
			items[i] = `{"id":"m","stageId":"StG00001","roundId":77,"roundName":"Round 3"}`
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
	if got[0].StageID != "StG00001" || got[0].RoundID.String() != "77" {
		t.Fatalf("decoded = %+v", got[0])
	}
}

func TestGamesPaginatesBySeasonAndParsesListGame(t *testing.T) {
	var offsets []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		q := r.URL.Query()
		if r.URL.Path != "/Games/list" || q.Get("SeasonUid") != "0159bf63" || q.Get("LeagueId") != "" || q.Get("TimeZone") != "0" ||
			q.Get("From") != "2026-08-01T00:00:00" || q.Get("Limit") != "1000" || q.Get("Order") != "1" {
			t.Errorf("unexpected request %s", r.URL.String())
		}
		offsets = append(offsets, q.Get("Offset"))
		n := 1000
		if q.Get("Offset") == "1000" {
			n = 1
		}
		items := make([]string, n)
		for i := range items {
			items[i] = `{"mappings":{"flashscore":"xWXoFJwP","pari":"66244605"},"id":1563072,"flashId":"xWXoFJwP","dateUtc":1783771200,"status":8,
				"statusName":"Finished","homeResult":3,"awayResult":0,"homeHTResult":2,"awayHTResult":0,"homeFTResult":3,"awayFTResult":0,
				"homeTeam":{"mappings":{"flashscore":["fPxiAl6T"]},"id":1078,"name":"FC UFA","flashId":"fPxiAl6T","country":{"code":"RU","name":"Russia"}},
				"awayTeam":{"mappings":{},"id":6811,"name":"Leningradets","flashId":null,"country":null},
				"season":{"uid":"0159bf63","year":2026,"league":{"id":236,"flashScoreId":"jizXHcsM","mappings":{}}},
				"roundName":"Regular Season - 1","odds":[{"marketId":1,"marketName":null,"odds":[{"name":"Home","value":2.07},{"name":"Draw","value":"3.1"}]}]}`
		}
		_, _ = w.Write([]byte(`{"status":"OK","data":[` + strings.Join(items, ",") + `]}`))
	}))
	defer srv.Close()

	s := NewClient(Options{BaseURL: srv.URL}).Session()
	got, err := s.Games(context.Background(), GamesQuery{SeasonUID: "0159bf63", From: time.Date(2026, 8, 1, 0, 0, 0, 0, time.UTC)})
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 1001 || len(offsets) != 2 {
		t.Fatalf("games=%d offsets=%v", len(got), offsets)
	}
	g := got[0]
	if g.ID.String() != "1563072" || int(*g.Status) != 8 || g.Away.FlashID != "" || g.Away.ID.String() != "6811" || g.Home.Country.Code != "RU" ||
		g.Season.UID != "0159bf63" || g.Mappings["pari"].First() != "66244605" || len(g.Odds) != 1 || float64(g.Odds[0].Odds[1].Value) != 3.1 {
		t.Fatalf("game = %+v", g)
	}

	if _, err := s.Games(context.Background(), GamesQuery{LeagueID: "236"}); err == nil {
		t.Fatal("season is required")
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

func TestPlayersListPagesByTeam(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		q := r.URL.Query()
		if r.URL.Path != "/Players/list" || q.Get("teamId") != "596" || q.Get("limit") != "1000" || q.Get("offset") != "0" {
			t.Errorf("unexpected request %s", r.URL.String())
		}
		_, _ = w.Write([]byte(`{"status":"OK","data":[
			{"firstName":"Igor","lastName":"Diveev","nationality":"Russia","height":"194","birthDate":"1999-09-27","photoUrl":"https://sstats.net/assets/player_photos/826.png",
			 "unavailabilities":[{"start":"2026-11-01","end":null,"typeId":1,"typeName":"Injury"}],"id":826,"name":"I. Diveev"},
			{"firstName":null,"lastName":null,"nationality":null,"height":null,"birthDate":null,"photoUrl":null,"unavailabilities":null,"id":"9","name":"Pedrinho"}]}`))
	}))
	defer srv.Close()

	s := NewClient(Options{BaseURL: srv.URL}).Session()
	people, err := s.Players(context.Background(), "596")
	if err != nil {
		t.Fatal(err)
	}
	if len(people) != 2 || people[0].ID.String() != "826" || people[0].Height.String() != "194" || len(people[0].Unavailabilities) != 1 ||
		people[1].FirstName != nil || people[1].Unavailabilities != nil || s.Requests() != 1 {
		t.Fatalf("people = %+v requests=%d", people, s.Requests())
	}
}

func TestLsPlayersPagesByFlashTeam(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		q := r.URL.Query()
		if r.URL.Path != "/Ls/Players" || q.Get("teamId") != "vsXou9m7" || q.Get("limit") != "1000" {
			t.Errorf("unexpected request %s", r.URL.String())
		}
		_, _ = w.Write([]byte(`{"status":"OK","data":[{"id":"0UR7zPPo","name":"Kirill Obonin","role":"Defender","birthdate":"2006-07-05"},
			{"id":"8QFFlwyr","name":"Pedrinho","role":null,"birthdate":null}]}`))
	}))
	defer srv.Close()

	s := NewClient(Options{BaseURL: srv.URL}).Session()
	people, err := s.LsPlayers(context.Background(), "vsXou9m7")
	if err != nil {
		t.Fatal(err)
	}
	if len(people) != 2 || *people[0].Role != "Defender" || people[1].Role != nil {
		t.Fatalf("people = %+v", people)
	}
}
