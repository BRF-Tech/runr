package provider

import (
	"fmt"
	"testing"
	"time"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestRuntimePatchesDataSource(t *testing.T) {
	srv := fake(t)
	p := srv.SeedProject("api", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SeedFiles("api", map[string]string{"main.py": "def handler(event): return 1\n"})
	srv.SeedDeployment("api", 1)
	quiet := srv.SeedProject("quiet", runr.TypeCaaS, "", "eu-1", map[string]any{"image": "nginx:1.27"})
	older := srv.SeedRuntimePatch(runr.RuntimePatch{Runtime: "python", Version: "3.13", Tag: "3.13.8-r1", Level: runr.PatchRoutine,
		Reason: "Python 3.13.8", PublishedAt: time.Now().Add(-72 * time.Hour).UTC().Format(time.RFC3339)})
	newer := srv.SeedRuntimePatch(runr.RuntimePatch{Runtime: "python", Version: "3.13", Tag: "3.13.9-r1", Level: runr.PatchCritical,
		Reason: "OpenSSL: TLS session fix", CVEs: []string{"CVE-2026-1111"}, URL: "https://example.com/advisories/1111",
		PublishedAt: time.Now().Add(-time.Hour).UTC().Format(time.RFC3339), DeadlineAt: time.Now().Add(48 * time.Hour).UTC().Format(time.RFC3339)})
	srv.SeedRunningPatch("api", older.ID)
	data := providerBlock(srv) + fmt.Sprintf(`
data "runr_runtime_patches" "api" {
  project_id = %q
}
data "runr_runtime_patches" "quiet" {
  project_id = %q
}
`, p.ID, quiet.ID)
	ds := "data.runr_runtime_patches.api"
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: data,
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr(ds, "state", "patch_available"),
					resource.TestCheckResourceAttr(ds, "level", "critical"),
					resource.TestCheckResourceAttr(ds, "deadline_at", newer.DeadlineAt),
					resource.TestCheckResourceAttr(ds, "deployed", "true"),
					resource.TestCheckResourceAttr(ds, "current.tag", "3.13.8-r1"),
					resource.TestCheckResourceAttr(ds, "latest.tag", "3.13.9-r1"),
					resource.TestCheckResourceAttr(ds, "latest.level", "critical"),
					resource.TestCheckNoResourceAttr(ds, "pin.id"),
					resource.TestCheckResourceAttr(ds, "pending.#", "1"),
					resource.TestCheckResourceAttr(ds, "pending.0", newer.ID),
					resource.TestCheckResourceAttr(ds, "auto_migrate", "true"),
					resource.TestCheckResourceAttr(ds, "keep", "5"),
					resource.TestCheckResourceAttr(ds, "patches.#", "2"),
					resource.TestCheckResourceAttr(ds, "patches.0.tag", "3.13.9-r1"),
					resource.TestCheckResourceAttr(ds, "patches.0.cves.0", "CVE-2026-1111"),
					resource.TestCheckResourceAttr(ds, "patches.0.reason", "OpenSSL: TLS session fix"),
					resource.TestCheckNoResourceAttr(ds, "patches.1.deadline_at"),
					resource.TestCheckNoResourceAttr("data.runr_runtime_patches.quiet", "state"),
					resource.TestCheckResourceAttr("data.runr_runtime_patches.quiet", "patches.#", "0"),
				),
			},
			{
				// Pinned with `runr projects patch pin` (an action): the data source shows the pin.
				PreConfig: func() {
					if _, _, err := client(t, srv).PinRuntimePatch(t.Context(), p.ID, older.ID, false); err != nil {
						t.Fatal(err)
					}
				},
				Config: data,
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr(ds, "state", "pinned"),
					resource.TestCheckResourceAttr(ds, "pin.id", older.ID),
					resource.TestCheckResourceAttr(ds, "pin.tag", "3.13.8-r1"),
					resource.TestCheckResourceAttr(ds, "pin.available", "true"),
				),
			},
			{
				Config: providerBlock(srv) + `
data "runr_runtime_patches" "missing" {
  project_id = "01J99999999999999999999999"
}
`,
				ExpectError: phrase("404"),
			},
			{
				PreConfig:   func() { srv.Without("GET /projects/{project}/runtime-patches") },
				Config:      data,
				ExpectError: phrase("Runtime patches not available"),
			},
		},
	})
}

func TestRightsizingDataSource(t *testing.T) {
	srv := fake(t)
	big := srv.SeedProject("big", runr.TypeFaaS, "python", "eu-1", nil)
	young := srv.SeedProject("young", runr.TypeFaaS, "python", "eu-1", nil)
	gpu := srv.SeedProject("gpu", runr.TypeFaaS, "python", "eu-1", nil)
	monthly, maxMem, ratio := runr.FlexFloat(12.5), runr.FlexFloat(180.4), runr.FlexFloat(0.01)
	p95 := 40
	srv.SeedRecommendation("big", runr.Recommendation{Direction: "down",
		Current:     runr.RightsizingSize{CPUM: 1000, MemoryMB: 1024},
		Recommended: runr.RightsizingSize{CPUM: 250, MemoryMB: 256},
		Reasons:     []string{"Memory peaked at 180 MiB of 1024 MiB."},
		Evidence:    runr.RightsizingEvidence{WindowDays: 14, Days: 14, Sessions: 120, MemoryMaxMB: &maxMem, CPUP95M: &p95, ThrottledRatio: ratio},
		Savings:     &runr.RightsizingSavings{Basis: "reserved", Monthly: &monthly, Currency: "EUR"},
		ComputedAt:  "2026-10-22T03:00:00Z"})
	srv.SetRightsizingState("young", runr.ProjectRightsizing{Status: "collecting",
		Collecting: &runr.RightsizingCollecting{Days: 1, RequiredDays: 3, Sessions: 4, RequiredSessions: 20, WindowDays: 14}})
	srv.SetRightsizingState("gpu", runr.ProjectRightsizing{Status: "not_eligible", Reason: "gpu"})
	data := providerBlock(srv) + fmt.Sprintf(`
data "runr_rightsizing" "big" {
  project_id = %q
}
data "runr_rightsizing" "young" {
  project_id = %q
}
data "runr_rightsizing" "gpu" {
  project_id = %q
}
`, big.ID, young.ID, gpu.ID)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: data,
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("data.runr_rightsizing.big", "status", "open"),
					resource.TestCheckResourceAttr("data.runr_rightsizing.big", "direction", "down"),
					resource.TestCheckResourceAttr("data.runr_rightsizing.big", "current.cpu", "1000m"),
					resource.TestCheckResourceAttr("data.runr_rightsizing.big", "recommended.cpu", "250m"),
					resource.TestCheckResourceAttr("data.runr_rightsizing.big", "recommended.cpu_m", "250"),
					resource.TestCheckResourceAttr("data.runr_rightsizing.big", "recommended.memory_mb", "256"),
					resource.TestCheckResourceAttr("data.runr_rightsizing.big", "reasons.0", "Memory peaked at 180 MiB of 1024 MiB."),
					resource.TestCheckResourceAttr("data.runr_rightsizing.big", "evidence.memory_max_mb", "180.4"),
					resource.TestCheckResourceAttr("data.runr_rightsizing.big", "evidence.cpu_p95_m", "40"),
					resource.TestCheckNoResourceAttr("data.runr_rightsizing.big", "evidence.cpu_max_m"),
					resource.TestCheckResourceAttr("data.runr_rightsizing.big", "savings.monthly", "12.5"),
					resource.TestCheckResourceAttr("data.runr_rightsizing.big", "savings.currency", "EUR"),
					resource.TestCheckNoResourceAttr("data.runr_rightsizing.big", "blocked.code"),
					resource.TestCheckNoResourceAttr("data.runr_rightsizing.big", "collecting.days"),
					resource.TestCheckResourceAttr("data.runr_rightsizing.young", "status", "collecting"),
					resource.TestCheckResourceAttr("data.runr_rightsizing.young", "collecting.required_days", "3"),
					resource.TestCheckNoResourceAttr("data.runr_rightsizing.young", "recommended.cpu"),
					resource.TestCheckResourceAttr("data.runr_rightsizing.gpu", "status", "not_eligible"),
					resource.TestCheckResourceAttr("data.runr_rightsizing.gpu", "reason", "gpu"),
				),
			},
			{
				// A configuration feeds the project's size from the recommendation explicitly.
				Config: providerBlock(srv) + fmt.Sprintf(`
data "runr_rightsizing" "big" {
  project_id = %q
}
resource "runr_project" "app" {
  name      = "app"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id
  resources = {
    cpu       = data.runr_rightsizing.big.recommended.cpu
    memory_mb = data.runr_rightsizing.big.recommended.memory_mb
  }
}
`, big.ID),
				Check: check(func() error {
					p, _ := srv.Project("app")
					if p.Config.CPU != "250m" || p.Config.MemoryMB == nil || *p.Config.MemoryMB != 256 {
						return fmt.Errorf("size = %v / %v", p.Config.CPU, p.Config.MemoryMB)
					}
					return nil
				}),
			},
			{
				Config: providerBlock(srv) + `
data "runr_rightsizing" "missing" {
  project_id = "01J99999999999999999999999"
}
`,
				ExpectError: phrase("404"),
			},
			{
				PreConfig:   func() { srv.Without("GET /projects/{project}/rightsizing") },
				Config:      data,
				ExpectError: phrase("Rightsizing not available"),
			},
		},
	})
}
