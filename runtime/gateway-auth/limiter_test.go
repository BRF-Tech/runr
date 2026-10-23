package main

import (
	"context"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"
)

func ptr(v int64) *int64 { return &v }

// Both limiters must decide the same: every case runs against the in-memory one and against
// Redis (miniredis runs the Lua script).
func limiters(t *testing.T) map[string]Limiter {
	t.Helper()
	mr := miniredis.RunT(t)
	client := redis.NewClient(&redis.Options{Addr: mr.Addr()})
	t.Cleanup(func() { _ = client.Close() })
	rl := NewRedisLimiter(client)
	rl.OnError = func(err error) { t.Errorf("redis limiter fell back: %v", err) }
	return map[string]Limiter{"memory": NewMemoryLimiter(), "redis": rl}
}

func allowN(t *testing.T, l Limiter, subject string, p *Plan, now time.Time, n int) (allowed int, last Decision) {
	t.Helper()
	for range n {
		d, err := l.Allow(context.Background(), subject, p, now)
		if err != nil {
			t.Fatalf("Allow: %v", err)
		}
		if d.Allowed {
			allowed++
		}
		last = d
	}
	return allowed, last
}

func TestPlanWithoutLimitsAllowsEverything(t *testing.T) {
	for name, l := range limiters(t) {
		got, _ := allowN(t, l, "c:a", &Plan{ID: "p"}, time.Now(), 50)
		if got != 50 {
			t.Errorf("%s: %d of 50 allowed without limits", name, got)
		}
		got, _ = allowN(t, l, "c:a", nil, time.Now(), 5)
		if got != 5 {
			t.Errorf("%s: nil plan limited", name)
		}
	}
}

func TestPerSecondBurstThenRefill(t *testing.T) {
	plan := &Plan{ID: "p", PerSecond: ptr(2), Burst: ptr(4)}
	now := time.Date(2026, 10, 21, 10, 0, 0, 0, time.UTC)
	for name, l := range limiters(t) {
		got, last := allowN(t, l, "c:burst", plan, now, 6)
		if got != 4 {
			t.Errorf("%s: burst 4 allowed %d", name, got)
		}
		if last.Allowed || last.Reason != ReasonRateLimited || last.Limit != 2 || last.Window != 1 || last.ResetMS <= 0 {
			t.Errorf("%s: refusal %+v", name, last)
		}
		// half a second later one token (2/s) is back
		got, _ = allowN(t, l, "c:burst", plan, now.Add(500*time.Millisecond), 2)
		if got != 1 {
			t.Errorf("%s: after 500 ms %d allowed, want 1", name, got)
		}
	}
}

func TestBurstDefaultsToRate(t *testing.T) {
	plan := &Plan{ID: "p", PerSecond: ptr(3)}
	now := time.Date(2026, 10, 21, 10, 0, 0, 0, time.UTC)
	for name, l := range limiters(t) {
		if got, _ := allowN(t, l, "c:rate", plan, now, 10); got != 3 {
			t.Errorf("%s: %d allowed at once, want 3", name, got)
		}
	}
}

func TestPerMinuteSlidingWindow(t *testing.T) {
	plan := &Plan{ID: "p", PerMinute: ptr(10)}
	start := time.Date(2026, 10, 21, 10, 0, 0, 0, time.UTC)
	for name, l := range limiters(t) {
		got, last := allowN(t, l, "c:minute", plan, start.Add(50*time.Second), 12)
		if got != 10 {
			t.Errorf("%s: %d allowed in a minute, want 10", name, got)
		}
		if last.Reason != ReasonRateLimited || last.Window != 60 || last.Limit != 10 {
			t.Errorf("%s: refusal %+v", name, last)
		}
		// 15 s into the next minute the previous one still weighs 75 %: 10 × 0.75 = 7.5 → 2 more
		got, _ = allowN(t, l, "c:minute", plan, start.Add(75*time.Second), 5)
		if got != 2 {
			t.Errorf("%s: %d allowed early in the next minute, want 2", name, got)
		}
	}
}

func TestDailyQuota(t *testing.T) {
	plan := &Plan{ID: "p", PerDay: ptr(3)}
	day := time.Date(2026, 10, 21, 23, 59, 0, 0, time.UTC)
	for name, l := range limiters(t) {
		got, last := allowN(t, l, "c:day", plan, day, 5)
		if got != 3 {
			t.Errorf("%s: %d allowed, want 3", name, got)
		}
		if last.Reason != ReasonQuotaExceeded || last.Window != 86400 || last.ResetMS != 60000 {
			t.Errorf("%s: refusal %+v (reset at midnight, 60 s away)", name, last)
		}
		if got, _ := allowN(t, l, "c:day", plan, day.Add(2*time.Minute), 1); got != 1 {
			t.Errorf("%s: the next day starts with a new quota", name)
		}
	}
}

func TestMonthlyQuota(t *testing.T) {
	plan := &Plan{ID: "p", PerMonth: ptr(2)}
	now := time.Date(2026, 10, 31, 12, 0, 0, 0, time.UTC)
	for name, l := range limiters(t) {
		got, last := allowN(t, l, "c:month", plan, now, 4)
		if got != 2 || last.Reason != ReasonQuotaExceeded || last.Window != 31*86400 || last.RetryAfter() != 12*3600 {
			t.Errorf("%s: %d allowed, refusal %+v", name, got, last)
		}
		if got, _ := allowN(t, l, "c:month", plan, time.Date(2026, 11, 1, 0, 0, 1, 0, time.UTC), 1); got != 1 {
			t.Errorf("%s: November starts with a new quota", name)
		}
	}
}

func TestRefusedCallsAreNotCounted(t *testing.T) {
	// a call refused by the per-second limit does not use the daily quota
	plan := &Plan{ID: "p", PerSecond: ptr(1), PerDay: ptr(3)}
	now := time.Date(2026, 10, 21, 10, 0, 0, 0, time.UTC)
	for name, l := range limiters(t) {
		allowN(t, l, "c:mix", plan, now, 10) // 1 passes
		if got, _ := allowN(t, l, "c:mix", plan, now.Add(time.Second), 1); got != 1 {
			t.Errorf("%s: second call", name)
		}
		if got, _ := allowN(t, l, "c:mix", plan, now.Add(2*time.Second), 1); got != 1 {
			t.Errorf("%s: third call", name)
		}
		got, last := allowN(t, l, "c:mix", plan, now.Add(3*time.Second), 1)
		if got != 0 || last.Reason != ReasonQuotaExceeded {
			t.Errorf("%s: the fourth call of the day: %+v", name, last)
		}
	}
}

func TestSubjectsAreCountedApart(t *testing.T) {
	plan := &Plan{ID: "p", PerDay: ptr(1)}
	now := time.Now()
	for name, l := range limiters(t) {
		a, _ := allowN(t, l, "c:one", plan, now, 2)
		b, _ := allowN(t, l, "k:two", plan, now, 2)
		if a != 1 || b != 1 {
			t.Errorf("%s: %d / %d", name, a, b)
		}
	}
}

func TestRedisDownFallsBackToThePod(t *testing.T) {
	mr := miniredis.RunT(t)
	client := redis.NewClient(&redis.Options{Addr: mr.Addr(), MaxRetries: -1})
	rl := NewRedisLimiter(client)
	var reported error
	rl.OnError = func(err error) { reported = err }
	mr.Close()

	plan := &Plan{ID: "p", PerDay: ptr(2)}
	got, last := allowN(t, rl, "c:down", plan, time.Now(), 3)
	if got != 2 || last.Reason != ReasonQuotaExceeded {
		t.Errorf("the pod's counters should apply: %d allowed, %+v", got, last)
	}
	if reported == nil {
		t.Error("the Redis error was not reported")
	}
	_ = client.Close()
}
