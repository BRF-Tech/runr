package runr

import (
	"fmt"
	"strconv"
	"strings"
)

// The timed warmer (config.scale_schedule; older APIs do not send it): rows of a five-field cron
// expression and a container count. From the moment a row's expression fires — in the
// organization's time zone (Settings, default Europe/Istanbul) — at least MinScale containers stay
// warm until the next row fires. While a schedule is set it replaces min_scale; max_scale still
// applies. The API applies a changed schedule to the running workload within a minute, without a
// redeploy.
const (
	// MaxScaleScheduleRows is the most rows a schedule may have.
	MaxScaleScheduleRows = 10
	// MaxWarmScale is the most containers one row may keep warm (the min_scale limit).
	MaxWarmScale = 10
)

// ScaleScheduleRow is one row of config.scale_schedule.
type ScaleScheduleRow struct {
	Cron     string `json:"cron"`
	MinScale int    `json:"min_scale"`
}

// String renders the row as the CLI's --warm flag takes it: "<cron>=<count>".
func (r ScaleScheduleRow) String() string {
	return r.Cron + "=" + strconv.Itoa(r.MinScale)
}

// NormalizeCron trims a cron expression and joins its fields with single spaces (the form the
// API stores).
func NormalizeCron(expr string) string {
	return strings.Join(strings.Fields(expr), " ")
}

// ValidateScaleSchedule checks rows locally the way the API does what it can without a cron
// parser: at most MaxScaleScheduleRows rows, five fields (no macros, no seconds field), a count of
// 0-MaxWarmScale and, when maxScale is known (> 0), not above it. The API checks the expressions
// themselves.
func ValidateScaleSchedule(rows []ScaleScheduleRow, maxScale int) error {
	if len(rows) > MaxScaleScheduleRows {
		return fmt.Errorf("the timed warmer takes at most %d rows, got %d", MaxScaleScheduleRows, len(rows))
	}
	for i, r := range rows {
		fields := strings.Fields(r.Cron)
		switch {
		case len(fields) == 1 && strings.HasPrefix(fields[0], "@"):
			return fmt.Errorf("row %d: %q: use a five-field cron expression instead of a macro", i+1, r.Cron)
		case len(fields) != 5:
			return fmt.Errorf("row %d: %q must be a cron expression with five fields (minute hour day-of-month month day-of-week), e.g. \"0 9 * * 1-5\"", i+1, r.Cron)
		}
		if r.MinScale < 0 || r.MinScale > MaxWarmScale {
			return fmt.Errorf("row %d: the container count must be between 0 and %d", i+1, MaxWarmScale)
		}
		if maxScale > 0 && r.MinScale > maxScale {
			return fmt.Errorf("row %d: %d containers is above the maximum scale %d", i+1, r.MinScale, maxScale)
		}
	}
	return nil
}

// ParseScaleScheduleRow parses "<cron>=<count>", e.g. "0 9 * * 1-5=3". The count follows the
// last "=" (cron expressions have none).
func ParseScaleScheduleRow(s string) (ScaleScheduleRow, error) {
	i := strings.LastIndex(s, "=")
	if i < 0 {
		return ScaleScheduleRow{}, fmt.Errorf("%q: want \"<cron>=<count>\", e.g. \"0 9 * * 1-5=3\"", s)
	}
	n, err := strconv.Atoi(strings.TrimSpace(s[i+1:]))
	if err != nil {
		return ScaleScheduleRow{}, fmt.Errorf("%q: the container count after \"=\" must be a whole number", s)
	}
	return ScaleScheduleRow{Cron: NormalizeCron(s[:i]), MinScale: n}, nil
}

// EqualScaleSchedules reports whether two schedules have the same rows in the same order (the
// expressions compared in their normalized form).
func EqualScaleSchedules(a, b []ScaleScheduleRow) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if NormalizeCron(a[i].Cron) != NormalizeCron(b[i].Cron) || a[i].MinScale != b[i].MinScale {
			return false
		}
	}
	return true
}

// ScaleScheduleLabel renders a schedule on one line: "0 9 * * 1-5 → 3, 0 18 * * 1-5 → 0".
func ScaleScheduleLabel(rows []ScaleScheduleRow) string {
	parts := make([]string, len(rows))
	for i, r := range rows {
		parts[i] = r.Cron + " → " + strconv.Itoa(r.MinScale)
	}
	return strings.Join(parts, ", ")
}
