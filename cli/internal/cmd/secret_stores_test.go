package cmd

import (
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// runr env ref: a secret that refers to a value in a secret store — no value is sent, the live
// version is redeployed so the ExternalSecret is applied, and the listing shows the reference.
func TestEnvRefSetsAReference(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	storeID := h.srv.SeedSecretStore("vault-prod", "vault", "kubernetes")
	deploysBefore := len(h.srv.Deployments("hello-api"))
	h.srv.ResetCalls()

	r := h.mustRun("env", "ref", "DB_PASSWORD", "vault-prod:apps/hello#db", "--dir", dir)
	contains(t, r.err, "DB_PASSWORD refers to vault-prod:apps/hello#db")
	puts := h.srv.CallsTo("PUT /projects/{project}/secrets/{name}")
	if len(puts) != 1 {
		t.Fatalf("secret calls = %+v", puts)
	}
	if got := h.srv.SecretReferences("hello-api")["DB_PASSWORD"]; got.StoreID != storeID || got.Path != "apps/hello" || got.Key != "db" {
		t.Fatalf("reference = %+v", got)
	}
	if deps := h.srv.Deployments("hello-api"); len(deps) != deploysBefore+1 {
		t.Fatalf("the live version was not redeployed: %d deployments", len(deps))
	}

	r = h.mustRun("env", "ls", "--dir", dir)
	contains(t, r.out, "vault-prod:apps/hello#db (pending)")
	contains(t, r.out, "(in the store)")

	var vars []runr.EnvVar
	decodeJSON(t, h.mustRun("env", "ls", "--dir", dir, "-o", "json").out, &vars)
	found := false
	for _, v := range vars {
		if v.Name == "DB_PASSWORD" {
			found = v.Secret && v.Source == runr.SecretSourceExternal && v.Reference != nil && v.Sync != nil
		}
	}
	if !found {
		t.Fatalf("env ls = %+v", vars)
	}

	// --reveal never reads a reference back: its value lives in the store
	before := h.srv.SecretReveals()
	h.mustRun("env", "ls", "--reveal", "--dir", dir)
	if h.srv.SecretReveals() != before {
		t.Fatal("a reference was read back")
	}
}

func TestEnvRefRejectsBadInput(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.SeedSecretStore("vault-prod", "vault", "kubernetes")

	r := h.expectCode(ExitUsage, "env", "ref", "DB_PASSWORD", "no-colon", "--dir", dir)
	contains(t, r.err, "STORE:PATH")
	h.expectCode(ExitUsage, "env", "ref", "1BAD", "vault-prod:x", "--dir", dir)
	r = h.run("env", "ref", "DB_PASSWORD", "unknown:x", "--no-deploy", "--dir", dir)
	if r.code == 0 {
		t.Fatal("an unknown store was accepted")
	}
	contains(t, r.err, "vault-prod")
}

func TestEnvSyncShowsReferences(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.SeedSecretStore("aws-eu", "aws", "workload_identity")
	h.mustRun("env", "ref", "STRIPE_KEY", "aws-eu:payments/stripe#key", "--no-deploy", "--dir", dir)
	h.srv.SeedSecret("hello-api", "TOKEN", "placeholder-value")

	r := h.mustRun("env", "sync", "--dir", dir)
	contains(t, r.out, "STRIPE_KEY")
	contains(t, r.out, "aws-eu:payments/stripe#key")
	notContains(t, r.out, "TOKEN")
	if h.srv.SecretRefreshes() != 1 {
		t.Fatalf("%d refreshes", h.srv.SecretRefreshes())
	}
}

func TestSecretStoresLsAndTrust(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedSecretStore("vault-prod", "vault", "jwt")

	r := h.mustRun("secret-stores", "ls")
	contains(t, r.out, "vault-prod")
	contains(t, r.out, "jwt")

	r = h.mustRun("secret-stores", "trust", "vault-prod", "--region", "eu-1")
	contains(t, r.out, "Issuer")
	contains(t, r.out, "system:serviceaccount:")
	contains(t, r.out, "BEGIN PUBLIC KEY")
	contains(t, r.out, "not reachable from outside the cluster")

	var trust runr.SecretStoreTrust
	decodeJSON(t, h.mustRun("secret-stores", "trust", "vault-prod", "--region", "eu-1", "-o", "json").out, &trust)
	if trust.ServiceAccount == "" || trust.Namespace == "" || len(trust.PEMKeys) == 0 {
		t.Fatalf("trust = %+v", trust)
	}

	h.loginWith(h.srv.ScopedToken("dev", "env.view"))
	r = h.expectCode(ExitAuth, "secret-stores", "ls")
	contains(t, r.err, "secret_stores.view")
}
