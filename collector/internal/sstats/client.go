// Package sstats — HTTP-клиент SStats.net (rate limit, ретраи, разбор ответов).
package sstats

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

var (
	ErrUnauthorized = errors.New("sstats: invalid or missing apikey")
	ErrRateLimited  = errors.New("sstats: rate limit exceeded")
)

// StatusError — неуспешный ответ источника.
type StatusError struct {
	Code    int
	Message string
}

func (e *StatusError) Error() string {
	return fmt.Sprintf("sstats: http %d: %s", e.Code, e.Message)
}

// APIError — HTTP 200 со status=error в теле (например, неизвестный матч). Повтор бессмыслен.
type APIError struct{ Message string }

func (e *APIError) Error() string {
	if e.Message == "" {
		return "sstats: status=error"
	}

	return "sstats: status=error: " + e.Message
}

func (e *StatusError) Retryable() bool {
	return e.Code >= 500 || e.Code == http.StatusTooManyRequests
}

// Retryable сообщает, имеет ли смысл повторить команду позже.
func Retryable(err error) bool {
	var se *StatusError
	if errors.As(err, &se) {
		return se.Retryable()
	}

	return errors.Is(err, ErrRateLimited) || errors.Is(err, context.DeadlineExceeded)
}

type Options struct {
	BaseURL        string
	APIKey         string
	HTTPClient     *http.Client
	RatePerMinute  int
	MaxRetries     int
	RetryBaseDelay time.Duration
}

type Client struct {
	opts    Options
	limiter *limiter
}

func NewClient(opts Options) *Client {
	if opts.HTTPClient == nil {
		opts.HTTPClient = &http.Client{Timeout: 30 * time.Second}
	}
	if opts.MaxRetries == 0 {
		opts.MaxRetries = 3
	}
	if opts.RetryBaseDelay == 0 {
		opts.RetryBaseDelay = time.Second
	}
	opts.BaseURL = strings.TrimRight(opts.BaseURL, "/")

	return &Client{opts: opts, limiter: newLimiter(opts.RatePerMinute)}
}

// Session — клиент с отдельными счётчиками запросов (на одну команду).
func (c *Client) Session() *Session {
	return &Session{client: c}
}

type Session struct {
	client      *Client
	requests    atomic.Int64
	rateLimited atomic.Int64
}

func (s *Session) Requests() int    { return int(s.requests.Load()) }
func (s *Session) RateLimited() int { return int(s.rateLimited.Load()) }

func (s *Session) Leagues(ctx context.Context, name string) ([]LsLeague, error) {
	q := url.Values{}
	if name != "" {
		q.Set("name", name)
	}

	return get[[]LsLeague](ctx, s, "/Ls/Leagues", q)
}

func (s *Session) Seasons(ctx context.Context, leagueID string) ([]LsSeason, error) {
	return get[[]LsSeason](ctx, s, "/Ls/Seasons", url.Values{"leagueId": {leagueID}})
}

func (s *Session) SeasonStructure(ctx context.Context, seasonID string) (LsSeasonStructure, error) {
	return get[LsSeasonStructure](ctx, s, "/Ls/SeasonStructure", url.Values{"seasonId": {seasonID}})
}

func (s *Session) Standings(ctx context.Context, seasonID string) ([]LsStandings, error) {
	return get[[]LsStandings](ctx, s, "/Ls/Standings", url.Values{"seasonId": {seasonID}})
}

const (
	fixturesPageSize = 1000
	fixturesMaxPages = 50
)

// Fixtures возвращает все матчи по фильтрам, листая /Ls/List по Offset.
func (s *Session) Fixtures(ctx context.Context, f FixturesQuery) ([]LsFixture, error) {
	var all []LsFixture
	for page := 0; page < fixturesMaxPages; page++ {
		q := url.Values{
			"Limit":    {strconv.Itoa(fixturesPageSize)},
			"Offset":   {strconv.Itoa(page * fixturesPageSize)},
			"Order":    {"1"},
			"TimeZone": {"0"},
		}
		if f.SeasonID != "" {
			q.Set("SeasonId", f.SeasonID)
		}
		if f.StageID != "" {
			q.Set("StageId", f.StageID)
		}
		if !f.From.IsZero() {
			q.Set("From", f.From.UTC().Format("2006-01-02T15:04:05"))
		}
		if !f.To.IsZero() {
			q.Set("To", f.To.UTC().Format("2006-01-02T15:04:05"))
		}

		items, err := get[[]LsFixture](ctx, s, "/Ls/List", q)
		if err != nil {
			return nil, err
		}
		all = append(all, items...)
		if len(items) < fixturesPageSize {
			return all, nil
		}
	}

	return nil, fmt.Errorf("sstats: /Ls/List вернул больше %d страниц", fixturesMaxPages)
}

// GameInfo — события и командная статистика матча.
func (s *Session) GameInfo(ctx context.Context, matchID string) (LsGameInfo, error) {
	return get[LsGameInfo](ctx, s, "/Ls/GameInfo", url.Values{"id": {matchID}})
}

// GameLineups — составы, статистика игроков и профили людей матча.
func (s *Session) GameLineups(ctx context.Context, matchID string) (LsGameLineups, error) {
	return get[LsGameLineups](ctx, s, "/Ls/GameLineups", url.Values{"id": {matchID}})
}

func get[T any](ctx context.Context, s *Session, path string, q url.Values) (T, error) {
	var zero T
	body, err := s.do(ctx, path, q)
	if err != nil {
		return zero, err
	}

	var env envelope[T]
	if err := json.Unmarshal(body, &env); err != nil {
		return zero, fmt.Errorf("sstats: decode %s: %w", path, err)
	}
	if strings.EqualFold(env.Status, "error") {
		return zero, &APIError{Message: env.Message}
	}

	return env.Data, nil
}

func (s *Session) do(ctx context.Context, path string, q url.Values) ([]byte, error) {
	c := s.client
	if q == nil {
		q = url.Values{}
	}
	if c.opts.APIKey != "" {
		q.Set("apikey", c.opts.APIKey)
	}
	endpoint := c.opts.BaseURL + path + "?" + q.Encode()

	var lastErr error
	for attempt := 0; attempt <= c.opts.MaxRetries; attempt++ {
		if attempt > 0 {
			if err := sleep(ctx, backoff(c.opts.RetryBaseDelay, attempt, lastErr)); err != nil {
				return nil, err
			}
		}
		if err := c.limiter.wait(ctx); err != nil {
			return nil, err
		}

		s.requests.Add(1)
		body, err := c.once(ctx, endpoint)
		if err == nil {
			return body, nil
		}
		lastErr = err

		var se *StatusError
		if errors.As(err, &se) && se.Code == http.StatusTooManyRequests {
			s.rateLimited.Add(1)
		}
		if !Retryable(err) {
			return nil, err
		}
	}

	return nil, lastErr
}

func (c *Client) once(ctx context.Context, endpoint string) ([]byte, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Accept", "application/json")

	resp, err := c.opts.HTTPClient.Do(req)
	if err != nil {
		return nil, sanitize(err, c.opts.APIKey)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(io.LimitReader(resp.Body, 32<<20))
	if err != nil {
		return nil, sanitize(err, c.opts.APIKey)
	}

	switch {
	case resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden:
		return nil, ErrUnauthorized
	case resp.StatusCode == http.StatusTooManyRequests:
		return nil, &StatusError{Code: resp.StatusCode, Message: retryAfterMessage(resp)}
	case resp.StatusCode >= 400:
		return nil, &StatusError{Code: resp.StatusCode, Message: snippet(body)}
	}

	return body, nil
}

// sanitize убирает apikey из текста сетевой ошибки (URL попадает в *url.Error).
func sanitize(err error, key string) error {
	if key == "" {
		return err
	}

	return errors.New(strings.ReplaceAll(err.Error(), key, "***"))
}

func retryAfterMessage(resp *http.Response) string {
	if v := resp.Header.Get("Retry-After"); v != "" {
		return "retry-after " + v
	}

	return "too many requests"
}

func snippet(b []byte) string {
	s := strings.TrimSpace(string(b))
	if len(s) > 200 {
		s = s[:200]
	}

	return s
}

func backoff(base time.Duration, attempt int, last error) time.Duration {
	var se *StatusError
	if errors.As(last, &se) && strings.HasPrefix(se.Message, "retry-after ") {
		if n, err := strconv.Atoi(strings.TrimPrefix(se.Message, "retry-after ")); err == nil && n > 0 {
			return time.Duration(n) * time.Second
		}
	}

	return base << (attempt - 1)
}

func sleep(ctx context.Context, d time.Duration) error {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}

// limiter равномерно разносит запросы (rate per minute).
type limiter struct {
	mu       sync.Mutex
	interval time.Duration
	next     time.Time
}

func newLimiter(perMinute int) *limiter {
	if perMinute <= 0 {
		return &limiter{}
	}

	return &limiter{interval: time.Minute / time.Duration(perMinute)}
}

func (l *limiter) wait(ctx context.Context) error {
	if l.interval == 0 {
		return nil
	}

	l.mu.Lock()
	now := time.Now()
	at := l.next
	if at.Before(now) {
		at = now
	}
	l.next = at.Add(l.interval)
	l.mu.Unlock()

	return sleep(ctx, time.Until(at))
}
