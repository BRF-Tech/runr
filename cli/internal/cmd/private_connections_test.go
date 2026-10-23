package cmd

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestPrivateConnectionsOfProjectAndEnvironment(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)
	h.srv.SeedEnvironment("orders", "staging", false)
	link := filepath.Join(t.TempDir(), "link.yaml")
	if err := os.WriteFile(link, []byte("apiVersion: skupper.io/v2alpha1\nkind: Link\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	// The project's own workload; the password never goes on the command line here
	r := h.runStdin("s3cret\n", "private-connections", "add", "orders-db", "-p", "orders", "--routing-key", "pg-orders",
		"--link", "@"+link, "--database", "orders", "--username", "app", "--password-stdin", "--env-prefix", "ORDERS_")
	if r.code != 0 {
		t.Fatalf("add: exit %d: %s", r.code, r.err)
	}
	contains(t, r.err, "Connected orders to orders-db")
	contains(t, r.out, "ORDERS_DATABASE_URL")
	body := h.srv.CallsTo("POST /projects/{project}/private-connections")[0].Body
	if body["password"] != "s3cret" || body["environment"] != nil || body["link_token"] == "" {
		t.Fatalf("create body = %v", body)
	}

	// --env: the connection of one environment, in the region it runs in
	r = h.runStdin("staging-pass\n", "pconn", "add", "orders-db", "-p", "orders", "--env", "staging", "--routing-key", "pg-orders-staging",
		"--link", "@"+link, "--database", "orders", "--username", "app", "--password-stdin")
	if r.code != 0 {
		t.Fatalf("add --env: exit %d: %s", r.code, r.err)
	}
	contains(t, r.err, "orders (staging)")
	if body := h.srv.CallsTo("POST /projects/{project}/private-connections")[1].Body; body["environment"] != "staging" {
		t.Fatalf("create body = %v", body)
	}

	r = h.mustRun("private-connections", "ls", "-p", "orders")
	contains(t, r.out, "pg-orders")
	notContains(t, r.out, "pg-orders-staging")
	r = h.mustRun("private-connections", "ls", "-p", "orders", "--env", "staging")
	contains(t, r.out, "pg-orders-staging")
	contains(t, r.out, "staging")

	r = h.mustRun("private-connections", "status", "orders-db", "-p", "orders", "--env", "staging")
	contains(t, r.out, "ready")

	// Removing one environment's connection leaves the project's own
	h.expectCode(ExitUsage, "private-connections", "rm", "orders-db", "-p", "orders", "--env", "staging")
	h.mustRun("private-connections", "rm", "orders-db", "-p", "orders", "--env", "staging", "--yes")
	if list := h.srv.PrivateConnections("orders"); len(list) != 1 || list[0].EnvironmentID != "" {
		t.Fatalf("connections after rm = %+v", list)
	}

	h.expectCode(ExitUsage, "private-connections", "add", "x", "-p", "orders")
	h.expectCode(ExitNotFound, "private-connections", "ls", "-p", "orders", "--env", "nope")
	h.expectCode(ExitNotFound, "private-connections", "rm", "ghost", "-p", "orders", "--yes")
}
