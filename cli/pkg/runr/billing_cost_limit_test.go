package runr_test

import (
	"context"
	"errors"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func TestCostLimit(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SetMonthToDate("svc", 50)

	cl, err := c.GetCostLimit(ctx, p.ID)
	if err != nil || !cl.Available || cl.Limit != nil || cl.State != "none" || cl.Currency != "EUR" || cl.MonthToDate == nil || *cl.MonthToDate != 50 {
		t.Fatalf("no limit = %+v, %v", cl, err)
	}
	cl, err = c.SetCostLimit(ctx, p.ID, runr.CostLimitInput{Amount: 150.125, Action: runr.CostLimitNotify, Currency: "EUR"})
	if err != nil || cl.Limit == nil || cl.Limit.Amount != 150.125 || cl.Limit.Action != "notify" || cl.State != "ok" ||
		cl.Percent == nil || cl.Forecast == nil || cl.Limit.UpdatedBy == nil {
		t.Fatalf("set = %+v, %v", cl, err)
	}
	if call := srv.CallsTo("PUT /projects/{project}/billing/cost-limit")[0]; call.Body["amount"] != 150.125 {
		t.Fatalf("sent %v", call.Body)
	}

	for name, in := range map[string]runr.CostLimitInput{
		"four decimals":  {Amount: 1.2345, Action: "stop"},
		"zero":           {Amount: 0, Action: "stop"},
		"action":         {Amount: 10, Action: "pause"},
		"other currency": {Amount: 10, Action: "stop", Currency: "USD"},
	} {
		if _, err := c.SetCostLimit(ctx, p.ID, in); !runr.IsValidation(err) {
			t.Errorf("%s: %v", name, err)
		}
	}

	// A stop limit the month already passed stops the project at once
	cl, err = c.SetCostLimit(ctx, p.ID, runr.CostLimitInput{Amount: 40, Action: runr.CostLimitStop})
	if err != nil || cl.State != "reached" || !cl.Stopped {
		t.Fatalf("stop limit = %+v, %v", cl, err)
	}
	if got, _ := srv.Project("svc"); got.Stopped == nil {
		t.Fatal("the project was not stopped")
	}
	cl, err = c.DeleteCostLimit(ctx, p.ID)
	if err != nil || cl.Limit != nil || cl.State != "none" || !cl.Stopped || !cl.CanResume {
		t.Fatalf("delete = %+v, %v", cl, err)
	}

	// A fixed-price project has no limit
	q := srv.SeedProject("fixed", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SetFixedPrice("fixed", true)
	var ae *runr.APIError
	if _, err := c.SetCostLimit(ctx, q.ID, runr.CostLimitInput{Amount: 10, Action: "stop"}); !errors.As(err, &ae) || ae.Code != "cost_limit_not_available" {
		t.Fatalf("fixed price: %v", err)
	}
	if cl, err := c.GetCostLimit(ctx, q.ID); err != nil || cl.Available || cl.MonthToDate != nil {
		t.Fatalf("fixed price status = %+v, %v", cl, err)
	}

	// Without the Billing module
	srv.Without("GET /projects/{project}/billing/cost-limit")
	if _, err := c.GetCostLimit(ctx, p.ID); !errors.Is(err, runr.ErrUnsupported) {
		t.Fatalf("billing off: %v", err)
	}
}
