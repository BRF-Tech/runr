package main

import (
	"context"
	"fmt"
	"math"
	"sync"
	"time"

	"github.com/redis/go-redis/v9"
)

// Limits of a plan, checked together; a call passes only when every limit lets it through, and
// only then is it counted:
//
//	per second  token bucket: Burst tokens (default PerSecond), PerSecond tokens refilled a second
//	per minute  sliding window: the previous minute weighted by how much of it still overlaps
//	per day     calendar day, UTC
//	per month   calendar month, UTC
//
// A refusal names the limit (RateLimit-* headers): rate_limited for the per-second and
// per-minute limits, quota_exceeded for the daily and monthly quotas.

const (
	ReasonRateLimited   = "rate_limited"
	ReasonQuotaExceeded = "quota_exceeded"
)

// Decision is the answer of a limiter.
type Decision struct {
	Allowed bool
	Reason  string
	Limit   int64
	// Window of the refusing limit, seconds (1, 60, 86400, the month's length)
	Window int64
	// ResetMS until the refusing limit lets a call through again
	ResetMS int64
}

// RetryAfter is ResetMS in whole seconds, at least 1.
func (d Decision) RetryAfter() int64 {
	s := int64(math.Ceil(float64(d.ResetMS) / 1000))
	if s < 1 {
		return 1
	}
	return s
}

// Limiter counts calls of a subject ("c:<consumer>" or "k:<key>") against a plan.
type Limiter interface {
	Allow(ctx context.Context, subject string, p *Plan, now time.Time) (Decision, error)
}

func val(v *int64) int64 {
	if v == nil {
		return 0
	}
	return *v
}

func burstOf(p *Plan) int64 {
	if b := val(p.Burst); b > 0 {
		return b
	}
	return val(p.PerSecond)
}

// windows of now: the minute index, the day and month keys, ms until the next day and month.
type windows struct {
	minute       int64
	day, month   string
	dayResetMS   int64
	monthResetMS int64
	monthSeconds int64
}

func windowsAt(now time.Time) windows {
	now = now.UTC()
	nextDay := time.Date(now.Year(), now.Month(), now.Day()+1, 0, 0, 0, 0, time.UTC)
	monthStart := time.Date(now.Year(), now.Month(), 1, 0, 0, 0, 0, time.UTC)
	nextMonth := monthStart.AddDate(0, 1, 0)
	return windows{
		minute:       now.UnixMilli() / 60000,
		day:          now.Format("20060102"),
		month:        now.Format("200601"),
		dayResetMS:   nextDay.Sub(now).Milliseconds(),
		monthResetMS: nextMonth.Sub(now).Milliseconds(),
		monthSeconds: int64(nextMonth.Sub(monthStart).Seconds()),
	}
}

// ── In memory (one pod; a region without Redis, or while Redis is unreachable) ──

type bucket struct {
	tokens float64
	ts     int64
}

// MemoryLimiter keeps the counters in the pod.
type MemoryLimiter struct {
	mu      sync.Mutex
	buckets map[string]*bucket
	counts  map[string]int64
	swept   int64
}

func NewMemoryLimiter() *MemoryLimiter {
	return &MemoryLimiter{buckets: map[string]*bucket{}, counts: map[string]int64{}}
}

func (m *MemoryLimiter) Allow(_ context.Context, subject string, p *Plan, now time.Time) (Decision, error) {
	if !p.Limited() {
		return Decision{Allowed: true}, nil
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	nowMS := now.UnixMilli()
	w := windowsAt(now)
	m.sweep(w)

	var b *bucket
	var tokens float64
	if rate := val(p.PerSecond); rate > 0 {
		burst := float64(burstOf(p))
		b = m.buckets[subject]
		if b == nil {
			b = &bucket{tokens: burst, ts: nowMS}
			m.buckets[subject] = b
		}
		tokens = math.Min(burst, b.tokens+float64(max(0, nowMS-b.ts))*float64(rate)/1000)
		if tokens < 1 {
			return Decision{Reason: ReasonRateLimited, Limit: rate, Window: 1, ResetMS: int64(math.Ceil((1 - tokens) * 1000 / float64(rate)))}, nil
		}
	}

	curKey := fmt.Sprintf("%s|m|%d", subject, w.minute)
	prevKey := fmt.Sprintf("%s|m|%d", subject, w.minute-1)
	if limit := val(p.PerMinute); limit > 0 {
		within := float64(nowMS%60000) / 60000
		est := float64(m.counts[prevKey])*(1-within) + float64(m.counts[curKey])
		if est+1 > float64(limit) {
			return Decision{Reason: ReasonRateLimited, Limit: limit, Window: 60, ResetMS: 60000 - nowMS%60000}, nil
		}
	}
	dayKey := subject + "|d|" + w.day
	if limit := val(p.PerDay); limit > 0 && m.counts[dayKey]+1 > limit {
		return Decision{Reason: ReasonQuotaExceeded, Limit: limit, Window: 86400, ResetMS: w.dayResetMS}, nil
	}
	monthKey := subject + "|mo|" + w.month
	if limit := val(p.PerMonth); limit > 0 && m.counts[monthKey]+1 > limit {
		return Decision{Reason: ReasonQuotaExceeded, Limit: limit, Window: w.monthSeconds, ResetMS: w.monthResetMS}, nil
	}

	if b != nil {
		b.tokens = tokens - 1
		b.ts = nowMS
	}
	if val(p.PerMinute) > 0 {
		m.counts[curKey]++
	}
	if val(p.PerDay) > 0 {
		m.counts[dayKey]++
	}
	if val(p.PerMonth) > 0 {
		m.counts[monthKey]++
	}
	return Decision{Allowed: true}, nil
}

// sweep drops counters of past windows once a minute.
func (m *MemoryLimiter) sweep(w windows) {
	if m.swept == w.minute {
		return
	}
	m.swept = w.minute
	keep := map[string]bool{
		fmt.Sprintf("|m|%d", w.minute): true, fmt.Sprintf("|m|%d", w.minute-1): true,
		"|d|" + w.day: true, "|mo|" + w.month: true,
	}
	for k := range m.counts {
		ok := false
		for suffix := range keep {
			if len(k) >= len(suffix) && k[len(k)-len(suffix):] == suffix {
				ok = true
				break
			}
		}
		if !ok {
			delete(m.counts, k)
		}
	}
	for k, b := range m.buckets {
		if w.minute*60000-b.ts > 3600000 {
			delete(m.buckets, k)
		}
	}
}

// ── Redis (the region's: every pod shares the counters) ──

// limitScript checks every limit and counts the call only when all pass. Keys share the subject's
// hash tag (one slot in a Redis Cluster).
//
//	KEYS  1 token bucket  2 this minute  3 previous minute  4 day  5 month
//	ARGV  1 now ms  2 per second  3 burst  4 per minute  5 per day  6 per month
//	      7 ms to the next day  8 ms to the next month  9 month seconds
//	→ {allowed, reason (1 rate, 2 quota), limit, window, reset ms}
const limitScript = `
local now = tonumber(ARGV[1])
local rate = tonumber(ARGV[2])
local burst = tonumber(ARGV[3])
local perMin = tonumber(ARGV[4])
local perDay = tonumber(ARGV[5])
local perMonth = tonumber(ARGV[6])
local dayReset = tonumber(ARGV[7])
local monthReset = tonumber(ARGV[8])
local monthSeconds = tonumber(ARGV[9])

local tokens = 0
if rate > 0 then
  local state = redis.call('HMGET', KEYS[1], 't', 'ts')
  local t = tonumber(state[1])
  local ts = tonumber(state[2])
  if t == nil or ts == nil then
    t = burst
    ts = now
  end
  local elapsed = now - ts
  if elapsed < 0 then elapsed = 0 end
  t = math.min(burst, t + elapsed * rate / 1000)
  if t < 1 then
    return {0, 1, rate, 1, math.ceil((1 - t) * 1000 / rate)}
  end
  tokens = t
end

if perMin > 0 then
  local cur = tonumber(redis.call('GET', KEYS[2]) or '0')
  local prev = tonumber(redis.call('GET', KEYS[3]) or '0')
  local within = (now % 60000) / 60000
  if prev * (1 - within) + cur + 1 > perMin then
    return {0, 1, perMin, 60, 60000 - (now % 60000)}
  end
end

if perDay > 0 then
  local used = tonumber(redis.call('GET', KEYS[4]) or '0')
  if used + 1 > perDay then
    return {0, 2, perDay, 86400, dayReset}
  end
end

if perMonth > 0 then
  local used = tonumber(redis.call('GET', KEYS[5]) or '0')
  if used + 1 > perMonth then
    return {0, 2, perMonth, monthSeconds, monthReset}
  end
end

if rate > 0 then
  redis.call('HSET', KEYS[1], 't', tostring(tokens - 1), 'ts', tostring(now))
  redis.call('PEXPIRE', KEYS[1], math.ceil(burst * 1000 / rate) + 1000)
end
if perMin > 0 then
  redis.call('INCR', KEYS[2])
  redis.call('PEXPIRE', KEYS[2], 120000)
end
if perDay > 0 then
  redis.call('INCR', KEYS[4])
  redis.call('PEXPIRE', KEYS[4], dayReset + 3600000)
end
if perMonth > 0 then
  redis.call('INCR', KEYS[5])
  redis.call('PEXPIRE', KEYS[5], monthReset + 86400000)
end
return {1, 0, 0, 0, 0}
`

// RedisLimiter keeps the counters in the region's Redis; when Redis does not answer it counts in
// the pod (Fallback) rather than refusing every call.
type RedisLimiter struct {
	client   redis.UniversalClient
	script   *redis.Script
	Fallback Limiter
	OnError  func(error)
}

func NewRedisLimiter(client redis.UniversalClient) *RedisLimiter {
	return &RedisLimiter{client: client, script: redis.NewScript(limitScript), Fallback: NewMemoryLimiter()}
}

func (r *RedisLimiter) Allow(ctx context.Context, subject string, p *Plan, now time.Time) (Decision, error) {
	if !p.Limited() {
		return Decision{Allowed: true}, nil
	}
	w := windowsAt(now)
	tag := "gwa:{" + subject + "}"
	keys := []string{
		tag + ":tb",
		fmt.Sprintf("%s:m:%d", tag, w.minute),
		fmt.Sprintf("%s:m:%d", tag, w.minute-1),
		tag + ":d:" + w.day,
		tag + ":mo:" + w.month,
	}
	args := []any{now.UnixMilli(), val(p.PerSecond), burstOf(p), val(p.PerMinute), val(p.PerDay), val(p.PerMonth), w.dayResetMS, w.monthResetMS, w.monthSeconds}

	res, err := r.script.Run(ctx, r.client, keys, args...).Int64Slice()
	if err != nil || len(res) != 5 {
		if err == nil {
			err = fmt.Errorf("limit script answered %d values", len(res))
		}
		if r.OnError != nil {
			r.OnError(err)
		}
		return r.Fallback.Allow(ctx, subject, p, now)
	}
	if res[0] == 1 {
		return Decision{Allowed: true}, nil
	}
	reason := ReasonRateLimited
	if res[1] == 2 {
		reason = ReasonQuotaExceeded
	}
	return Decision{Reason: reason, Limit: res[2], Window: res[3], ResetMS: res[4]}, nil
}
