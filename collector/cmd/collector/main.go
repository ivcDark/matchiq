// Collector: читает команды ingest из RabbitMQ, собирает данные SStats.net и публикует
// канонические результаты обратно в RabbitMQ. В PostgreSQL не пишет.
package main

import (
	"context"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/ivcDark/matchiq/collector/internal/amqp"
	"github.com/ivcDark/matchiq/collector/internal/config"
	"github.com/ivcDark/matchiq/collector/internal/jobs"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

func main() {
	cfg, err := config.Load()
	if err != nil {
		log.Fatal(err)
	}
	if cfg.SStatsAPIKey == "" {
		log.Println("warning: SSTATS_API_KEY is empty, requests go without apikey (strict anonymous limits)")
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	server := serveHealth(cfg.HTTPAddr)
	defer server.Close()

	conn := dialWithRetry(ctx, cfg)
	if conn == nil {
		return
	}
	defer conn.Close()

	client := sstats.NewClient(sstats.Options{
		BaseURL:       cfg.SStatsBaseURL,
		APIKey:        cfg.SStatsAPIKey,
		HTTPClient:    &http.Client{Timeout: cfg.HTTPTimeout},
		RatePerMinute: cfg.SStatsRatePM,
	})
	runner := &jobs.Runner{
		Registry:  jobs.DefaultRegistry(),
		Client:    client,
		Publisher: conn,
		BatchSize: cfg.BatchSize,
	}

	log.Printf("collector consuming %s (prefetch=%d)", amqp.QueueCommands, cfg.Prefetch)
	if err := conn.Consume(ctx, runner); err != nil {
		log.Fatalf("consume: %v", err)
	}
	log.Println("collector shutting down")
}

func dialWithRetry(ctx context.Context, cfg config.Config) *amqp.Conn {
	for {
		conn, err := amqp.Dial(cfg.RabbitMQURL, cfg.Prefetch)
		if err == nil {
			return conn
		}
		log.Printf("rabbitmq unavailable, retry in 5s: %v", err)
		select {
		case <-ctx.Done():
			return nil
		case <-time.After(5 * time.Second):
		}
	}
}

func serveHealth(addr string) *http.Server {
	mux := http.NewServeMux()
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprintf(w, `{"status":"ok","service":"collector","ts":%q}`, time.Now().UTC().Format(time.RFC3339))
	})

	server := &http.Server{Addr: addr, Handler: mux}
	go func() {
		if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Printf("healthz: %v", err)
			os.Exit(1)
		}
	}()

	return server
}
