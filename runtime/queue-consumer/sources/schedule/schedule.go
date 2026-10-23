// Package schedule is the gateway source of kind "schedule": a cron schedule that emits one tick
// event per scheduled time.
//
// Configuration (the trigger's "source" object):
//
//	{"cron": "*/5 * * * *", "timezone": "Europe/Berlin"}
//
// The expression has the 5 standard fields (minute hour day-of-month month day-of-week) or is
// one of the macros @yearly, @monthly, @weekly, @daily, @hourly and @every <duration>. It is
// evaluated in timezone (an IANA zone; default UTC; the zone database is built into the binary),
// with the parser the Kubernetes CronJob controller uses, so a schedule fires at the same times
// as the CronJob of a project whose only trigger it is (spec.timeZone), daylight saving changes
// included.
//
// Each tick is the event
//
//	id     <trigger>-<unix seconds of the scheduled time>   (the same on every retry)
//	type   dev.runr.schedule.tick
//	source /schedule/<trigger>
//	time   the scheduled time (UTC)
//	data   {"schedule": "<cron>", "timezone": "<zone>", "scheduled_time": "<RFC 3339, UTC>",
//	        "local_time": "<RFC 3339 in the zone>"}
//
// One tick is in delivery at a time: a tick that comes while the previous one is still being
// delivered (with its retries) is skipped and logged, so a slow function is not called by a pile
// of overlapping ticks. Ticks missed while the gateway was not running are not made up.
package schedule

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"strings"
	"sync"
	"sync/atomic"
	"time"
	_ "time/tzdata" // the zones of schedule triggers, whatever the image ships

	"github.com/robfig/cron/v3"

	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

func init() { source.Register("schedule", New) }

// Config is the "source" object of a schedule trigger.
type Config struct {
	Cron     string `json:"cron"`
	Timezone string `json:"timezone"` // IANA zone; "" = UTC
}

// clock is the time source of a schedule; tests replace it.
type clock interface {
	Now() time.Time
	// After is a timer: the channel receives once d has passed; stop releases it early.
	After(d time.Duration) (ch <-chan time.Time, stop func())
}

type realClock struct{}

func (realClock) Now() time.Time { return time.Now() }

func (realClock) After(d time.Duration) (<-chan time.Time, func()) {
	t := time.NewTimer(d)
	return t.C, func() { t.Stop() }
}

// Source emits the ticks of one cron schedule.
type Source struct {
	spec  string
	zone  string
	loc   *time.Location
	sched cron.Schedule
	clock clock
	busy  atomic.Bool // a tick is in delivery
}

// New builds a schedule source from its configuration.
func New(raw json.RawMessage) (source.Source, error) {
	var c Config
	if err := json.Unmarshal(raw, &c); err != nil {
		return nil, err
	}
	if c.Cron == "" {
		return nil, errors.New("cron is required")
	}
	if strings.HasPrefix(strings.TrimSpace(c.Cron), "CRON_TZ=") || strings.HasPrefix(strings.TrimSpace(c.Cron), "TZ=") {
		return nil, errors.New("cron: set the zone with timezone, not a CRON_TZ= / TZ= prefix")
	}
	zone := strings.TrimSpace(c.Timezone)
	if zone == "" {
		zone = "UTC"
	}
	if zone == "Local" {
		return nil, errors.New(`timezone "Local" is the container's zone: name an IANA zone`)
	}
	loc, err := time.LoadLocation(zone)
	if err != nil {
		return nil, fmt.Errorf("timezone %q: %w", zone, err)
	}
	sched, err := cron.ParseStandard(c.Cron)
	if err != nil {
		return nil, fmt.Errorf("cron %q: %w", c.Cron, err)
	}
	return &Source{spec: c.Cron, zone: zone, loc: loc, sched: sched, clock: realClock{}}, nil
}

// Name is "schedule <cron>" with the zone when it is not UTC.
func (s *Source) Name() string {
	if s.zone != "UTC" {
		return "schedule " + s.spec + " (" + s.zone + ")"
	}
	return "schedule " + s.spec
}

// Run emits a tick at every scheduled time until ctx ends, and returns after the tick in
// delivery (if any) returned.
func (s *Source) Run(ctx context.Context, emit func(context.Context, source.Event) error) error {
	trigger := source.TriggerName(ctx)
	var wg sync.WaitGroup
	defer wg.Wait()

	// The parser leaves the schedule in time.Local, which makes Next evaluate in the location of
	// the time it is given: the trigger's zone.
	next := s.sched.Next(s.clock.Now().In(s.loc))
	for {
		if next.IsZero() {
			log.Printf("schedule %s: %q has no further times", trigger, s.spec)
			<-ctx.Done()
			return nil
		}
		ch, stop := s.clock.After(next.Sub(s.clock.Now()))
		select {
		case <-ctx.Done():
			stop()
			return nil
		case <-ch:
		}
		tick := next
		// The next time after the later of the tick and now: ticks missed while the process
		// was paused are not made up.
		base := s.clock.Now().In(s.loc)
		if base.Before(tick) {
			base = tick
		}
		next = s.sched.Next(base)

		if !s.busy.CompareAndSwap(false, true) {
			log.Printf("schedule %s: tick %s skipped, the previous tick is still in delivery", trigger, tick.Format(time.RFC3339))
			continue
		}
		ev := s.event(trigger, tick)
		wg.Add(1)
		go func() {
			defer wg.Done()
			defer s.busy.Store(false)
			emit(ctx, ev) // nothing to acknowledge: the gateway logs failures and dead letters
		}()
	}
}

func (s *Source) event(trigger string, tick time.Time) source.Event {
	local := tick.In(s.loc)
	tick = tick.UTC()
	data, _ := json.Marshal(struct {
		Schedule      string `json:"schedule"`
		Timezone      string `json:"timezone"`
		ScheduledTime string `json:"scheduled_time"`
		LocalTime     string `json:"local_time"`
	}{s.spec, s.zone, tick.Format(time.RFC3339), local.Format(time.RFC3339)})
	return source.Event{
		ID:              fmt.Sprintf("%s-%d", trigger, tick.Unix()),
		Type:            "dev.runr.schedule.tick",
		Source:          "/schedule/" + trigger,
		DataContentType: "application/json",
		Time:            tick,
		Data:            data,
	}
}
