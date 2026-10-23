package runr_test

import (
	"context"
	"errors"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

// A store is created with the provider's settings, read back by ID, changed (settings merged,
// null removes one, refresh_interval null goes back to the installation's) and deleted once no
// secret variable refers to it.
func TestSecretStoreLifecycle(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()

	st, err := c.CreateSecretStore(ctx, runr.SecretStoreInput{Name: "vault-prod", Provider: "vault",
		Settings: map[string]string{"server": " https://vault.example.com:8200 ", "role": "runr", "mount": "kv"}})
	if err != nil {
		t.Fatal(err)
	}
	if st.Auth != "kubernetes" || st.RefreshInterval != 3600 || st.Settings["server"] != "https://vault.example.com:8200" ||
		st.KubernetesName != "runr-ss-"+strings.ToLower(st.ID) || st.Audience != "" || st.References != 0 {
		t.Fatalf("created = %+v", st)
	}
	call := srv.CallsTo("POST /secret-stores")[0]
	if _, sent := call.Body["refresh_interval"]; sent {
		t.Fatalf("an unset refresh interval was sent: %v", call.Body)
	}

	got, err := c.GetSecretStore(ctx, st.ID)
	if err != nil || got.Name != "vault-prod" || got.Settings["mount"] != "kv" {
		t.Fatalf("get = %+v, %v", got, err)
	}

	name, auth, interval := "vault-main", "jwt", 600
	upd, err := c.UpdateSecretStore(ctx, st.ID, runr.SecretStoreUpdate{Name: &name, Auth: &auth,
		Settings: map[string]*string{"mount": nil, "audience": storeSetting("runr")}, RefreshInterval: &interval})
	if err != nil {
		t.Fatal(err)
	}
	if upd.Name != "vault-main" || upd.Auth != "jwt" || upd.RefreshInterval != 600 || upd.Audience != "runr" {
		t.Fatalf("updated = %+v", upd)
	}
	if _, kept := upd.Settings["mount"]; kept || upd.Settings["role"] != "runr" {
		t.Fatalf("settings after the merge = %v", upd.Settings)
	}
	zero := 0
	if upd, err = c.UpdateSecretStore(ctx, st.ID, runr.SecretStoreUpdate{RefreshInterval: &zero}); err != nil || upd.RefreshInterval != 3600 {
		t.Fatalf("reset interval = %+v, %v", upd, err)
	}
	put := srv.CallsTo("PUT /secret-stores/{secretStore}")
	if body := put[len(put)-1].Body; len(body) != 1 || body["refresh_interval"] != nil {
		t.Fatalf("reset body = %v", body)
	}

	// Refused while a secret variable refers to it
	p := srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	if _, err := c.SetSecretReference(ctx, p.ID, "DB_PASSWORD", runr.SecretReferenceInput{StoreID: st.ID, Path: "apps/svc"}); err != nil {
		t.Fatal(err)
	}
	var ae *runr.APIError
	if err := c.DeleteSecretStore(ctx, st.ID); !errors.As(err, &ae) || ae.StatusCode != 409 || !strings.Contains(ae.Message, "svc") {
		t.Fatalf("delete while referred to: %v", err)
	}
	if err := c.DeleteSecret(ctx, p.ID, "DB_PASSWORD"); err != nil {
		t.Fatal(err)
	}
	if err := c.DeleteSecretStore(ctx, st.ID); err != nil {
		t.Fatal(err)
	}
	if _, err := c.GetSecretStore(ctx, st.ID); !runr.IsNotFound(err) {
		t.Fatalf("get after delete: %v", err)
	}
}

func TestSecretStoreValidation(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	cases := map[string]struct {
		in    runr.SecretStoreInput
		field string
	}{
		"bad name":         {runr.SecretStoreInput{Name: "Vault", Provider: "vault", Settings: map[string]string{"server": "https://v", "role": "r"}}, "name"},
		"unknown provider": {runr.SecretStoreInput{Name: "s", Provider: "openbao", Settings: map[string]string{}}, "provider"},
		"auth of another":  {runr.SecretStoreInput{Name: "s", Provider: "aws", Auth: "jwt", Settings: map[string]string{"region": "eu-central-1", "role_arn": "arn:aws:iam::123456789012:role/r"}}, "auth"},
		"missing setting":  {runr.SecretStoreInput{Name: "s", Provider: "vault", Settings: map[string]string{"server": "https://v"}}, "settings.role"},
		"credential":       {runr.SecretStoreInput{Name: "s", Provider: "vault", Settings: map[string]string{"server": "https://v", "role": "r", "token": "x"}}, "settings.token"},
		"role arn":         {runr.SecretStoreInput{Name: "s", Provider: "aws", Settings: map[string]string{"region": "eu-central-1", "role_arn": "runr"}}, "settings.role_arn"},
		"azure over http":  {runr.SecretStoreInput{Name: "s", Provider: "azure", Settings: map[string]string{"vault_url": "http://kv", "tenant_id": "t", "client_id": "c"}}, "settings.vault_url"},
		"short interval":   {runr.SecretStoreInput{Name: "s", Provider: "gcp", RefreshInterval: 30, Settings: map[string]string{"project_id": "p", "audience": "a"}}, "refresh_interval"},
		"private key":      {runr.SecretStoreInput{Name: "s", Provider: "vault", Settings: map[string]string{"server": "https://v", "role": "r", "ca_bundle": "-----BEGIN PRIVATE KEY-----"}}, "settings.ca_bundle"},
	}
	for name, tc := range cases {
		var ae *runr.APIError
		if _, err := c.CreateSecretStore(ctx, tc.in); !errors.As(err, &ae) || ae.StatusCode != 422 || ae.Errors[tc.field] == nil {
			t.Errorf("%s: %v", name, err)
		}
	}
	if _, err := c.CreateSecretStore(ctx, runr.SecretStoreInput{Name: "aws-eu", Provider: "aws",
		Settings: map[string]string{"region": "eu-central-1", "role_arn": "arn:aws:iam::123456789012:role/runr"}}); err != nil {
		t.Fatal(err)
	}
	st, _ := c.FindSecretStore(ctx, "aws-eu")
	if st.Auth != "workload_identity" || st.Audience != "sts.amazonaws.com" {
		t.Fatalf("aws store = %+v", st)
	}
	if _, err := c.CreateSecretStore(ctx, runr.SecretStoreInput{Name: "aws-eu", Provider: "aws",
		Settings: map[string]string{"region": "eu-central-1", "role_arn": "arn:aws:iam::123456789012:role/runr"}}); !runr.IsValidation(err) {
		t.Fatalf("duplicate name: %v", err)
	}
}

func storeSetting(s string) *string { return &s }
