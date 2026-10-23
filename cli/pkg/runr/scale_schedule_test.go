package runr_test

import (
	"context"
	"encoding/json"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func TestParseScaleScheduleRow(t *testing.T) {
	r, err := runr.ParseScaleScheduleRow("  0  9 * * 1-5 = 3 ")
	if err != nil || r.Cron != "0 9 * * 1-5" || r.MinScale != 3 {
		t.Fatalf("row = %+v, %v", r, err)
	}
	if r.String() != "0 9 * * 1-5=3" {
		t.Fatalf("String() = %q", r.String())
	}
	// Lists and steps keep their commas and slashes
	if r, err := runr.ParseScaleScheduleRow("*/30 8,12 * * MON-FRI=2"); err != nil || r.Cron != "*/30 8,12 * * MON-FRI" || r.MinScale != 2 {
		t.Fatalf("row = %+v, %v", r, err)
	}
	for _, bad := range []string{"0 9 * * 1-5", "0 9 * * 1-5=three", "0 9 * * 1-5="} {
		if _, err := runr.ParseScaleScheduleRow(bad); err == nil {
			t.Errorf("ParseScaleScheduleRow(%q) accepted", bad)
		}
	}
}

func TestValidateScaleSchedule(t *testing.T) {
	ok := []runr.ScaleScheduleRow{{Cron: "0 9 * * 1-5", MinScale: 3}, {Cron: "0 18 * * 1-5", MinScale: 0}}
	if err := runr.ValidateScaleSchedule(ok, 5); err != nil {
		t.Fatal(err)
	}
	if err := runr.ValidateScaleSchedule(nil, 0); err != nil {
		t.Fatal("an empty schedule turns the warmer off", err)
	}
	many := make([]runr.ScaleScheduleRow, runr.MaxScaleScheduleRows+1)
	for i := range many {
		many[i] = runr.ScaleScheduleRow{Cron: "0 9 * * *", MinScale: 1}
	}
	cases := map[string]struct {
		rows []runr.ScaleScheduleRow
		max  int
		want string
	}{
		"too many rows": {many, 0, "at most 10 rows"},
		"six fields":    {[]runr.ScaleScheduleRow{{Cron: "0 0 9 * * *", MinScale: 1}}, 0, "five fields"},
		"macro":         {[]runr.ScaleScheduleRow{{Cron: "@daily", MinScale: 1}}, 0, "macro"},
		"count":         {[]runr.ScaleScheduleRow{{Cron: "0 9 * * *", MinScale: 11}}, 0, "between 0 and 10"},
		"negative":      {[]runr.ScaleScheduleRow{{Cron: "0 9 * * *", MinScale: -1}}, 0, "between 0 and 10"},
		"above max":     {[]runr.ScaleScheduleRow{{Cron: "0 9 * * *", MinScale: 4}}, 3, "above the maximum scale 3"},
	}
	for name, c := range cases {
		err := runr.ValidateScaleSchedule(c.rows, c.max)
		if err == nil || !strings.Contains(err.Error(), c.want) {
			t.Errorf("%s: err = %v, want %q", name, err, c.want)
		}
	}
}

func TestScaleScheduleOnTheWire(t *testing.T) {
	// A pointer to an empty list is sent as [] (turns the warmer off); nil is not sent at all
	off := []runr.ScaleScheduleRow{}
	body, _ := json.Marshal(runr.ConfigInput{ScaleSchedule: &off})
	if string(body) != `{"scale_schedule":[]}` {
		t.Fatalf("off = %s", body)
	}
	if body, _ := json.Marshal(runr.ConfigInput{}); string(body) != `{}` {
		t.Fatalf("unset = %s", body)
	}
	if (&runr.ConfigInput{ScaleSchedule: &off}).Empty() {
		t.Fatal("a config that turns the warmer off is not empty")
	}

	var p runr.Project
	if err := json.Unmarshal([]byte(`{"id":"p1","config":{"min_scale":0,"max_scale":5,"scale_schedule":[{"cron":"0 9 * * 1-5","min_scale":3}]},
		"warm_scale":{"applied":3,"applied_at":"2026-10-21T06:00:00Z"}}`), &p); err != nil {
		t.Fatal(err)
	}
	if len(p.Config.ScaleSchedule) != 1 || p.Config.ScaleSchedule[0].MinScale != 3 || p.WarmScale == nil || *p.WarmScale.Applied != 3 || p.WarmScale.AppliedAt == nil {
		t.Fatalf("decoded = %+v / %+v", p.Config.ScaleSchedule, p.WarmScale)
	}
	if !strings.Contains(runr.ScaleScheduleLabel(p.Config.ScaleSchedule), "0 9 * * 1-5 → 3") {
		t.Fatalf("label = %q", runr.ScaleScheduleLabel(p.Config.ScaleSchedule))
	}
	if !runr.EqualScaleSchedules([]runr.ScaleScheduleRow{{Cron: "0  9 * * 1-5", MinScale: 3}}, p.Config.ScaleSchedule) {
		t.Fatal("the same rows with other spacing are equal")
	}
}

func TestUpdateProjectScaleSchedule(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("office", runr.TypeFaaS, "python", "eu-1", nil)

	rows := []runr.ScaleScheduleRow{{Cron: "0 9 * * 1-5", MinScale: 3}, {Cron: "0 18 * * 1-5", MinScale: 0}}
	got, err := c.UpdateProject(ctx, p.ID, runr.UpdateProjectInput{Config: &runr.ConfigInput{ScaleSchedule: &rows}})
	if err != nil {
		t.Fatal(err)
	}
	if !runr.EqualScaleSchedules(got.Config.ScaleSchedule, rows) {
		t.Fatalf("schedule = %+v", got.Config.ScaleSchedule)
	}

	// The API checks the count against max_scale (5 in the fake)
	high := []runr.ScaleScheduleRow{{Cron: "0 9 * * *", MinScale: 6}}
	if _, err := c.UpdateProject(ctx, p.ID, runr.UpdateProjectInput{Config: &runr.ConfigInput{ScaleSchedule: &high}}); !runr.IsValidation(err) {
		t.Fatalf("a count above max_scale: err = %v, want a validation error", err)
	}

	off := []runr.ScaleScheduleRow{}
	got, err = c.UpdateProject(ctx, p.ID, runr.UpdateProjectInput{Config: &runr.ConfigInput{ScaleSchedule: &off}})
	if err != nil || len(got.Config.ScaleSchedule) != 0 {
		t.Fatalf("off: %+v, %v", got.Config.ScaleSchedule, err)
	}
}
