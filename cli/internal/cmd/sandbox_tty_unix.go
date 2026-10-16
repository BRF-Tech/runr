//go:build !windows

package cmd

import (
	"context"
	"os"
	"os/signal"
	"syscall"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// watchTerminalSizeOS reads the window's size at every SIGWINCH.
func watchTerminalSizeOS(ctx context.Context, f *os.File, sizes chan runr.TerminalSize) func() {
	if _, _, ok := terminalSize(f); !ok {
		return func() {}
	}
	winch := make(chan os.Signal, 1)
	signal.Notify(winch, syscall.SIGWINCH)
	done := make(chan struct{})
	go func() {
		for {
			select {
			case <-done:
				return
			case <-ctx.Done():
				return
			case <-winch:
				if w, h, ok := terminalSize(f); ok {
					sendLatestSize(sizes, runr.TerminalSize{Cols: w, Rows: h})
				}
			}
		}
	}()
	return func() {
		signal.Stop(winch)
		close(done)
	}
}

// enableTerminalOutput: a Unix terminal interprets escape sequences as they are.
func enableTerminalOutput(*os.File) func() { return func() {} }
