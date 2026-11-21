package main

import (
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"
)

// Placeholder Go collector service for MatchIQ.
// Later: provider adapters (scraper → paid API), normalize into Postgres.
func main() {
	addr := envOr("COLLECTOR_ADDR", ":8081")
	league := envOr("COLLECTOR_LEAGUE", "rpl")

	mux := http.NewServeMux()
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprintf(w, `{"status":"ok","service":"collector","league":%q,"ts":%q}`, league, time.Now().UTC().Format(time.RFC3339))
	})
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		fmt.Fprintln(w, "MatchIQ collector stub — ingestion not implemented yet")
	})

	server := &http.Server{Addr: addr, Handler: mux}

	go func() {
		log.Printf("collector stub listening on %s (league=%s)", addr, league)
		if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatal(err)
		}
	}()

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	<-stop
	log.Println("collector shutting down")
	_ = server.Close()
}

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
