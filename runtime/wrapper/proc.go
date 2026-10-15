package main

import (
	"context"
	"os"
	"os/exec"
	"syscall"
	"time"
)

// pipeGrace is how long Wait keeps reading the output pipes after the process exited (or was
// killed) — a forked background child that keeps them open does not hold the invocation.
const pipeGrace = 2 * time.Second

// invoker runs one invocation of the runtime command.
type invoker interface {
	Invoke(ctx context.Context, env *Envelope, rawBody []byte) *Response
	Close()
}

func newInvoker(cfg config) invoker {
	if cfg.Mode == modePersistent {
		return newPool(cfg)
	}
	return &execInvoker{cfg: cfg}
}

// command returns the runtime command, started in its own process group so a timeout or a
// shutdown reaches everything it spawned. With a context, cancelling it kills the group.
func command(ctx context.Context, cfg config, extraEnv ...string) *exec.Cmd {
	var cmd *exec.Cmd
	if ctx != nil {
		cmd = exec.CommandContext(ctx, cfg.Command[0], cfg.Command[1:]...)
		cmd.Cancel = func() error { return signalGroup(cmd, syscall.SIGKILL) }
	} else {
		cmd = exec.Command(cfg.Command[0], cfg.Command[1:]...)
	}

	cmd.Dir = cfg.Dir
	cmd.Env = append(append(os.Environ(), cfg.Env...), extraEnv...)
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	cmd.WaitDelay = pipeGrace

	return cmd
}

// signalGroup sends sig to the process group of a started command.
func signalGroup(cmd *exec.Cmd, sig syscall.Signal) error {
	if cmd.Process == nil {
		return nil
	}
	err := syscall.Kill(-cmd.Process.Pid, sig)
	if err == syscall.ESRCH {
		return nil
	}
	return err
}

// exitCode of a finished command: the exit status, 128+signal when it was killed by a signal.
func exitCode(state *os.ProcessState) int {
	if state == nil {
		return -1
	}
	if ws, ok := state.Sys().(syscall.WaitStatus); ok && ws.Signaled() {
		return 128 + int(ws.Signal())
	}
	return state.ExitCode()
}

// waiter tracks a started command: done is closed once Wait returned.
type waiter struct {
	cmd  *exec.Cmd
	done chan struct{}
	err  error
}

func startWaiter(cmd *exec.Cmd) (*waiter, error) {
	if err := cmd.Start(); err != nil {
		return nil, err
	}
	w := &waiter{cmd: cmd, done: make(chan struct{})}
	go func() {
		w.err = cmd.Wait()
		close(w.done)
	}()
	return w, nil
}

// exited reports whether the command finished.
func (w *waiter) exited() bool {
	select {
	case <-w.done:
		return true
	default:
		return false
	}
}

// stop terminates the process group: SIGTERM, SIGKILL after grace.
func (w *waiter) stop(grace time.Duration) {
	if w.exited() {
		return
	}
	_ = signalGroup(w.cmd, syscall.SIGTERM)
	select {
	case <-w.done:
	case <-time.After(grace):
		_ = signalGroup(w.cmd, syscall.SIGKILL)
		<-w.done
	}
}

// kill kills the process group at once.
func (w *waiter) kill() {
	_ = signalGroup(w.cmd, syscall.SIGKILL)
}
