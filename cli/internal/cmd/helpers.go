package cmd

import (
	"encoding/json"
	"fmt"
	"io"
	"os"
	"strings"
)

// Helpers shared by the command groups of the feature files.

// readArg returns the bytes a value flag names: "@path" reads a file, "-" or "@-" reads stdin,
// anything else is the value itself.
func readArg(app *App, v string) ([]byte, error) {
	switch {
	case v == "-" || v == "@-":
		return io.ReadAll(app.In)
	case strings.HasPrefix(v, "@"):
		return os.ReadFile(strings.TrimPrefix(v, "@"))
	}
	return []byte(v), nil
}

// readFileArg reads a file argument ("-" = stdin).
func readFileArg(app *App, path string) ([]byte, error) {
	if path == "-" {
		return io.ReadAll(app.In)
	}
	return os.ReadFile(path)
}

// readJSONArg reads a JSON value given inline, as @file or as - (stdin) and validates it.
// An empty value returns nil.
func readJSONArg(app *App, flag, v string) (json.RawMessage, error) {
	if v == "" {
		return nil, nil
	}
	data, err := readArg(app, v)
	if err != nil {
		return nil, err
	}
	if !json.Valid(data) {
		return nil, usageErrorf("%s is not valid JSON", flag)
	}
	return json.RawMessage(data), nil
}

// parseKeyValues turns KEY=VALUE arguments into a map (flag names the source in errors).
func parseKeyValues(flag string, pairs []string) (map[string]string, error) {
	out := map[string]string{}
	for _, p := range pairs {
		k, v, ok := strings.Cut(p, "=")
		k = strings.TrimSpace(k)
		if !ok || k == "" {
			return nil, usageErrorf("%s %q is not KEY=VALUE", flag, p)
		}
		out[k] = v
	}
	return out, nil
}

// yesNo renders a flag column: "yes" or "".
func yesNo(b bool) string {
	if b {
		return "yes"
	}
	return ""
}

// joinOrDash joins s with ", ", "-" when empty.
func joinOrDash(s []string) string {
	if len(s) == 0 {
		return "-"
	}
	return strings.Join(s, ", ")
}

// writeOut writes data to path, or to stdout for "" and "-".
func writeOut(app *App, path string, data []byte) error {
	if path == "" || path == "-" {
		_, err := app.Out.Write(data)
		return err
	}
	if err := os.WriteFile(path, data, 0o644); err != nil {
		return err
	}
	app.Progress("Wrote %s (%d bytes).", path, len(data))
	return nil
}

// createOut opens path for writing, or returns stdout for "" and "-"; close it when done.
func createOut(app *App, path string) (io.Writer, func() error, error) {
	if path == "" || path == "-" {
		return app.Out, func() error { return nil }, nil
	}
	f, err := os.Create(path)
	if err != nil {
		return nil, nil, err
	}
	return f, f.Close, nil
}

// countLabel renders "1 item" / "3 items".
func countLabel(n int, singular string) string {
	if n == 1 {
		return fmt.Sprintf("1 %s", singular)
	}
	return fmt.Sprintf("%d %ss", n, singular)
}
