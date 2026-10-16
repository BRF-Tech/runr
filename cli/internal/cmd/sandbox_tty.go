package cmd

import (
	"context"
	"io"
	"os"
	"os/signal"
	"sync"
	"syscall"

	"golang.org/x/term"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// `runr sandbox exec -t`: a command on a terminal over the exec WebSocket.

// maxTerminalSize is the API's largest terminal width and height.
const maxTerminalSize = 1000

// watchTerminalSize sends the size of the terminal f to sizes whenever the window changes (SIGWINCH
// on Unix, polling the console on Windows) until stop is called or ctx ends. Tests replace it.
var watchTerminalSize = watchTerminalSizeOS

// sandboxExecTTY runs a command on a terminal of the window's size. With -i on a terminal, runr's
// terminal is raw while the command runs — every key, Ctrl+C included, goes to the command as
// typed — and restored on every way out: the command's end, an error, Ctrl+C's SIGINT when stdin
// is no terminal, SIGTERM and SIGHUP.
func sandboxExecTTY(ctx context.Context, app *App, id string, in runr.SandboxExecInput, stdin bool) error {
	c, err := app.Client()
	if err != nil {
		return err
	}
	inFile, _ := app.In.(*os.File)
	outFile, _ := app.Out.(*os.File)

	in.TTY = true
	if w, h, ok := terminalSize(outFile); ok {
		in.Cols, in.Rows = w, h
	}

	// A signal that ends runr ends the session first, so the terminal is restored.
	ctx, stopSignals := signal.NotifyContext(ctx, syscall.SIGTERM, syscall.SIGHUP)
	defer stopSignals()

	var input io.Reader
	if stdin {
		input = app.In
	}
	restore := func() {}
	if stdin && inFile != nil && app.interactive() && term.IsTerminal(int(inFile.Fd())) {
		if old, err := term.MakeRaw(int(inFile.Fd())); err == nil {
			restore = sync.OnceFunc(func() { _ = term.Restore(int(inFile.Fd()), old) })
		}
	}
	defer restore()
	defer enableTerminalOutput(outFile)()

	sizes := make(chan runr.TerminalSize, 1)
	defer watchTerminalSize(ctx, outFile, sizes)()

	res, err := c.StreamSandboxExecWS(ctx, id, in, runr.SandboxStream{Stdout: app.Out, Stderr: app.Err, Resize: sizes}, input)
	restore()
	if err == nil && res != nil && !res.TTY {
		app.Warn("the API ran the command without a terminal: it predates terminals for exec")
	}
	return sandboxStreamEnd(ctx, app, res, err)
}

// terminalSize is the size of the terminal f, within the API's limits.
func terminalSize(f *os.File) (cols, rows int, ok bool) {
	if f == nil || !term.IsTerminal(int(f.Fd())) {
		return 0, 0, false
	}
	w, h, err := term.GetSize(int(f.Fd()))
	if err != nil || w <= 0 || h <= 0 {
		return 0, 0, false
	}
	return min(w, maxTerminalSize), min(h, maxTerminalSize), true
}

// sendLatestSize puts s into sizes (capacity 1), replacing a size not sent yet.
func sendLatestSize(sizes chan runr.TerminalSize, s runr.TerminalSize) {
	for {
		select {
		case sizes <- s:
			return
		default:
		}
		select {
		case <-sizes:
		default:
		}
	}
}
