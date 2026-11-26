package amqp

import (
	"context"
	"encoding/json"
	"log"
	"sync"

	amqp "github.com/rabbitmq/amqp091-go"

	"github.com/ivcDark/matchiq/collector/internal/dto"
)

// CommandHandler обрабатывает команду. Ошибка — не удалось опубликовать результат.
type CommandHandler interface {
	Run(ctx context.Context, cmd dto.Command) error
}

// Consume читает ingest.commands до отмены ctx. Ручной ack: после публикации результата.
// Невалидная команда — reject без requeue (уходит в ingest.commands.dlq). Сбой публикации:
// первый раз возвращаем в очередь, при повторной доставке — в DLQ.
func (c *Conn) Consume(ctx context.Context, handler CommandHandler) error {
	deliveries, err := c.consumeCh.Consume(QueueCommands, "", false, false, false, false, nil)
	if err != nil {
		return err
	}

	var wg sync.WaitGroup
	defer wg.Wait()

	for {
		select {
		case <-ctx.Done():
			return nil
		case d, ok := <-deliveries:
			if !ok {
				return amqp.ErrClosed
			}
			wg.Add(1)
			go func() {
				defer wg.Done()
				handleDelivery(ctx, handler, d)
			}()
		}
	}
}

func handleDelivery(ctx context.Context, handler CommandHandler, d amqp.Delivery) {
	var cmd dto.Command
	if err := json.Unmarshal(d.Body, &cmd); err != nil {
		log.Printf("command rejected: invalid json: %v", err)
		_ = d.Reject(false)
		return
	}
	if err := cmd.Validate(); err != nil {
		log.Printf("command rejected: message_id=%s: %v", cmd.MessageID, err)
		_ = d.Reject(false)
		return
	}

	log.Printf("command received: run_id=%d job_type=%s message_id=%s", cmd.RunID, cmd.JobType, cmd.MessageID)

	if err := handler.Run(ctx, cmd); err != nil {
		log.Printf("command failed to publish result: run_id=%d: %v", cmd.RunID, err)
		_ = d.Reject(!d.Redelivered)
		return
	}

	_ = d.Ack(false)
	log.Printf("command done: run_id=%d job_type=%s", cmd.RunID, cmd.JobType)
}
