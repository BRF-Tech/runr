// q is the interpreter of Q, a tiny line-based language for RunR handlers.
//
//	q script.q          persistent mode: request envelopes as JSON lines on stdin,
//	                    one JSON response line per request on stdout
//	q -once script.q    one request: the envelope on stdin, the response on stdout
//	q -cgi script.q     one CGI request (WAGI): the request in the environment, the body on
//	                    stdin, the response as CGI on stdout (cgi.go)
//	q -check script.q   parse only
//
// The script is parsed once at start; parse errors go to stderr with the line number (exit 2).
package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"os"
)

func main() {
	once := flag.Bool("once", false, "handle one request from stdin and exit")
	check := flag.Bool("check", false, "parse the script and exit")
	cgi := flag.Bool("cgi", false, "handle one CGI request (WAGI) and exit")
	flag.Usage = func() {
		fmt.Fprintln(os.Stderr, "usage: q [-once | -cgi | -check] script.q")
	}
	flag.Parse()
	if flag.NArg() != 1 {
		flag.Usage()
		os.Exit(2)
	}

	prog, err := load(flag.Arg(0))
	if err != nil {
		fmt.Fprintln(os.Stderr, "q:", err)
		os.Exit(2)
	}

	switch {
	case *check:
		return
	case *cgi:
		in, err := io.ReadAll(os.Stdin)
		if err == nil {
			err = writeCGI(os.Stdout, run(prog, cgiEnvelope(os.Environ(), in), os.Stderr))
		}
		if err != nil {
			fmt.Fprintln(os.Stderr, "q:", err)
			os.Exit(1)
		}
	case *once:
		in, err := io.ReadAll(os.Stdin)
		if err == nil {
			err = writeResponse(os.Stdout, handle(prog, in, os.Stderr))
		}
		if err != nil {
			fmt.Fprintln(os.Stderr, "q:", err)
			os.Exit(1)
		}
	default:
		if err := serve(prog, os.Stdin, os.Stdout, os.Stderr); err != nil {
			fmt.Fprintln(os.Stderr, "q:", err)
			os.Exit(1)
		}
	}
}

func load(path string) (*program, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	prog, err := parse(f)
	if err != nil {
		return nil, fmt.Errorf("%s: %v", path, err)
	}
	return prog, nil
}

// handle runs the program for one envelope; an envelope that is not valid JSON gets a 400.
func handle(prog *program, line []byte, log io.Writer) response {
	var req envelope
	if err := json.Unmarshal(line, &req); err != nil {
		fmt.Fprintln(log, "q: invalid request envelope:", err)
		return response{StatusCode: 400, Headers: map[string]string{"Content-Type": "application/json"}, Body: `{"error":"invalid request envelope"}`}
	}
	return run(prog, req, log)
}

func writeResponse(w io.Writer, resp response) error {
	b, err := json.Marshal(resp)
	if err != nil {
		return err
	}
	_, err = w.Write(append(b, '\n'))
	return err
}

// serve answers every request line on in with exactly one response line on out.
func serve(prog *program, in io.Reader, out io.Writer, log io.Writer) error {
	r := bufio.NewReaderSize(in, 1<<20)
	w := bufio.NewWriter(out)
	for {
		line, readErr := r.ReadBytes('\n')
		if len(bytes.TrimSpace(line)) > 0 {
			if err := writeResponse(w, handle(prog, line, log)); err != nil {
				return err
			}
			if err := w.Flush(); err != nil {
				return err
			}
		}
		if readErr == io.EOF {
			return nil
		}
		if readErr != nil {
			return readErr
		}
	}
}
