package conf

import (
	"testing"
	"time"
)

func TestParseDuration(t *testing.T) {
	cases := map[string]time.Duration{
		"2":     2 * time.Second,
		"0.5":   500 * time.Millisecond,
		"250ms": 250 * time.Millisecond,
		"1m":    time.Minute,
	}
	for in, want := range cases {
		got, err := ParseDuration(in)
		if err != nil || got != want {
			t.Errorf("ParseDuration(%q) = %v, %v; want %v", in, got, err, want)
		}
	}
	for _, bad := range []string{"-1", "abc", "-2s"} {
		if _, err := ParseDuration(bad); err == nil {
			t.Errorf("ParseDuration(%q) accepted", bad)
		}
	}
}

func env(m map[string]string) func(string) string {
	return func(k string) string { return m[k] }
}

func TestDurationFallsBackOnInvalidValues(t *testing.T) {
	for _, v := range []string{"soon", "-5", "-1m"} {
		if got := Duration(env(map[string]string{"QC_DURATION": v}), "QC_DURATION", 7*time.Second); got != 7*time.Second {
			t.Errorf("Duration(%q) = %v, want the default", v, got)
		}
	}
	if got := Duration(env(map[string]string{"QC_DURATION": " 250ms "}), "QC_DURATION", time.Second); got != 250*time.Millisecond {
		t.Errorf("Duration(250ms) = %v", got)
	}
}

func TestIntAndString(t *testing.T) {
	e := env(map[string]string{"N": " 42 ", "BAD": "abc", "S": "x"})
	if got := Int(e, "N", 1); got != 42 {
		t.Errorf("Int(N) = %d", got)
	}
	if got := Int(e, "BAD", 7); got != 7 {
		t.Errorf("Int(BAD) = %d, want the default", got)
	}
	if got := Int(e, "UNSET", 9); got != 9 {
		t.Errorf("Int(UNSET) = %d, want the default", got)
	}
	if String(e, "S", "d") != "x" || String(e, "UNSET", "d") != "d" {
		t.Error("String does not fall back to the default for empty values only")
	}
}
