package provider

import (
	"context"
	"fmt"
	"testing"

	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"
	"github.com/hashicorp/terraform-plugin-testing/tfjsonpath"

	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

// A schedule trigger's time zone (docs/triggers.md#schedules-and-time-zones): trigger.timezone
// is an IANA zone or "" (the organization's), trigger.effective_timezone the zone it runs in.

func cronProject(srv *runrtest.Server, trigger string) string {
	return providerBlock(srv) + fmt.Sprintf(`
resource "runr_project" "cron" {
  name      = "cron"
  type      = "caas"
  region_id = data.runr_region.eu.id
  image     = "nginx:1.27"
  trigger   = %s
}

data "runr_project" "cron" {
  name       = runr_project.cron.name
  depends_on = [runr_project.cron]
}
`, trigger)
}

// serverZone checks the schedule's zone the fake API holds ("" = the organization's).
func serverZone(srv *runrtest.Server, own, effective string) resource.TestCheckFunc {
	return func(*terraform.State) error {
		p, ok := srv.Project("cron")
		if !ok {
			return fmt.Errorf("project cron not on the API")
		}
		if p.Config.ScheduleTimezone != own || p.Config.ScheduleEffectiveTimezone != effective {
			return fmt.Errorf("server zone %q / effective %q, want %q / %q", p.Config.ScheduleTimezone, p.Config.ScheduleEffectiveTimezone, own, effective)
		}
		return nil
	}
}

func TestProjectScheduleTimezone(t *testing.T) {
	srv := fake(t)
	srv.OrgTimezone = "Europe/Berlin"
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "cron"),
		Steps: []resource.TestStep{
			{
				Config: cronProject(srv, `{ type = "schedule", schedule = "0 3 * * *", timezone = "Asia/Tokyo" }`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_project.cron", "trigger.timezone", "Asia/Tokyo"),
					resource.TestCheckResourceAttr("runr_project.cron", "trigger.effective_timezone", "Asia/Tokyo"),
					resource.TestCheckResourceAttr("data.runr_project.cron", "schedule_timezone", "Asia/Tokyo"),
					resource.TestCheckResourceAttr("data.runr_project.cron", "schedule_effective_timezone", "Asia/Tokyo"),
					serverZone(srv, "Asia/Tokyo", "Asia/Tokyo"),
				),
			},
			{
				ResourceName:      "runr_project.cron",
				ImportState:       true,
				ImportStateId:     "cron",
				ImportStateVerify: true,
			},
			{
				// left out: the schedule keeps its zone
				Config: cronProject(srv, `{ type = "schedule", schedule = "0 3 * * *" }`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectEmptyPlan(),
				}},
				Check: serverZone(srv, "Asia/Tokyo", "Asia/Tokyo"),
			},
			{
				// another cron keeps the zone too, and the effective zone stays known in the plan
				Config: cronProject(srv, `{ type = "schedule", schedule = "0 4 * * *" }`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_project.cron", "trigger.schedule", "0 4 * * *"),
					resource.TestCheckResourceAttr("runr_project.cron", "trigger.timezone", "Asia/Tokyo"),
					serverZone(srv, "Asia/Tokyo", "Asia/Tokyo"),
				),
			},
			{
				// "": the organization's zone, which the schedule follows
				Config: cronProject(srv, `{ type = "schedule", schedule = "0 4 * * *", timezone = "" }`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_project.cron", plancheck.ResourceActionUpdate),
					plancheck.ExpectUnknownValue("runr_project.cron", tfjsonpath.New("trigger").AtMapKey("effective_timezone")),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_project.cron", "trigger.timezone", ""),
					resource.TestCheckResourceAttr("runr_project.cron", "trigger.effective_timezone", "Europe/Berlin"),
					resource.TestCheckNoResourceAttr("data.runr_project.cron", "schedule_timezone"),
					resource.TestCheckResourceAttr("data.runr_project.cron", "schedule_effective_timezone", "Europe/Berlin"),
					serverZone(srv, "", "Europe/Berlin"),
				),
			},
			{
				Config:   cronProject(srv, `{ type = "schedule", schedule = "0 4 * * *", timezone = "" }`),
				PlanOnly: true,
			},
			{
				// no schedule, no zone
				Config: cronProject(srv, `{ type = "http" }`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckNoResourceAttr("runr_project.cron", "trigger.timezone"),
					resource.TestCheckNoResourceAttr("runr_project.cron", "trigger.effective_timezone"),
					serverZone(srv, "", ""),
				),
			},
		},
	})
}

// A new schedule without a zone runs in the organization's.
func TestProjectScheduleWithoutTimezone(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "cron"),
		Steps: []resource.TestStep{
			{
				Config: cronProject(srv, `{ type = "schedule", schedule = "*/5 * * * *" }`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckNoResourceAttr("runr_project.cron", "trigger.timezone"),
					resource.TestCheckResourceAttr("runr_project.cron", "trigger.effective_timezone", "Europe/Istanbul"),
					serverZone(srv, "", "Europe/Istanbul"),
				),
			},
			{
				Config:   cronProject(srv, `{ type = "schedule", schedule = "*/5 * * * *" }`),
				PlanOnly: true,
			},
			{
				// a zone set later
				Config: cronProject(srv, `{ type = "schedule", schedule = "*/5 * * * *", timezone = "UTC" }`),
				Check:  serverZone(srv, "UTC", "UTC"),
			},
		},
	})
}

func TestProjectScheduleTimezoneValidation(t *testing.T) {
	srv := fake(t)
	cases := map[string]string{
		`{ type = "schedule", schedule = "0 3 * * *", timezone = "Mars/Olympus" }`: "not an IANA time zone",
		`{ type = "schedule", schedule = "0 3 * * *", timezone = "Local" }`:        "not an IANA time zone",
		`{ type = "http", timezone = "Europe/Berlin" }`:                            "timezone applies to a schedule trigger",
	}
	for trigger, want := range cases {
		resource.UnitTest(t, resource.TestCase{
			ProtoV6ProviderFactories: providerFactories(),
			Steps: []resource.TestStep{{
				Config:      cronProject(srv, trigger),
				ExpectError: phrase(want),
			}},
		})
	}
}

func TestTimezoneValidatorUnit(t *testing.T) {
	for zone, ok := range map[string]bool{"": true, "UTC": true, "Europe/Berlin": true, "Europe/Kiev": true, "Mars/Olympus": false, "Local": false} {
		resp := &validator.StringResponse{}
		timezoneValidator{}.ValidateString(context.Background(), validator.StringRequest{Path: path.Root("tz"), ConfigValue: types.StringValue(zone)}, resp)
		if resp.Diagnostics.HasError() == ok {
			t.Errorf("%q: errors %v, want ok=%v", zone, resp.Diagnostics, ok)
		}
	}
	if got := triggerTimezone("", types.StringValue("")); got.ValueString() != "" || got.IsNull() {
		t.Errorf(`"" configured: %v`, got)
	}
	if got := triggerTimezone("", types.StringNull()); !got.IsNull() {
		t.Errorf("left out: %v", got)
	}
	if got := triggerTimezone("UTC", types.StringNull()); got.ValueString() != "UTC" {
		t.Errorf("own zone: %v", got)
	}
}
