// queue-consumer is the RunR event gateway: it reads the events of a project's triggers — Redis
// Stream queues and topic subscriptions, the webhook / platform-event inbox of the RunR API,
// cron schedules, Kafka, NATS, RabbitMQ, MQTT, Postgres changes (CDC), S3 object events and IMAP
// mailboxes — and POSTs each one as a CloudEvent to the project's Knative Service. The
// delivery guarantees are documented in package gateway, the sources in package source and
// sources/*.
//
// Modes:
//   - GATEWAY_MODE=publisher: the region's topic publisher (package publisher).
//   - GATEWAY_MODE=cdc-cleanup: a one-shot pod of the RunR API that drops the replication slots
//     (and the publication RunR created) of a removed cdc trigger (cdc.RunCleanup).
//   - GATEWAY_CONFIG=/etc/runr/gateway/gateway.json: the triggers of that file (gateway.Load).
//   - neither (legacy env mode): the queue consumer's environment (REDIS_ADDRESS, STREAM, GROUP,
//     SINK_URL, ...) becomes one redis-stream trigger, so existing <slug>-qc Deployments keep
//     working unchanged (gateway.FromLegacyEnv).
//
// :8081 answers GET /healthz, GET /stats, GET /metrics and POST /v1/nudge (package gateway).
package main

import (
	"context"
	"fmt"
	"log"
	"os"
	"os/signal"
	"syscall"

	"github.com/brf-tech/runr/runtime/queue-consumer/gateway"
	"github.com/brf-tech/runr/runtime/queue-consumer/publisher"
	"github.com/brf-tech/runr/runtime/queue-consumer/sources/cdc"

	_ "github.com/brf-tech/runr/runtime/queue-consumer/sources/email"
	_ "github.com/brf-tech/runr/runtime/queue-consumer/sources/inbox"
	_ "github.com/brf-tech/runr/runtime/queue-consumer/sources/kafka"
	_ "github.com/brf-tech/runr/runtime/queue-consumer/sources/mqtt"
	_ "github.com/brf-tech/runr/runtime/queue-consumer/sources/nats"
	_ "github.com/brf-tech/runr/runtime/queue-consumer/sources/rabbitmq"
	_ "github.com/brf-tech/runr/runtime/queue-consumer/sources/redisstream"
	_ "github.com/brf-tech/runr/runtime/queue-consumer/sources/s3"
	_ "github.com/brf-tech/runr/runtime/queue-consumer/sources/schedule"
)

func main() {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go handleSignals(cancel)

	if err := run(ctx, os.Getenv); err != nil {
		log.Fatal(err)
	}
}

func run(ctx context.Context, getenv func(string) string) error {
	switch mode := getenv("GATEWAY_MODE"); mode {
	case "publisher":
		return publisher.Run(ctx, getenv)
	case "cdc-cleanup":
		return cdc.RunCleanup(ctx, getenv, os.Stdout)
	case "", "gateway":
	default:
		return fmt.Errorf("GATEWAY_MODE=%q: want publisher, cdc-cleanup or gateway", mode)
	}

	var (
		cfg    *gateway.Config
		err    error
		origin string
	)
	if path := getenv("GATEWAY_CONFIG"); path != "" {
		cfg, err = gateway.Load(path)
		origin = path
	} else {
		cfg, err = gateway.FromLegacyEnv(getenv)
		origin = "legacy env"
	}
	if err != nil {
		return err
	}
	g, err := gateway.New(cfg)
	if err != nil {
		return err
	}
	log.Printf("event gateway starting (%s): %s", origin, g)

	go func() {
		if err := g.Serve(ctx, ":8081"); err != nil {
			log.Printf("health server error: %v", err)
		}
	}()
	g.Run(ctx)

	log.Println("event gateway shutdown complete")
	return nil
}

func handleSignals(cancel context.CancelFunc) {
	ch := make(chan os.Signal, 1)
	signal.Notify(ch, syscall.SIGTERM, syscall.SIGINT)
	sig := <-ch
	log.Printf("received signal %v, shutting down", sig)
	cancel()
}
