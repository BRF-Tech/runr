// Package output renders command results as human tables, JSON or YAML.
package output

import (
	"encoding/json"
	"fmt"
	"io"
	"strings"
	"text/tabwriter"
	"time"

	"gopkg.in/yaml.v3"
)

// Formats.
const (
	Table = "table"
	JSON  = "json"
	YAML  = "yaml"
)

// Valid reports whether f is a known format.
func Valid(f string) bool { return f == Table || f == JSON || f == YAML || f == "" }

// Printer writes results in one format.
type Printer struct {
	Out    io.Writer
	Format string
}

// Machine reports whether the format is JSON or YAML.
func (p *Printer) Machine() bool { return p.Format == JSON || p.Format == YAML }

// Print writes v as JSON/YAML, or calls table to build the human view.
func (p *Printer) Print(v any, table func(t *TableWriter)) error {
	switch p.Format {
	case JSON:
		return WriteJSON(p.Out, v)
	case YAML:
		return WriteYAML(p.Out, v)
	}
	t := &TableWriter{}
	table(t)
	return t.Render(p.Out)
}

// WriteJSON writes indented JSON.
func WriteJSON(w io.Writer, v any) error {
	data, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}
	_, err = fmt.Fprintln(w, string(data))
	return err
}

// WriteYAML writes v as YAML with the JSON field names.
func WriteYAML(w io.Writer, v any) error {
	data, err := json.Marshal(v)
	if err != nil {
		return err
	}
	var generic any
	if err := json.Unmarshal(data, &generic); err != nil {
		return err
	}
	out, err := yaml.Marshal(generic)
	if err != nil {
		return err
	}
	_, err = w.Write(out)
	return err
}

// TableWriter collects a table or key/value list.
type TableWriter struct {
	headers []string
	rows    [][]string
	kv      [][2]string
	notes   []string
}

// Header sets the column names.
func (t *TableWriter) Header(cols ...string) { t.headers = cols }

// Row adds a row.
func (t *TableWriter) Row(cols ...string) { t.rows = append(t.rows, cols) }

// KV adds a key/value line (detail views).
func (t *TableWriter) KV(key, value string) { t.kv = append(t.kv, [2]string{key, value}) }

// Note adds a line printed after the table.
func (t *TableWriter) Note(format string, args ...any) {
	t.notes = append(t.notes, fmt.Sprintf(format, args...))
}

// Empty reports whether nothing was added.
func (t *TableWriter) Empty() bool { return len(t.rows) == 0 && len(t.kv) == 0 }

// Render writes the table.
func (t *TableWriter) Render(w io.Writer) error {
	tw := tabwriter.NewWriter(w, 0, 4, 3, ' ', 0)
	if len(t.kv) > 0 {
		for _, kv := range t.kv {
			fmt.Fprintf(tw, "%s:\t%s\n", kv[0], kv[1])
		}
	}
	if len(t.rows) > 0 || (len(t.headers) > 0 && len(t.kv) == 0) {
		if len(t.kv) > 0 {
			fmt.Fprintln(tw)
		}
		if len(t.headers) > 0 {
			fmt.Fprintln(tw, strings.Join(t.headers, "\t"))
		}
		for _, r := range t.rows {
			fmt.Fprintln(tw, strings.Join(r, "\t"))
		}
	}
	if err := tw.Flush(); err != nil {
		return err
	}
	for _, n := range t.notes {
		fmt.Fprintln(w, n)
	}
	return nil
}

// Dash returns s, or "-" when it is empty.
func Dash(s string) string {
	if strings.TrimSpace(s) == "" {
		return "-"
	}
	return s
}

// Age renders a timestamp as a short relative age ("5m", "3d").
func Age(t *time.Time) string {
	if t == nil || t.IsZero() {
		return "-"
	}
	return Duration(time.Since(*t))
}

// Duration renders d with one unit.
func Duration(d time.Duration) string {
	switch {
	case d < 0:
		return "0s"
	case d < time.Minute:
		return fmt.Sprintf("%ds", int(d.Seconds()))
	case d < time.Hour:
		return fmt.Sprintf("%dm", int(d.Minutes()))
	case d < 48*time.Hour:
		return fmt.Sprintf("%dh", int(d.Hours()))
	default:
		return fmt.Sprintf("%dd", int(d.Hours()/24))
	}
}

// Truncate shortens s to n runes.
func Truncate(s string, n int) string {
	s = strings.ReplaceAll(s, "\n", " ")
	r := []rune(s)
	if len(r) <= n {
		return s
	}
	return string(r[:n-1]) + "…"
}
