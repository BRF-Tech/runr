package provider

import (
	"fmt"
	"regexp"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/runrsh/core/cli/pkg/runr"
	"github.com/runrsh/core/cli/pkg/runr/runrtest"
)

func modelServicesGone(srv *runrtest.Server, names ...string) resource.TestCheckFunc {
	return func(*terraform.State) error {
		for _, n := range names {
			if _, ok := srv.ModelService(n); ok {
				return fmt.Errorf("model service %s still exists", n)
			}
		}
		return nil
	}
}

func TestModelServiceResource(t *testing.T) {
	srv := fake(t)
	srv.Models().ServicePolls = 2
	cfg := func(extra string) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_model_service" "embed" {
  name      = "minilm"
  hub_id    = "sentence-transformers/all-MiniLM-L6-v2"
  region_id = data.runr_region.eu.id
  min_scale = 1
  env_vars = {
    LOG_LEVEL = "debug"
  }
%s
}
`, extra)
	}
	svc := func() runr.ModelService {
		s, _ := srv.ModelService("minilm")
		return s
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             modelServicesGone(srv, "minilm"),
		Steps: []resource.TestStep{
			{
				Config: cfg(""),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_model_service.embed", "status", runr.ModelServiceReady),
					resource.TestCheckResourceAttr("runr_model_service.embed", "engine", "tei"),
					resource.TestCheckResourceAttr("runr_model_service.embed", "device", "cpu"),
					resource.TestCheckResourceAttr("runr_model_service.embed", "mode", "online"),
					resource.TestCheckResourceAttr("runr_model_service.embed", "api", "tei"),
					resource.TestCheckResourceAttr("runr_model_service.embed", "running", "true"),
					resource.TestCheckResourceAttr("runr_model_service.embed", "min_scale", "1"),
					resource.TestCheckResourceAttrSet("runr_model_service.embed", "model_id"),
					resource.TestCheckResourceAttrSet("runr_model_service.embed", "url"),
					resource.TestCheckResourceAttrSet("runr_model_service.embed", "openai_base"),
					resource.TestCheckNoResourceAttr("runr_model_service.embed", "gpu"),
					check(func() error {
						s := svc()
						if s.Status != runr.ModelServiceReady || s.EnvVars["LOG_LEVEL"] != "debug" || s.MinScale != 1 {
							return fmt.Errorf("service = %+v", s)
						}
						body := srv.CallsTo("POST /model-services")[0].Body
						for _, k := range []string{"engine", "device", "gpu", "image", "args", "port", "deploy"} {
							if _, sent := body[k]; sent {
								return fmt.Errorf("%s sent without being configured: %v", k, body)
							}
						}
						return nil
					}),
				),
			},
			{
				// A change of a running service redeploys it; only the changed settings are sent.
				Config: cfg(`  max_scale = 3
  image     = "ghcr.io/acme/tei:1.6"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_model_service.embed", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_model_service.embed", "max_scale", "3"),
					resource.TestCheckResourceAttr("runr_model_service.embed", "status", runr.ModelServiceReady),
					check(func() error {
						calls := srv.CallsTo("PATCH /model-services/{service}")
						body := calls[len(calls)-1].Body
						if len(body) != 2 || body["max_scale"] != float64(3) || body["image"] != "ghcr.io/acme/tei:1.6" {
							return fmt.Errorf("update body = %v", body)
						}
						if s := svc(); s.MaxScale != 3 || s.Image != "ghcr.io/acme/tei:1.6" {
							return fmt.Errorf("service = %+v", s)
						}
						return nil
					}),
				),
			},
			{
				// Removing an optional setting resets it; running = false stops the service.
				Config: cfg(`  max_scale = 3
  running   = false`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_model_service.embed", "status", runr.ModelServiceStopped),
					resource.TestCheckResourceAttr("runr_model_service.embed", "running", "false"),
					resource.TestCheckNoResourceAttr("runr_model_service.embed", "image"),
					check(func() error {
						calls := srv.CallsTo("PATCH /model-services/{service}")
						body := calls[len(calls)-1].Body
						if v, sent := body["image"]; !sent || v != nil || len(body) != 1 {
							return fmt.Errorf("reset body = %v", body)
						}
						if s := svc(); s.Image != "" || s.Status != runr.ModelServiceStopped {
							return fmt.Errorf("service = %+v", s)
						}
						return nil
					}),
				),
			},
			{
				Config: cfg(`  max_scale = 3`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_model_service.embed", "status", runr.ModelServiceReady),
					resource.TestCheckResourceAttr("runr_model_service.embed", "running", "true"),
				),
			},
			{
				ResourceName:            "runr_model_service.embed",
				ImportState:             true,
				ImportStateId:           "minilm",
				ImportStateVerify:       true,
				ImportStateVerifyIgnore: []string{"hub_id", "revision", "status_message"},
			},
			{
				// A service that failed is deployed again.
				PreConfig: func() {
					c := client(t, srv)
					if _, err := c.StopModelService(t.Context(), svc().ID); err != nil {
						t.Fatal(err)
					}
				},
				Config: cfg(`  max_scale = 3`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_model_service.embed", plancheck.ResourceActionUpdate),
				}},
				Check: resource.TestCheckResourceAttr("runr_model_service.embed", "status", runr.ModelServiceReady),
			},
			{
				// Deleted outside Terraform: created again.
				PreConfig: func() {
					c := client(t, srv)
					if err := c.DeleteModelService(t.Context(), svc().ID); err != nil {
						t.Fatal(err)
					}
				},
				Config: cfg(`  max_scale = 3`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_model_service.embed", plancheck.ResourceActionCreate),
				}},
				Check: resource.TestCheckResourceAttr("runr_model_service.embed", "status", runr.ModelServiceReady),
			},
		},
	})
}

func TestModelServiceResourceGPUAndLibraryModel(t *testing.T) {
	srv := fake(t)
	m := srv.SeedModel("Qwen/Qwen2.5-0.5B-Instruct")
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             modelServicesGone(srv, "chat"),
		Steps: []resource.TestStep{{
			Config: providerBlock(srv) + fmt.Sprintf(`
resource "runr_model_service" "chat" {
  name      = "chat"
  model_id  = %q
  region_id = data.runr_region.eu.id
  mode      = "offline"
  device    = "gpu"
  engine    = "vllm"
  gpu = {
    count      = 2
    memory_min = 16000
  }
  args = ["--max-model-len", "8192"]
}
`, m.ID),
			Check: resource.ComposeAggregateTestCheckFunc(
				resource.TestCheckResourceAttr("runr_model_service.chat", "status", runr.ModelServiceReady),
				resource.TestCheckResourceAttr("runr_model_service.chat", "model_id", m.ID),
				resource.TestCheckResourceAttr("runr_model_service.chat", "gpu.count", "2"),
				resource.TestCheckResourceAttr("runr_model_service.chat", "gpu.memory_min", "16000"),
				resource.TestCheckResourceAttr("runr_model_service.chat", "args.#", "2"),
				resource.TestCheckResourceAttr("runr_model_service.chat", "api", "openai"),
				check(func() error {
					got, _ := srv.AIModel(m.Name)
					for _, s := range got.Snapshots {
						if s.Status == runr.SnapshotReady {
							return nil
						}
					}
					return fmt.Errorf("the offline service has no ready snapshot: %+v", got.Snapshots)
				}),
			),
		}},
	})
}

func TestModelServiceResourceErrors(t *testing.T) {
	srv := fake(t)
	srv.Models().FailService = true
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: providerBlock(srv) + `
resource "runr_model_service" "both" {
  hub_id    = "sentence-transformers/all-MiniLM-L6-v2"
  model_id  = "01J00000000000000000000001"
  region_id = data.runr_region.eu.id
}
`,
				ExpectError: phrase("Set exactly one of hub_id"),
			},
			{
				Config: providerBlock(srv) + `
resource "runr_model_service" "cpu_gpu" {
  hub_id    = "sentence-transformers/all-MiniLM-L6-v2"
  region_id = data.runr_region.eu.id
  device    = "cpu"
  gpu       = { count = 1 }
}
`,
				ExpectError: phrase("gpu needs device gpu"),
			},
			{
				Config: providerBlock(srv) + `
resource "runr_model_service" "auto" {
  hub_id    = "sentence-transformers/all-MiniLM-L6-v2"
  region_id = data.runr_region.eu.id
  engine    = "auto"
}
`,
				ExpectError: regexp.MustCompile(`engine`),
			},
			{
				Config: providerBlock(srv) + `
resource "runr_model_service" "broken" {
  name      = "broken"
  hub_id    = "sentence-transformers/all-MiniLM-L6-v2"
  region_id = data.runr_region.eu.id
  timeout   = "1m"
}
`,
				ExpectError: phrase("did not become ready"),
			},
		},
	})
}
