// Package conf reads the gateway's environment variables and durations the way the queue
// consumer always has: an invalid value logs a warning and falls back to the default.
package conf

import (
	"errors"
	"log"
	"os"
	"strconv"
	"strings"
	"time"
)

// ParseDuration accepts Go durations ("2s", "500ms", "1m") or plain seconds ("2", "0.5").
// Negative durations are rejected.
func ParseDuration(v string) (time.Duration, error) {
	if n, err := strconv.ParseFloat(v, 64); err == nil {
		if n < 0 {
			return 0, errors.New("negative duration")
		}
		return time.Duration(n * float64(time.Second)), nil
	}
	d, err := time.ParseDuration(v)
	if err != nil {
		return 0, err
	}
	if d < 0 {
		return 0, errors.New("negative duration")
	}
	return d, nil
}

// String is getenv(key), or def when that is empty.
func String(getenv func(string) string, key, def string) string {
	if v := getenv(key); v != "" {
		return v
	}
	return def
}

// Int is getenv(key) as an integer, or def when it is empty or not an integer (with a warning).
func Int(getenv func(string) string, key string, def int64) int64 {
	v := strings.TrimSpace(getenv(key))
	if v == "" {
		return def
	}
	n, err := strconv.ParseInt(v, 10, 64)
	if err != nil {
		log.Printf("warning: %s=%q is not an integer, using %d", key, v, def)
		return def
	}
	return n
}

// Duration is getenv(key) parsed by ParseDuration, or def when it is empty or invalid (with a
// warning).
func Duration(getenv func(string) string, key string, def time.Duration) time.Duration {
	v := strings.TrimSpace(getenv(key))
	if v == "" {
		return def
	}
	d, err := ParseDuration(v)
	if err != nil {
		log.Printf("warning: %s=%q: %v, using %s", key, v, err, def)
		return def
	}
	return d
}

// Hostname is the pod name, the default consumer name in a Redis consumer group: a restarted
// pod's pending entries then belong to another consumer and get claimed.
func Hostname() string {
	if h, err := os.Hostname(); err == nil && h != "" {
		return h
	}
	return "consumer-0"
}
