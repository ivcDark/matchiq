// Package config читает настройки collector из окружения.
package config

import (
	"errors"
	"os"
	"strconv"
	"time"
)

type Config struct {
	HTTPAddr      string
	RabbitMQURL   string
	SStatsBaseURL string
	SStatsAPIKey  string
	SStatsRatePM  int
	HTTPTimeout   time.Duration
	Prefetch      int
	BatchSize     int
}

func Load() (Config, error) {
	cfg := Config{
		HTTPAddr:      envOr("COLLECTOR_ADDR", ":8081"),
		RabbitMQURL:   os.Getenv("RABBITMQ_URL"),
		SStatsBaseURL: envOr("SSTATS_BASE_URL", "https://api.sstats.net"),
		SStatsAPIKey:  os.Getenv("SSTATS_API_KEY"),
		SStatsRatePM:  envInt("SSTATS_RATE_PER_MINUTE", 30),
		HTTPTimeout:   time.Duration(envInt("SSTATS_TIMEOUT_SECONDS", 30)) * time.Second,
		Prefetch:      envInt("COLLECTOR_PREFETCH", 2),
		BatchSize:     envInt("COLLECTOR_BATCH_SIZE", 200),
	}

	if cfg.RabbitMQURL == "" {
		return cfg, errors.New("RABBITMQ_URL is required")
	}

	return cfg, nil
}

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}

	return fallback
}

func envInt(key string, fallback int) int {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n > 0 {
			return n
		}
	}

	return fallback
}
