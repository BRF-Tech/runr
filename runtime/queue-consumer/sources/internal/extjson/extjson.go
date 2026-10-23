// Package extjson encodes broker headers as the JSON value of a CloudEvents extension attribute
// (kafkaheaders, natsheaders, rabbitheaders).
package extjson

import (
	"encoding/json"
	"strings"
)

// MaxLen is the largest extension value Encode returns: headers beyond it are left out, so a
// message with huge headers does not produce a huge ce-* HTTP header.
const MaxLen = 8 << 10

// Encode returns v as JSON, or "" when v is empty, cannot be encoded or encodes to more than
// MaxLen bytes.
func Encode(v any) string {
	b, err := json.Marshal(v)
	if err != nil || len(b) > MaxLen {
		return ""
	}
	switch s := string(b); s {
	case "{}", "null", "[]":
		return ""
	default:
		return s
	}
}

// Joined folds multi-valued headers into one string per name: the values joined by ", ", the
// way HTTP combines repeated header fields.
func Joined(h map[string][]string) map[string]string {
	if len(h) == 0 {
		return nil
	}
	out := make(map[string]string, len(h))
	for k, vs := range h {
		out[k] = strings.Join(vs, ", ")
	}
	return out
}
