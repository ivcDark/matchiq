package sstats

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
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
