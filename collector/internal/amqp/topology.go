// Package amqp — RabbitMQ: топология ingest, consumer команд, publisher результатов.
package amqp

import (
	amqp "github.com/rabbitmq/amqp091-go"
)

const (
	Exchange = "matchiq.ingest"

	QueueCommands    = "ingest.commands"
	QueueResults     = "ingest.results"
	QueueCommandsDLQ = "ingest.commands.dlq"
	QueueResultsDLQ  = "ingest.results.dlq"

	KeyCommand    = "command"
	KeyResult     = "result"
	KeyCommandDLQ = "command.dlq"
	KeyResultDLQ  = "result.dlq"
)

// DeclareTopology идемпотентно объявляет exchange и очереди. Аргументы очередей обязаны
// совпадать с Laravel (config/rabbitmq.php), иначе RabbitMQ вернёт PRECONDITION_FAILED.
func DeclareTopology(ch *amqp.Channel) error {
	if err := ch.ExchangeDeclare(Exchange, "direct", true, false, false, false, nil); err != nil {
		return err
	}

	queues := []struct {
		name, key, deadKey string
	}{
		{QueueCommands, KeyCommand, KeyCommandDLQ},
		{QueueResults, KeyResult, KeyResultDLQ},
		{QueueCommandsDLQ, KeyCommandDLQ, ""},
		{QueueResultsDLQ, KeyResultDLQ, ""},
	}

	for _, q := range queues {
		var args amqp.Table
		if q.deadKey != "" {
			args = amqp.Table{
				"x-dead-letter-exchange":    Exchange,
				"x-dead-letter-routing-key": q.deadKey,
			}
		}
		if _, err := ch.QueueDeclare(q.name, true, false, false, false, args); err != nil {
			return err
		}
		if err := ch.QueueBind(q.name, q.key, Exchange, false, nil); err != nil {
			return err
		}
	}

	return nil
}
