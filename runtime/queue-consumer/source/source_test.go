package source

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"slices"
	"strings"
	"testing"
)

type nopSource struct{ cfg string }

func (n nopSource) Name() string { return "nop " + n.cfg }

func (nopSource) Run(ctx context.Context, _ func(context.Context, Event) error) error {
	<-ctx.Done()
	return nil
}

func init() {
	Register("test-nop", func(cfg json.RawMessage) (Source, error) {
		if string(cfg) == `{"fail":true}` {
			return nil, errors.New("bad config")
		}
		return nopSource{cfg: string(cfg)}, nil
	})
}

func TestRegistry(t *testing.T) {
	if !slices.Contains(Kinds(), "test-nop") || !slices.IsSorted(Kinds()) {
		t.Errorf("Kinds = %v", Kinds())
	}

	s, err := New("test-nop", nil)
	if err != nil || s.Name() != "nop {}" {
		t.Errorf("New with an empty config = %v, %v; want the factory called with {}", s, err)
	}
	if _, err := New("test-nop", json.RawMessage(`{"fail":true}`)); err == nil || !strings.Contains(err.Error(), "bad config") {
		t.Errorf("factory error = %v", err)
	}
	if _, err := New("kafka-not-here", nil); err == nil || !strings.Contains(err.Error(), "test-nop") {
		t.Errorf("unknown kind error %v, want it to list the known kinds", err)
	}

	defer func() {
		if recover() == nil {
			t.Error("registering a kind twice did not panic")
		}
	}()
	Register("test-nop", func(json.RawMessage) (Source, error) { return nopSource{}, nil })
}

func TestSettled(t *testing.T) {
	if !Settled(nil) || !Settled(ErrDeadLettered) || !Settled(fmt.Errorf("%w after 3 attempts: status 500", ErrDeadLettered)) {
		t.Error("delivered or dead-lettered not settled")
	}
	if Settled(context.Canceled) || Settled(errors.New("boom")) {
		t.Error("shutdown or another error settled")
	}
}

func TestContextHelpers(t *testing.T) {
	ctx := context.Background()
	if Concurrency(ctx) != 1 || TriggerName(ctx) != "" || Nudges(ctx) != nil || PriorAttempts(ctx) != 0 || DeadLetterFields(ctx) != nil {
		t.Error("defaults of an empty context")
	}
	if Concurrency(WithConcurrency(ctx, 0)) != 1 || PriorAttempts(WithPriorAttempts(ctx, -2)) != 0 {
		t.Error("invalid values not clamped")
	}

	nudges := make(chan struct{}, 1)
	ctx = WithConcurrency(ctx, 4)
	ctx = WithTriggerName(ctx, "orders")
	ctx = WithNudges(ctx, nudges)
	ctx = WithPriorAttempts(ctx, 2)
	ctx = WithDeadLetterFields(ctx, map[string]string{"order": "1"})
	nudges <- struct{}{}
	select {
	case <-Nudges(ctx):
	default:
		t.Error("Nudges does not return the core's channel")
	}
	if Concurrency(ctx) != 4 || TriggerName(ctx) != "orders" || PriorAttempts(ctx) != 2 || DeadLetterFields(ctx)["order"] != "1" {
		t.Error("values not carried by the context")
	}
}
