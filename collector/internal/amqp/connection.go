package amqp

import (
	"context"
	"encoding/json"
	"fmt"
	"sync"

	amqp "github.com/rabbitmq/amqp091-go"
)

// Conn — соединение с RabbitMQ: отдельные каналы на consume и publish.
type Conn struct {
	conn      *amqp.Connection
	consumeCh *amqp.Channel
	publishCh *amqp.Channel
	pubMu     sync.Mutex
}

func Dial(url string, prefetch int) (*Conn, error) {
	conn, err := amqp.Dial(url)
	if err != nil {
		return nil, fmt.Errorf("rabbitmq dial: %w", err)
	}

	consumeCh, err := conn.Channel()
	if err != nil {
		_ = conn.Close()
		return nil, err
	}
	publishCh, err := conn.Channel()
	if err != nil {
		_ = conn.Close()
		return nil, err
	}
	if err := DeclareTopology(consumeCh); err != nil {
		_ = conn.Close()
		return nil, fmt.Errorf("declare topology: %w", err)
	}
	if err := consumeCh.Qos(prefetch, 0, false); err != nil {
		_ = conn.Close()
		return nil, err
	}
	if err := publishCh.Confirm(false); err != nil {
		_ = conn.Close()
		return nil, err
	}

	return &Conn{conn: conn, consumeCh: consumeCh, publishCh: publishCh}, nil
}

func (c *Conn) Close() error { return c.conn.Close() }

// NotifyClose сигнализирует о потере соединения.
func (c *Conn) NotifyClose() <-chan *amqp.Error {
	return c.conn.NotifyClose(make(chan *amqp.Error, 1))
}

// Publish кладёт persistent JSON-сообщение в exchange ingest и ждёт confirm брокера.
func (c *Conn) Publish(ctx context.Context, routingKey string, msg any) error {
	body, err := json.Marshal(msg)
	if err != nil {
		return err
	}

	c.pubMu.Lock()
	defer c.pubMu.Unlock()

	confirm, err := c.publishCh.PublishWithDeferredConfirmWithContext(ctx, Exchange, routingKey, false, false, amqp.Publishing{
		ContentType:  "application/json",
		DeliveryMode: amqp.Persistent,
		Body:         body,
	})
	if err != nil {
		return err
	}

	acked, err := confirm.WaitContext(ctx)
	if err != nil {
		return err
	}
	if !acked {
		return fmt.Errorf("rabbitmq: publish to %q was not confirmed", routingKey)
	}

	return nil
}
