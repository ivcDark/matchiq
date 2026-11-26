package jobs

import (
	"context"
	"errors"
	"sort"

	"github.com/ivcDark/matchiq/collector/internal/dto"
	"github.com/ivcDark/matchiq/collector/internal/sstats"
)

// Publisher — публикация результатов (реализует internal/amqp).
type Publisher interface {
	Publish(ctx context.Context, routingKey string, msg any) error
}

const ResultRoutingKey = "result"

// Runner выполняет команду: сборщик → батчи → done / failed.
type Runner struct {
	Registry  Registry
	Client    *sstats.Client
	Publisher Publisher
	BatchSize int
	// NewSource подменяется в тестах.
	NewSource func() (Source, SourceCounter)
}

type SourceCounter interface {
	Requests() int
	RateLimited() int
}

func (r *Runner) source() (Source, SourceCounter) {
	if r.NewSource != nil {
		return r.NewSource()
	}
	s := r.Client.Session()

	return s, s
}

// Run публикует результат. Ошибку возвращает только при сбое публикации (команду нужно повторить).
func (r *Runner) Run(ctx context.Context, cmd dto.Command) error {
	collector, err := r.Registry.Get(cmd.JobType)
	if err != nil {
		return r.fail(ctx, cmd, err.Error(), false)
	}

	src, counter := r.source()
	out, err := collector.Collect(ctx, src, cmd)
	if err != nil {
		var perm *PermanentError
		retryable := !errors.As(err, &perm) && sstats.Retryable(err)

		return r.fail(ctx, cmd, err.Error(), retryable)
	}

	return r.publishOutput(ctx, cmd, out, counter)
}

func (r *Runner) publishOutput(ctx context.Context, cmd dto.Command, out *Output, counter SourceCounter) error {
	sort.SliceStable(out.Entities, func(i, j int) bool {
		return dto.EntityRank[out.Entities[i].EntityType] < dto.EntityRank[out.Entities[j].EntityType]
	})

	size := r.BatchSize
	if size <= 0 {
		size = 200
	}

	var batches []dto.Batch
	for start := 0; start < len(out.Entities); start += size {
		end := min(start+size, len(out.Entities))
		batches = append(batches, dto.Batch{Entities: out.Entities[start:end]})
	}
	if len(batches) == 0 && len(out.Errors) > 0 {
		batches = append(batches, dto.Batch{})
	}
	if len(batches) > 0 {
		batches[len(batches)-1].Errors = out.Errors
	}

	for i := range batches {
		b := &batches[i]
		b.Envelope = dto.ResultEnvelope(cmd)
		b.Kind = dto.KindBatch
		b.Seq = i + 1
		if b.Entities == nil {
			b.Entities = []dto.Entity{}
		}
		if b.Errors == nil {
			b.Errors = []dto.EntityError{}
		}
		if err := r.Publisher.Publish(ctx, ResultRoutingKey, b); err != nil {
			return err
		}
	}

	done := dto.Done{
		Envelope:      dto.ResultEnvelope(cmd),
		Kind:          dto.KindDone,
		BatchesTotal:  len(batches),
		EntitiesTotal: len(out.Entities),
		ErrorsTotal:   len(out.Errors),
		Source:        dto.SourceStats{Requests: counter.Requests(), RateLimited: counter.RateLimited()},
	}

	return r.Publisher.Publish(ctx, ResultRoutingKey, done)
}

func (r *Runner) fail(ctx context.Context, cmd dto.Command, message string, retryable bool) error {
	return r.Publisher.Publish(ctx, ResultRoutingKey, dto.Failed{
		Envelope:  dto.ResultEnvelope(cmd),
		Kind:      dto.KindFailed,
		Message:   message,
		Retryable: retryable,
	})
}
