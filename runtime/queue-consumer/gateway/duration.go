package gateway

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/brf-tech/runr/runtime/queue-consumer/internal/conf"
)

// Duration is a duration in gateway.json: a Go duration string ("2s", "500ms", "5m"), a number of
// seconds (2, 0.5) or a numeric string ("2"). Negative values are rejected; null and "" leave it
// zero, which means "the default" everywhere it is used.
type Duration time.Duration

// D is the time.Duration.
func (d Duration) D() time.Duration { return time.Duration(d) }

func (d Duration) String() string { return time.Duration(d).String() }

// MarshalJSON writes the Go duration string.
func (d Duration) MarshalJSON() ([]byte, error) {
	return json.Marshal(time.Duration(d).String())
}

// UnmarshalJSON accepts the forms listed on Duration.
func (d *Duration) UnmarshalJSON(b []byte) error {
	b = bytes.TrimSpace(b)
	if len(b) == 0 || string(b) == "null" {
		return nil
	}
	if b[0] == '"' {
		var s string
		if err := json.Unmarshal(b, &s); err != nil {
			return err
		}
		s = strings.TrimSpace(s)
		if s == "" {
			*d = 0
			return nil
		}
		v, err := conf.ParseDuration(s)
		if err != nil {
			return fmt.Errorf("duration %q: %w", s, err)
		}
		*d = Duration(v)
		return nil
	}
	var secs float64
	if err := json.Unmarshal(b, &secs); err != nil {
		return fmt.Errorf("duration %s: not a duration string or a number of seconds", b)
	}
	if secs < 0 {
		return errors.New("duration: negative duration")
	}
	*d = Duration(secs * float64(time.Second))
	return nil
}
