package cmd

import (
	"errors"
	"fmt"
	"sort"
	"strconv"
	"strings"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// The organization's secret stores (docs/secret-stores.md): runr secret-stores ls|trust. A store
// holds settings only; it trusts the region's identity, so there is nothing secret to print.

func init() { registerCommand(newSecretStoresCmd) }

func newSecretStoresCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "secret-stores",
		Aliases: []string{"secret-store"},
		Short:   "Secret stores of the organization (Vault / OpenBao, AWS, GCP, Azure)",
		Long: "A project secret can refer to a value in one of the organization's secret stores\n" +
			"(`runr env ref`). External Secrets Operator in the project's region reads it with the\n" +
			"region's identity: a store never gets a credential from RunR, it trusts the region\n" +
			"cluster's service account issuer (`runr secret-stores trust`). Stores are registered in\n" +
			"the console (Settings → Secret stores) or through the API (secret_stores.manage).",
	}
	ls := newSecretStoresLsCmd(app)
	cmd.AddCommand(ls, newSecretStoresTrustCmd(app))
	cmd.RunE = ls.RunE
	return cmd
}

func newSecretStoresLsCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the secret stores",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			stores, err := c.ListSecretStores(cmd.Context())
			if errors.Is(err, runr.ErrUnsupported) {
				return fmt.Errorf("this RunR API has no secret stores")
			}
			if err != nil {
				return err
			}
			return app.Printer().Print(stores, func(t *output.TableWriter) {
				t.Header("NAME", "PROVIDER", "AUTH", "REFERENCES", "REGIONS", "ID")
				for _, s := range stores {
					t.Row(s.Name, s.Provider, s.Auth, strconv.Itoa(s.References), storeRegions(s), s.ID)
				}
				if len(stores) == 0 {
					t.Note("No secret stores yet: register one in Settings → Secret stores.")
				}
			})
		},
	}
}

// storeRegions is "eu-1 ready, us-2 failed" (region IDs: the list carries no names).
func storeRegions(s runr.SecretStore) string {
	if len(s.Regions) == 0 {
		return "-"
	}
	ids := make([]string, 0, len(s.Regions))
	for id := range s.Regions {
		ids = append(ids, id)
	}
	sort.Strings(ids)
	parts := make([]string, 0, len(ids))
	for _, id := range ids {
		parts = append(parts, id+" "+s.Regions[id].Status)
	}
	return strings.Join(parts, ", ")
}

func newSecretStoresTrustCmd(app *App) *cobra.Command {
	var region string
	c := &cobra.Command{
		Use:   "trust STORE --region REGION",
		Short: "What a store has to trust so a region reads from it",
		Long: "Prints the region cluster's service account issuer (and whether a cloud provider\n" +
			"could reach it), its public keys as PEM, and the service account a store logs in with:\n" +
			"its namespace, subject and token audience. Configure them at the store — a Vault /\n" +
			"OpenBao role, an AWS IAM OIDC provider and role, a GCP workload identity pool provider\n" +
			"or an Azure federated credential (docs/secret-stores.md). `-o json` adds the JWKS.",
		Example: "  runr secret-stores trust vault-prod --region eu-1\n" +
			"  runr secret-stores trust vault-prod --region eu-1 -o json | jq -r '.pem_keys[]' > keys.pem",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			store, err := c.FindSecretStore(ctx, args[0])
			if err != nil {
				return err
			}
			r, err := c.FindRegion(ctx, region)
			if err != nil {
				return err
			}
			trust, err := c.SecretStoreTrust(ctx, store.ID, r.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(trust, func(t *output.TableWriter) {
				t.KV("Store", store.Name+" ("+store.Provider+", "+store.Auth+")")
				t.KV("Region", output.Dash(trust.Region.Name))
				t.KV("Issuer", trust.Issuer)
				t.KV("Publicly reachable", yesNo(trust.PubliclyReachable))
				t.KV("JWKS URI", output.Dash(trust.JWKSURI))
				t.KV("Namespace", trust.Namespace)
				t.KV("Service account", trust.ServiceAccount)
				t.KV("Subject", trust.Subject)
				t.KV("Audience", output.Dash(trust.Audience))
				t.KV("External Secrets", output.Dash(trust.ESOStatus))
				if !trust.PubliclyReachable {
					t.Note("")
					t.Note("The issuer is not reachable from outside the cluster: AWS and Azure cannot use it as it is;")
					t.Note("GCP takes the keys uploaded, Vault / OpenBao JWT auth takes them as jwt_validation_pubkeys.")
				}
				if len(trust.PEMKeys) > 0 {
					t.Note("")
					t.Note("Public keys of the issuer (PEM):")
					for _, k := range trust.PEMKeys {
						for _, line := range strings.Split(k, "\n") {
							t.Note("  %s", line)
						}
					}
				}
			})
		},
	}
	c.Flags().StringVar(&region, "region", "", "region name or id (default: the only active region)")
	return c
}
