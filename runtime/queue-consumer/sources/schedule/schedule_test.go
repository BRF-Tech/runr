package schedule

import (
	"context"
	"encoding/json"
	"fmt"
	"sync"
	"testing"
	"time"

	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

// fakeClock moves only when Advance is called; timers fire when their time is reached.
type fakeClock struct {
	mu     sync.Mutex
	now    time.Time
	timers []*fakeTimer
}

type fakeTimer struct {
	at time.Time
	ch chan time.Time
}

func (c *fakeClock) Now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.now
}

func (c *fakeClock) After(d time.Duration) (<-chan time.Time, func()) {
	c.mu.Lock()
	defer c.mu.Unlock()
	t := &fakeTimer{at: c.now.Add(d), ch: make(chan time.Time, 1)}
	if d <= 0 {
		t.ch <- c.now
		return t.ch, func() {}
	}
	c.timers = append(c.timers, t)
	return t.ch, func() {
		c.mu.Lock()
		defer c.mu.Unlock()
		for i, x := range c.timers {
			if x == t {
				c.timers = append(c.timers[:i], c.timers[i+1:]...)
				return
			}
		}
	}
}

func (c *fakeClock) Advance(d time.Duration) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.now = c.now.Add(d)
	kept := c.timers[:0]
	for _, t := range c.timers {
		if !t.at.After(c.now) {
			t.ch <- c.now
			continue
		}
		kept = append(kept, t)
	}
	c.timers = kept
}

func (c *fakeClock) pending() int {
	c.mu.Lock()
	defer c.mu.Unlock()
	return len(c.timers)
}

func waitFor(t *testing.T, what string, cond func() bool) {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for !cond() {
		if time.Now().After(deadline) {
			t.Fatalf("timed out waiting for %s", what)
		}
		time.Sleep(time.Millisecond)
	}
}

func newTestSource(t *testing.T, spec string, start time.Time) (*Source, *fakeClock) {
	t.Helper()
	raw, _ := json.Marshal(Config{Cron: spec})
	s, err := New(raw)
	if err != nil {
		t.Fatal(err)
	}
	clock := &fakeClock{now: start}
	s.(*Source).clock = clock
	return s.(*Source), clock
}

func TestTicksAndOneInFlight(t *testing.T) {
	start := time.Date(2026, 10, 21, 10, 0, 30, 0, time.UTC)
	s, clock := newTestSource(t, "* * * * *", start)

	var mu sync.Mutex
	var got []source.Event
	release := make(chan struct{})
	emit := func(ctx context.Context, ev source.Event) error {
		mu.Lock()
		got = append(got, ev)
		n := len(got)
		mu.Unlock()
		if n == 1 {
			<-release // the first tick's delivery is still running when the second one comes
		}
		return nil
	}
	emitted := func() int { mu.Lock(); defer mu.Unlock(); return len(got) }

	ctx, cancel := context.WithCancel(source.WithTriggerName(context.Background(), "nightly"))
	done := make(chan error, 1)
	go func() { done <- s.Run(ctx, emit) }()

	waitFor(t, "the first timer", func() bool { return clock.pending() == 1 })
	clock.Advance(30 * time.Second) // 10:01:00
	waitFor(t, "the first tick", func() bool { return emitted() == 1 && clock.pending() == 1 })

	clock.Advance(time.Minute) // 10:02:00, while 10:01 is in delivery: skipped
	waitFor(t, "the timer after the skipped tick", func() bool { return clock.pending() == 1 })
	if n := emitted(); n != 1 {
		t.Fatalf("%d ticks emitted, want the 10:02 tick skipped", n)
	}

	close(release)
	waitFor(t, "the first delivery to end", func() bool { return !s.busy.Load() })
	clock.Advance(time.Minute) // 10:03:00
	waitFor(t, "the third tick", func() bool { return emitted() == 2 })

	cancel()
	if err := <-done; err != nil {
		t.Fatalf("Run = %v", err)
	}

	mu.Lock()
	defer mu.Unlock()
	first, third := got[0], got[1]
	t1 := time.Date(2026, 10, 21, 10, 1, 0, 0, time.UTC)
	t3 := time.Date(2026, 10, 21, 10, 3, 0, 0, time.UTC)
	if first.ID != fmt.Sprintf("nightly-%d", t1.Unix()) || third.ID != fmt.Sprintf("nightly-%d", t3.Unix()) {
		t.Errorf("ids %s, %s", first.ID, third.ID)
	}
	if first.Type != "dev.runr.schedule.tick" || first.Source != "/schedule/nightly" || first.DataContentType != "application/json" || !first.Time.Equal(t1) {
		t.Errorf("tick %+v", first)
	}
	var data map[string]string
	if err := json.Unmarshal(first.Data, &data); err != nil || data["schedule"] != "* * * * *" || data["scheduled_time"] != "2026-10-21T10:01:00Z" {
		t.Errorf("data %s (%v)", first.Data, err)
	}
}

func TestMissedTicksAreNotMadeUp(t *testing.T) {
	start := time.Date(2026, 10, 21, 10, 0, 30, 0, time.UTC)
	s, clock := newTestSource(t, "*/5 * * * *", start)

	var mu sync.Mutex
	var ids []string
	emit := func(_ context.Context, ev source.Event) error {
		mu.Lock()
		ids = append(ids, ev.ID)
		mu.Unlock()
		return nil
	}
	ctx, cancel := context.WithCancel(source.WithTriggerName(context.Background(), "t"))
	done := make(chan error, 1)
	go func() { done <- s.Run(ctx, emit) }()

	waitFor(t, "the first timer", func() bool { return clock.pending() == 1 })
	clock.Advance(time.Hour) // the process was paused: 10:05 fires late, 10:10..11:00 are gone
	waitFor(t, "the late tick", func() bool { mu.Lock(); defer mu.Unlock(); return len(ids) == 1 })
	waitFor(t, "the next timer", func() bool { return clock.pending() == 1 })
	cancel()
	<-done

	mu.Lock()
	defer mu.Unlock()
	if want := fmt.Sprintf("t-%d", time.Date(2026, 10, 21, 10, 5, 0, 0, time.UTC).Unix()); len(ids) != 1 || ids[0] != want {
		t.Fatalf("ids %v, want only %s", ids, want)
	}
}

// A schedule with a time zone fires at the zone's wall-clock times and says so in its data.
func TestTimezone(t *testing.T) {
	raw, _ := json.Marshal(Config{Cron: "0 9 * * *", Timezone: "Europe/Berlin"})
	src, err := New(raw)
	if err != nil {
		t.Fatal(err)
	}
	s := src.(*Source)
	if s.Name() != "schedule 0 9 * * * (Europe/Berlin)" {
		t.Errorf("Name = %q", s.Name())
	}
	start := time.Date(2026, 10, 21, 6, 0, 0, 0, time.UTC) // 08:00 in Berlin (CEST, UTC+2)
	clock := &fakeClock{now: start}
	s.clock = clock

	var mu sync.Mutex
	var got []source.Event
	emit := func(_ context.Context, ev source.Event) error {
		mu.Lock()
		got = append(got, ev)
		mu.Unlock()
		return nil
	}
	ctx, cancel := context.WithCancel(source.WithTriggerName(context.Background(), "morning"))
	done := make(chan error, 1)
	go func() { done <- s.Run(ctx, emit) }()

	waitFor(t, "the first timer", func() bool { return clock.pending() == 1 })
	clock.Advance(59 * time.Minute)
	if mu.Lock(); len(got) != 0 {
		t.Fatalf("a tick before 09:00 Berlin: %+v", got)
	}
	mu.Unlock()
	clock.Advance(time.Minute) // 07:00 UTC = 09:00 Berlin
	waitFor(t, "the 09:00 tick", func() bool { mu.Lock(); defer mu.Unlock(); return len(got) == 1 })
	// after the end of summer time (2026-10-25) 09:00 Berlin is 08:00 UTC
	waitFor(t, "the next timer", func() bool { return clock.pending() == 1 })
	// 2026-10-25 07:59 UTC: the 22nd's tick fires late, the ones in between are not made up
	clock.Advance(4*24*time.Hour + 59*time.Minute)
	waitFor(t, "the late tick", func() bool { mu.Lock(); defer mu.Unlock(); return len(got) == 2 })
	waitFor(t, "the timer of the 25th", func() bool { return clock.pending() == 1 })
	clock.Advance(time.Minute) // 08:00 UTC
	waitFor(t, "the 09:00 tick in winter time", func() bool { mu.Lock(); defer mu.Unlock(); return len(got) == 3 })
	cancel()
	<-done

	mu.Lock()
	defer mu.Unlock()
	t1 := time.Date(2026, 10, 21, 7, 0, 0, 0, time.UTC)
	if !got[0].Time.Equal(t1) || got[0].ID != fmt.Sprintf("morning-%d", t1.Unix()) {
		t.Errorf("first tick %+v, want %s", got[0], t1)
	}
	var data map[string]string
	json.Unmarshal(got[0].Data, &data)
	if data["timezone"] != "Europe/Berlin" || data["scheduled_time"] != "2026-10-21T07:00:00Z" || data["local_time"] != "2026-10-21T09:00:00+02:00" {
		t.Errorf("data %s", got[0].Data)
	}
	if want := time.Date(2026, 10, 25, 8, 0, 0, 0, time.UTC); !got[len(got)-1].Time.Equal(want) {
		t.Errorf("winter tick at %s, want %s", got[len(got)-1].Time, want)
	}
}

func TestConfig(t *testing.T) {
	for _, bad := range []string{`{}`, `{"cron": "every minute"}`, `{"cron": "* * * *"}`,
		`{"cron": "0 9 * * *", "timezone": "Mars/Olympus"}`, `{"cron": "0 9 * * *", "timezone": "Local"}`,
		`{"cron": "CRON_TZ=Europe/Berlin 0 9 * * *"}`} {
		if _, err := New(json.RawMessage(bad)); err == nil {
			t.Errorf("config %s accepted", bad)
		}
	}
	for _, ok := range []string{`{"cron": "@hourly"}`, `{"cron": "@every 90s"}`, `{"cron": "0 3 * * 1-5"}`} {
		s, err := New(json.RawMessage(ok))
		if err != nil {
			t.Errorf("config %s: %v", ok, err)
			continue
		}
		next := s.(*Source).sched.Next(time.Date(2026, 10, 21, 10, 0, 30, 0, time.UTC))
		if next.IsZero() || next.Location() != time.UTC {
			t.Errorf("%s: next %v, want a UTC time", ok, next)
		}
	}
}
