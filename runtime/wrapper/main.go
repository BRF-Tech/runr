// runr-wrapper is RunR's universal function wrapper (runtime contract v1). It lets a runtime in
// any language run on RunR: it receives the invocation — an HTTP request, a queue CloudEvent or a
// schedule tick — and runs the runtime's command (RUNR_COMMAND) for it.
//
// Wrapper modes (RUNR_WRAPPER_MODE):
//   - exec:       one process per invocation; the request envelope (or the raw body) on stdin,
//     the response on stdout, stderr to the logs.
//   - persistent: RUNR_WORKERS long-lived processes speaking line-delimited JSON over stdin/stdout,
//     so interpreted languages do not pay a process start per request.
//   - http:       the runtime serves HTTP itself on $PORT. For a schedule trigger the wrapper starts
//     the server, sends it one schedule CloudEvent and exits; otherwise it execs the server.
//
// Triggers (RUNR_TRIGGER): http and queue listen on $PORT (queue deliveries are CloudEvents POSTed
// by the queue consumer); schedule invokes once and exits 0 when the response status is < 400.
//
// Subcommands:
//
//	runr-wrapper [serve]          run according to the environment
//	runr-wrapper install <dest>   copy this binary to <dest> (init containers, image builds)
//	runr-wrapper tick             send one schedule CloudEvent to the server on $PORT (or
//	                              RUNR_TICK_URL) once it listens, print the response, exit 0 / 1
//	                              (schedule trigger of WebAssembly functions)
//	runr-wrapper version          print the wrapper version
package main

import (
	"fmt"
	"log"
	"os"
)

// version of the wrapper binary (the image tag ghcr.io/runrsh/runr-wrapper:<version>).
const version = "1.1.0"

var logger = log.New(os.Stderr, "[runr-wrapper] ", log.LstdFlags|log.Lmsgprefix)

func main() {
	args := os.Args[1:]
	if len(args) > 0 {
		switch args[0] {
		case "install":
			if len(args) != 2 {
				fmt.Fprintln(os.Stderr, "usage: runr-wrapper install <dest>")
				os.Exit(2)
			}
			if err := runInstall(args[1]); err != nil {
				logger.Printf("install: %v", err)
				os.Exit(1)
			}
			return
		case "version", "--version", "-v":
			fmt.Println(version)
			return
		case "tick":
			cfg, err := loadTickConfig(os.Getenv)
			if err != nil {
				logger.Printf("tick configuration: %v", err)
				os.Exit(2)
			}
			os.Exit(runTick(cfg))
		case "serve":
		default:
			fmt.Fprintf(os.Stderr, "unknown subcommand %q (serve, install, tick, version)\n", args[0])
			os.Exit(2)
		}
	}

	cfg, err := loadConfig(os.Getenv)
	if err != nil {
		logger.Printf("configuration: %v", err)
		os.Exit(2)
	}

	os.Exit(run(cfg))
}

// run executes the configured mode and returns the process exit code.
func run(cfg config) int {
	// Tracing / replay capture (observe.go): off unless RunR configured them for the project
	obs := setupObservability(cfg, os.Getenv)
	defer obs.shutdown()

	if cfg.Mode == modeHTTP {
		if cfg.Trigger == triggerSchedule {
			return obs.traceTick(cfg, runProxySchedule)
		}
		if obs.active() {
			return runObservedProxy(cfg, obs)
		}
		return execServer(cfg)
	}

	inv := obs.wrap(newInvoker(cfg))
	defer inv.Close()

	if cfg.Trigger == triggerSchedule {
		return runSchedule(cfg, inv)
	}

	return serve(cfg, inv)
}
