//go:build windows

package cmd

import (
	"context"
	"os"
	"time"

	"golang.org/x/sys/windows"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// consoleSizePoll is how often the console's size is read (Windows has no SIGWINCH).
const consoleSizePoll = 250 * time.Millisecond

// watchTerminalSizeOS polls the console's size.
func watchTerminalSizeOS(ctx context.Context, f *os.File, sizes chan runr.TerminalSize) func() {
	w0, h0, ok := terminalSize(f)
	if !ok {
		return func() {}
	}
	done := make(chan struct{})
	go func() {
		tick := time.NewTicker(consoleSizePoll)
		defer tick.Stop()
		for {
			select {
			case <-done:
				return
			case <-ctx.Done():
				return
			case <-tick.C:
				if w, h, ok := terminalSize(f); ok && (w != w0 || h != h0) {
					w0, h0 = w, h
					sendLatestSize(sizes, runr.TerminalSize{Cols: w, Rows: h})
				}
			}
		}
	}()
	return func() { close(done) }
}

// enableTerminalOutput turns on the console's processing of escape sequences (colors, cursor
// movement) the command's terminal output carries, and returns the function that restores the
// console mode.
func enableTerminalOutput(f *os.File) func() {
	if f == nil {
		return func() {}
	}
	h := windows.Handle(f.Fd())
	var mode uint32
	if windows.GetConsoleMode(h, &mode) != nil || mode&windows.ENABLE_VIRTUAL_TERMINAL_PROCESSING != 0 {
		return func() {}
	}
	if windows.SetConsoleMode(h, mode|windows.ENABLE_VIRTUAL_TERMINAL_PROCESSING) != nil {
		return func() {}
	}
	return func() { _ = windows.SetConsoleMode(h, mode) }
}
