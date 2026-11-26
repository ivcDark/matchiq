// Package dto — контракт сообщений ingest (schema_version 1) и канонические DTO сущностей.
// Описание: docs/architecture/ingestion.md.
package dto

import (
	"crypto/rand"
	"encoding/json"
	"fmt"
	"time"
)

const SchemaVersion = 1

const (
	JobSyncCatalog   = "sync_catalog"
	JobSyncStructure = "sync_structure"
)

const (
	KindBatch  = "batch"
	KindDone   = "done"
	KindFailed = "failed"
)

const (
	PhaseFetch     = "fetch"
	PhaseParse     = "parse"
	PhaseNormalize = "normalize"
)

type Envelope struct {
	SchemaVersion int    `json:"schema_version"`
	MessageID     string `json:"message_id"`
	RunID         int64  `json:"run_id"`
	JobType       string `json:"job_type"`
	Provider      string `json:"provider"`
	SentAt        string `json:"sent_at"`
}

// Command — команда Laravel → collector.
type Command struct {
	Envelope
	Payload json.RawMessage `json:"payload"`
}

type Entity struct {
	EntityType  string  `json:"entity_type"`
	ExternalID  string  `json:"external_id"`
	ExternalURL *string `json:"external_url"`
	Data        any     `json:"data"`
}

type EntityError struct {
	EntityType string         `json:"entity_type,omitempty"`
	ExternalID string         `json:"external_id,omitempty"`
	Phase      string         `json:"phase"`
	Message    string         `json:"message"`
	Context    map[string]any `json:"context,omitempty"`
}

type Batch struct {
	Envelope
	Kind     string        `json:"kind"`
	Seq      int           `json:"seq"`
	Entities []Entity      `json:"entities"`
	Errors   []EntityError `json:"errors"`
}

type SourceStats struct {
	Requests    int `json:"requests"`
	RateLimited int `json:"rate_limited"`
}

type Done struct {
	Envelope
	Kind          string      `json:"kind"`
	BatchesTotal  int         `json:"batches_total"`
	EntitiesTotal int         `json:"entities_total"`
	ErrorsTotal   int         `json:"errors_total"`
	Source        SourceStats `json:"source"`
}

type Failed struct {
	Envelope
	Kind      string `json:"kind"`
	Message   string `json:"message"`
	Retryable bool   `json:"retryable"`
}

// ResultEnvelope строит конверт ответа на команду.
func ResultEnvelope(cmd Command) Envelope {
	return Envelope{
		SchemaVersion: SchemaVersion,
		MessageID:     NewMessageID(),
		RunID:         cmd.RunID,
		JobType:       cmd.JobType,
		Provider:      cmd.Provider,
		SentAt:        time.Now().UTC().Format(time.RFC3339),
	}
}

func NewMessageID() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80

	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:])
}

// Validate проверяет конверт команды; ошибка означает «в DLQ».
func (c Command) Validate() error {
	if c.SchemaVersion != SchemaVersion {
		return fmt.Errorf("unsupported schema_version %d", c.SchemaVersion)
	}
	if c.RunID <= 0 {
		return fmt.Errorf("run_id is required")
	}
	if c.JobType == "" {
		return fmt.Errorf("job_type is required")
	}

	return nil
}
