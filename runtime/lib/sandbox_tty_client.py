"""`runr sandbox exec -it` end to end (ci.sh test-sandbox-api, lib/test-sandbox-api.sh).

Runs the runr CLI on a pseudo-terminal against the e2e stack, after the Sandbox API suite (gVisor
installed, runc allowed on the region):

  cli-sandbox-create   runr sandbox create (python, gvisor) prints the sandbox id
  cli-exec-tty         runr sandbox exec -it on a 100x30 terminal: the command runs on a terminal
                       of that size, runr's terminal is raw while it runs, a window change reaches
                       the command (120x40), typed lines are read, runr exits with the command's
                       exit code (7) and the terminal is restored afterwards
  cli-exec-tty-ctrl-c  Ctrl+C typed on runr's terminal goes to the command (its INT trap runs,
                       exit 5) instead of interrupting runr
  cli-exec-t-pipe      -t without a terminal (output to a pipe): the command still gets a terminal
  cli-sandbox-rm       runr sandbox rm

The client shares the network namespace of the compose `api` container. The CLI reaches the API
and the exec WebSocket at one address, as behind the chart's gateway: a small proxy here sends
/ws/* to Reverb (prefix stripped, as the console's dev server does) and everything else to the
API on :80.

Usage: python sandbox_tty_client.py --base http://localhost --admin-user U --admin-password P
       [--reverb runr-reverb:8080] [--runr /usr/local/bin/runr]
"""

import argparse
import fcntl
import json
import os
import select
import socket
import struct
import subprocess
import sys
import termios
import threading
import time
import urllib.request

RESULTS = []


def report(name, ok, why=""):
    RESULTS.append(ok)
    print(("  ✓ " if ok else "  ✗ ") + name + ("" if ok else ": " + why), flush=True)


# ── One address for /api and /ws (the chart's gateway) ──


def _pipe(src, dst):
    try:
        while True:
            data = src.recv(65536)
            if not data:
                break
            dst.sendall(data)
    except OSError:
        pass
    finally:
        for s in (src, dst):
            try:
                s.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass


def _serve(conn, api, reverb):
    head = b""
    while b"\r\n\r\n" not in head:
        chunk = conn.recv(65536)
        if not chunk:
            conn.close()
            return
        head += chunk
    line, rest = head.split(b"\r\n", 1)
    method, path, version = line.split(b" ", 2)
    target = api
    if path.startswith(b"/ws/"):
        target, path = reverb, path[3:]
    try:
        upstream = socket.create_connection(target, timeout=10)
    except OSError as e:
        print("  gateway: %s:%d unreachable (%s)" % (target[0], target[1], e), flush=True)
        conn.close()
        return
    upstream.settimeout(None)
    upstream.sendall(b" ".join([method, path, version]) + b"\r\n" + rest)
    threading.Thread(target=_pipe, args=(upstream, conn), daemon=True).start()
    _pipe(conn, upstream)


def start_gateway(api, reverb):
    srv = socket.socket()
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind(("127.0.0.1", 0))
    srv.listen(16)

    def accept():
        while True:
            conn, _ = srv.accept()
            threading.Thread(target=_serve, args=(conn, api, reverb), daemon=True).start()

    threading.Thread(target=accept, daemon=True).start()
    return "http://127.0.0.1:%d" % srv.getsockname()[1]


# ── runr on a pseudo-terminal ──


class Pty:
    def __init__(self, argv, env, rows, cols):
        self.master, self.slave = os.openpty()
        self.resize(rows, cols)
        self.buf = b""
        self.proc = subprocess.Popen(
            argv, stdin=self.slave, stdout=self.slave, stderr=self.slave, env=env,
            start_new_session=True, close_fds=True,
            # the terminal becomes runr's controlling terminal: a window change sends it SIGWINCH
            preexec_fn=lambda: fcntl.ioctl(0, termios.TIOCSCTTY, 0),
        )

    def resize(self, rows, cols):
        fcntl.ioctl(self.slave, termios.TIOCSWINSZ, struct.pack("HHHH", rows, cols, 0, 0))

    def lflag(self):
        return termios.tcgetattr(self.slave)[3]

    def _read(self, timeout):
        r, _, _ = select.select([self.master], [], [], timeout)
        if not r:
            return False
        try:
            data = os.read(self.master, 65536)
        except OSError:
            return False
        self.buf += data
        return bool(data)

    def expect(self, needle, timeout):
        deadline = time.time() + timeout
        while needle not in self.buf and time.time() < deadline:
            if not self._read(0.2) and self.proc.poll() is not None:
                break
        return needle in self.buf

    def write(self, data):
        os.write(self.master, data)

    def wait(self, timeout):
        deadline = time.time() + timeout
        while self.proc.poll() is None and time.time() < deadline:
            self._read(0.2)
        while self._read(0.1):
            pass
        if self.proc.poll() is None:
            self.proc.kill()
            return None
        return self.proc.returncode

    def text(self):
        return self.buf.decode("utf-8", "replace").replace("\r", "")[-600:]

    def close(self):
        if self.proc.poll() is None:
            self.proc.kill()
        for fd in (self.master, self.slave):
            try:
                os.close(fd)
            except OSError:
                pass


def login(base, user, password):
    req = urllib.request.Request(
        base + "/api/auth/login", data=json.dumps({"login": user, "password": password}).encode(),
        headers={"Content-Type": "application/json", "Accept": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.load(resp)["token"]


def check_tty(runr, env, sbx):
    script = '[ -t 0 ] && [ -t 1 ] && echo tty-yes; stty size; read a; stty size; read b; echo "got:$b"; exit 7'
    p = Pty([runr, "sandbox", "exec", "-it", sbx, "--", "/bin/sh", "-c", script], env, 30, 100)
    try:
        if not p.expect(b"tty-yes", 90):
            return "the command did not run on a terminal: " + p.text()
        if not p.expect(b"30 100", 30):
            return "terminal size is not 30 rows x 100 columns: " + p.text()
        raw = not (p.lflag() & (termios.ICANON | termios.ECHO | termios.ISIG))
        p.resize(40, 120)
        time.sleep(1.5)
        p.write(b"next\r")
        if not p.expect(b"40 120", 30):
            return "the window change did not reach the command: " + p.text()
        p.write(b"hello\r")
        if not p.expect(b"got:hello", 30):
            return "the typed line was not read: " + p.text()
        code = p.wait(30)
        restored = bool(p.lflag() & termios.ICANON) and bool(p.lflag() & termios.ECHO)
        if code != 7:
            return "runr exited %s, expected the command's 7: %s" % (code, p.text())
        if not raw:
            return "runr's terminal was not raw while the command ran"
        if not restored:
            return "runr's terminal was not restored after the command"
        return True
    finally:
        p.close()


def check_ctrl_c(runr, env, sbx):
    script = 'trap "echo got-sigint; exit 5" INT; echo ready; while true; do sleep 1; done'
    p = Pty([runr, "sandbox", "exec", "-it", sbx, "--", "/bin/sh", "-c", script], env, 24, 80)
    try:
        if not p.expect(b"ready", 90):
            return "the command did not start: " + p.text()
        time.sleep(1)
        p.write(b"\x03")
        if not p.expect(b"got-sigint", 20):
            return "Ctrl+C did not reach the command: " + p.text()
        code = p.wait(30)
        return True if code == 5 else "runr exited %s, expected the command's 5: %s" % (code, p.text())
    finally:
        p.close()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://localhost")
    ap.add_argument("--admin-user", default="admin")
    ap.add_argument("--admin-password", default="admin123")
    ap.add_argument("--reverb", default="runr-reverb:8080")
    ap.add_argument("--runr", default="/usr/local/bin/runr")
    args = ap.parse_args()

    host, port = args.reverb.rsplit(":", 1)
    api_host = args.base.split("://", 1)[1].split("/", 1)[0]
    api = (api_host.split(":")[0], int(api_host.split(":")[1]) if ":" in api_host else 80)
    url = start_gateway(api, (host, int(port)))

    token = login(args.base, args.admin_user, args.admin_password)
    env = dict(os.environ, RUNR_API_URL=url, RUNR_TOKEN=token, TERM="xterm-256color", HOME="/tmp")
    runr = args.runr
    version = subprocess.run([runr, "version"], env=env, capture_output=True, text=True).stdout.strip()
    print("  %s; API and exec WebSocket through %s" % ((version.splitlines() or ["runr"])[0], url), flush=True)

    created = subprocess.run([runr, "sandbox", "create", "--runtime", "python", "--class", "gvisor", "--ttl", "15m"],
                             env=env, capture_output=True, text=True, timeout=300)
    sbx = created.stdout.strip()
    if created.returncode != 0 or not sbx:
        report("cli-sandbox-create", False, "exit %d: %s" % (created.returncode, (created.stderr or created.stdout)[-400:]))
        return 1
    report("cli-sandbox-create", True)

    try:
        for name, check in (("cli-exec-tty", check_tty), ("cli-exec-tty-ctrl-c", check_ctrl_c)):
            try:
                result = check(runr, env, sbx)
            except Exception as e:  # noqa: BLE001 — a check that breaks is a failed check
                result = "%s: %s" % (type(e).__name__, e)
            report(name, result is True, "" if result is True else str(result))

        piped = subprocess.run([runr, "sandbox", "exec", "-t", sbx, "--", "/bin/sh", "-c", "[ -t 1 ] && echo tty-out; exit 3"],
                               env=env, stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=120)
        report("cli-exec-t-pipe", piped.returncode == 3 and "tty-out" in piped.stdout,
               "exit %d, stdout %r, stderr %r" % (piped.returncode, piped.stdout[-200:], piped.stderr[-200:]))
    finally:
        # --yes: no terminal here to confirm on
        removed = subprocess.run([runr, "sandbox", "rm", "--yes", sbx], env=env, capture_output=True, text=True, timeout=120)
        report("cli-sandbox-rm", removed.returncode == 0, (removed.stderr or removed.stdout)[-300:])

    return 0 if all(RESULTS) else 1


if __name__ == "__main__":
    sys.exit(main())
