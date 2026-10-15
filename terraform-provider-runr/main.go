// Command terraform-provider-runr is the Terraform provider of RunR.
package main

import (
	"context"
	"flag"
	"log"

	"github.com/hashicorp/terraform-plugin-framework/providerserver"

	"github.com/runrsh/core/terraform-provider-runr/internal/provider"
)

// Generate the registry documentation from the schema and examples/.
//go:generate go run github.com/hashicorp/terraform-plugin-docs/cmd/tfplugindocs@v0.25.0 generate --provider-name runr

// version is set by goreleaser.
var version = "dev"

func main() {
	var debug bool
	flag.BoolVar(&debug, "debug", false, "run the provider with support for debuggers like delve")
	flag.Parse()

	err := providerserver.Serve(context.Background(), provider.New(version), providerserver.ServeOpts{
		Address: "registry.terraform.io/runrsh/runr",
		Debug:   debug,
	})
	if err != nil {
		log.Fatal(err)
	}
}
