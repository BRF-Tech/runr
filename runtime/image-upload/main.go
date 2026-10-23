// runr-image-upload is the region side of RunR's image uploads (docs/images.md) and of
// organization exports and imports (docs/org-export.md).
//
//	runr-image-upload [serve]          receiver of one upload: chunks at their offsets, then the
//	                                   verification and the push into the region registry
//	runr-image-upload transfer         transfer agent of an organization export / import: serves
//	                                   the files a tool container produced (or an image it pulls)
//	                                   for download, or receives files in chunks for the tool to
//	                                   restore (transfer.go)
//	runr-image-upload push-when-ready NAME
//	                                   next to a receiving transfer agent: pushes in/NAME into
//	                                   RUNR_UPLOAD_TARGET once it arrived (an image import,
//	                                   pushready.go)
//	runr-image-upload fetch REF OUT    writes the WebAssembly module of an OCI artifact to OUT
//	                                   (the wasm-exec build image uses it for uploaded modules)
//	runr-image-upload version
//
// RunR starts `serve` and `transfer` as Jobs in the region and moves every chunk the browser or
// the CLI sends (or the export downloads) through the Kubernetes API server's service proxy, so
// the region needs no route to RunR or the internet. Both are configured through the
// environment (config.go).
package main

import (
	"context"
	"fmt"
	"os"
	"os/signal"
	"syscall"
	"time"
)

// version is set at build time (-ldflags "-X main.version=…").
var version = "1.1.0"

func main() {
	os.Exit(run(os.Args[1:]))
}

func run(args []string) int {
	cmd := "serve"
	if len(args) > 0 {
		cmd, args = args[0], args[1:]
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	switch cmd {
	case "serve":
		cfg, err := configFromEnv(os.Getenv)
		if err != nil {
			fmt.Fprintln(os.Stderr, "runr-image-upload:", err)
			return 2
		}
		if err := serve(ctx, cfg); err != nil {
			fmt.Fprintln(os.Stderr, "runr-image-upload:", err)
			return 1
		}
		return 0
	case "transfer":
		cfg, err := transferConfigFromEnv(os.Getenv)
		if err != nil {
			fmt.Fprintln(os.Stderr, "runr-image-upload:", err)
			return 2
		}
		if err := serveTransfer(ctx, cfg); err != nil {
			fmt.Fprintln(os.Stderr, "runr-image-upload:", err)
			return 1
		}
		return 0
	case "push-when-ready":
		if len(args) != 1 {
			fmt.Fprintln(os.Stderr, "usage: runr-image-upload push-when-ready <file name in in/>")
			return 2
		}
		if err := pushWhenReady(ctx, args[0], os.Getenv, 2*time.Second); err != nil {
			fmt.Fprintln(os.Stderr, "runr-image-upload:", err)
			return 1
		}
		return 0
	case "fetch":
		if len(args) != 2 {
			fmt.Fprintln(os.Stderr, "usage: runr-image-upload fetch <reference> <out.wasm>")
			return 2
		}
		plain := os.Getenv("RUNR_REGISTRY_PLAIN_HTTP") != "false"
		digest, err := fetchModule(ctx, args[0], args[1], plain)
		if err != nil {
			fmt.Fprintln(os.Stderr, "runr-image-upload:", err)
			return 1
		}
		fmt.Printf("runr: module %s (%s) → %s\n", args[0], digest, args[1])
		return 0
	case "version", "--version", "-v":
		fmt.Println(version)
		return 0
	default:
		fmt.Fprintf(os.Stderr, "runr-image-upload: unknown command %q (serve | transfer | push-when-ready | fetch | version)\n", cmd)
		return 2
	}
}
