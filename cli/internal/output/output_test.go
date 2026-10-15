package output

import (
	"bytes"
	"strings"
	"testing"
	"time"
)

type item struct {
	Name  string `json:"name"`
	Count int    `json:"count"`
}

func TestFormats(t *testing.T) {
	v := []item{{"a", 1}, {"bb", 22}}
	var b bytes.Buffer
	p := &Printer{Out: &b, Format: JSON}
	if err := p.Print(v, nil); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(b.String(), `"name": "a"`) {
		t.Fatalf("json = %s", b.String())
	}

	b.Reset()
	p.Format = YAML
	_ = p.Print(v, nil)
	if !strings.Contains(b.String(), "- count: 1\n  name: a\n") {
		t.Fatalf("yaml = %q", b.String())
	}

	b.Reset()
	p.Format = Table
	_ = p.Print(v, func(t *TableWriter) {
		t.Header("NAME", "COUNT")
		for _, i := range v {
			t.Row(i.Name, "x")
		}
		t.Note("done")
	})
	lines := strings.Split(strings.TrimRight(b.String(), "\n"), "\n")
	if len(lines) != 4 || !strings.HasPrefix(lines[0], "NAME   ") || lines[3] != "done" {
		t.Fatalf("table:\n%s", b.String())
	}
	if p.Machine() || !(&Printer{Format: YAML}).Machine() {
		t.Fatal("Machine()")
	}
}

func TestKeyValues(t *testing.T) {
	var b bytes.Buffer
	tw := &TableWriter{}
	tw.KV("Name", "VALUE1")
	tw.KV("Longer key", "VALUE2")
	_ = tw.Render(&b)
	lines := strings.Split(strings.TrimRight(b.String(), "\n"), "\n")
	if len(lines) != 2 || !strings.HasPrefix(lines[0], "Name:") || strings.Index(lines[0], "VALUE1") != strings.Index(lines[1], "VALUE2") {
		t.Fatalf("key/values are not aligned:\n%s", b.String())
	}
}

func TestHelpers(t *testing.T) {
	if Dash(" ") != "-" || Dash("x") != "x" {
		t.Fatal("Dash")
	}
	if Truncate("abcdef", 4) != "abc…" || Truncate("a\nb", 10) != "a b" {
		t.Fatal("Truncate")
	}
	if Duration(90*time.Second) != "1m" || Duration(3*time.Hour) != "3h" || Duration(72*time.Hour) != "3d" || Duration(5*time.Second) != "5s" {
		t.Fatal("Duration")
	}
	if Age(nil) != "-" {
		t.Fatal("Age(nil)")
	}
	if !Valid("") || !Valid("yaml") || Valid("xml") {
		t.Fatal("Valid")
	}
}
