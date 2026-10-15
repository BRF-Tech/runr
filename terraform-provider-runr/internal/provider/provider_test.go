package provider

import (
	"fmt"
	"path/filepath"
	"regexp"
	"strings"
	"testing"

	"github.com/hashicorp/terraform-plugin-framework/providerserver"
	"github.com/hashicorp/terraform-plugin-go/tfprotov6"
	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/brf-tech/runr/cli/pkg/config"
	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

// The tests are acceptance tests in the terraform-plugin-testing sense (real terraform plan /
// apply / import / destroy against the provider), run with resource.UnitTest against the
// in-memory fake API (runrtest), so they need the terraform CLI but no RunR installation.
// terraform-plugin-testing uses TF_ACC_TERRAFORM_PATH, else terraform on PATH, else downloads it.

func providerFactories() map[string]func() (tfprotov6.ProviderServer, error) {
	return map[string]func() (tfprotov6.ProviderServer, error){
		"runr": providerserver.NewProtocol6WithError(New("test")()),
	}
}

// isolate keeps the developer's environment and runr CLI contexts out of the tests.
func isolate(t *testing.T) {
	t.Helper()
	t.Setenv(config.EnvAPIURL, "")
	t.Setenv(config.EnvToken, "")
	t.Setenv(config.EnvContext, "")
	t.Setenv(config.EnvConfig, filepath.Join(t.TempDir(), "absent.yaml"))
}

func fake(t *testing.T) *runrtest.Server {
	t.Helper()
	isolate(t)
	return runrtest.New(t)
}

func providerBlock(srv *runrtest.Server) string {
	return fmt.Sprintf(`
provider "runr" {
  api_url       = %q
  token         = %q
  poll_interval = "5ms"
}

data "runr_region" "eu" {
  name = "eu-1"
}
`, srv.URL, runrtest.DefaultToken)
}

// client returns an API client of the fake (changes made "outside Terraform").
func client(t *testing.T, srv *runrtest.Server) *runr.Client {
	t.Helper()
	c, err := runr.New(runr.Options{APIURL: srv.URL, Token: runrtest.DefaultToken})
	if err != nil {
		t.Fatal(err)
	}
	return c
}

// phrase matches words separated by any whitespace (terraform wraps diagnostics).
func phrase(s string) *regexp.Regexp {
	parts := strings.Fields(s)
	for i, p := range parts {
		parts[i] = regexp.QuoteMeta(p)
	}
	return regexp.MustCompile(strings.Join(parts, `\s+`))
}

func projectGone(srv *runrtest.Server, names ...string) resource.TestCheckFunc {
	return func(*terraform.State) error {
		for _, n := range names {
			if _, ok := srv.Project(n); ok {
				return fmt.Errorf("project %s still exists", n)
			}
		}
		return nil
	}
}

func TestProviderNeedsCredentials(t *testing.T) {
	isolate(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config:      `data "runr_region" "any" {}`,
			ExpectError: regexp.MustCompile(`RunR\s+API\s+(URL|token)\s+missing`),
		}},
	})
}

func TestProviderUsesEnvironment(t *testing.T) {
	srv := fake(t)
	t.Setenv(config.EnvAPIURL, srv.URL)
	t.Setenv(config.EnvToken, runrtest.DefaultToken)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config: `data "runr_region" "any" {}`,
			Check:  resource.TestCheckResourceAttr("data.runr_region.any", "name", "eu-1"),
		}},
	})
}

func TestProviderUsesCLIContext(t *testing.T) {
	srv := fake(t)
	cfgPath := filepath.Join(t.TempDir(), "config.yaml")
	t.Setenv(config.EnvConfig, cfgPath)
	f, _ := config.LoadFrom(cfgPath)
	f.Set("dev", &config.Context{APIURL: srv.URL, Token: runrtest.DefaultToken})
	if err := f.Save(); err != nil {
		t.Fatal(err)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config: `
provider "runr" {
  context = "dev"
}
data "runr_region" "any" {}`,
			Check: resource.TestCheckResourceAttr("data.runr_region.any", "is_active", "true"),
		}},
	})
}

func TestProviderRejectsBadPollInterval(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config: fmt.Sprintf(`
provider "runr" {
  api_url       = %q
  token         = %q
  poll_interval = "often"
}
data "runr_region" "any" {}`, srv.URL, runrtest.DefaultToken),
			ExpectError: phrase("Invalid poll_interval"),
		}},
	})
}
