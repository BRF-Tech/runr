package main

import (
	"fmt"
	"strings"
)

type tokenKind int

const (
	tokIdent tokenKind = iota
	tokString
	tokNumber
	tokOp
)

type token struct {
	kind  tokenKind
	text  string    // identifier, number or operator
	parts []strPart // string literal: literal text and {path} interpolations
}

// strPart is a piece of a string literal: literal text, or a reference ({name}, {query.name}).
type strPart struct {
	text string
	ref  []string
}

func isIdentStart(c byte) bool {
	return c == '_' || (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z')
}

func isIdentChar(c byte) bool {
	return isIdentStart(c) || (c >= '0' && c <= '9') || c == '.' || c == '-'
}

func isDigit(c byte) bool { return c >= '0' && c <= '9' }

// lex splits one source line into tokens; "#" outside a string starts a comment.
func lex(line string) ([]token, error) {
	var toks []token
	for i := 0; i < len(line); {
		c := line[i]
		switch {
		case c == ' ' || c == '\t' || c == '\r':
			i++
		case c == '#':
			return toks, nil
		case c == '"':
			parts, n, err := lexString(line[i:])
			if err != nil {
				return nil, err
			}
			toks = append(toks, token{kind: tokString, parts: parts})
			i += n
		case isDigit(c):
			j := i
			for j < len(line) && (isDigit(line[j]) || line[j] == '.') {
				j++
			}
			toks = append(toks, token{kind: tokNumber, text: line[i:j]})
			i = j
		case isIdentStart(c):
			j := i
			for j < len(line) && isIdentChar(line[j]) {
				j++
			}
			toks = append(toks, token{kind: tokIdent, text: line[i:j]})
			i = j
		case strings.HasPrefix(line[i:], "==") || strings.HasPrefix(line[i:], "!="):
			toks = append(toks, token{kind: tokOp, text: line[i : i+2]})
			i += 2
		case c == '=' || c == '+' || c == '(' || c == ')':
			toks = append(toks, token{kind: tokOp, text: string(c)})
			i++
		default:
			return nil, fmt.Errorf("unexpected character %q", c)
		}
	}
	return toks, nil
}

// lexString reads a "..." literal at the start of s and returns its parts and length.
// Escapes: \" \\ \n \t \{ \}. "{path}" interpolates a variable or accessor.
func lexString(s string) ([]strPart, int, error) {
	var parts []strPart
	var lit strings.Builder
	flush := func() {
		if lit.Len() > 0 {
			parts = append(parts, strPart{text: lit.String()})
			lit.Reset()
		}
	}
	for i := 1; i < len(s); i++ {
		switch c := s[i]; c {
		case '"':
			flush()
			return parts, i + 1, nil
		case '\\':
			if i+1 >= len(s) {
				return nil, 0, fmt.Errorf("unterminated string")
			}
			i++
			switch s[i] {
			case 'n':
				lit.WriteByte('\n')
			case 't':
				lit.WriteByte('\t')
			case '"', '\\', '{', '}':
				lit.WriteByte(s[i])
			default:
				return nil, 0, fmt.Errorf("unknown escape \\%c", s[i])
			}
		case '{':
			end := strings.IndexByte(s[i:], '}')
			if end < 0 {
				return nil, 0, fmt.Errorf("unclosed { in string")
			}
			ref, err := splitRef(s[i+1 : i+end])
			if err != nil {
				return nil, 0, err
			}
			flush()
			parts = append(parts, strPart{ref: ref})
			i += end
		default:
			lit.WriteByte(c)
		}
	}
	return nil, 0, fmt.Errorf("unterminated string")
}

// splitRef validates "name" / "query.name" / "data.user.id" and splits it on dots.
func splitRef(s string) ([]string, error) {
	if s == "" || !isIdentStart(s[0]) {
		return nil, fmt.Errorf("invalid reference %q", s)
	}
	for i := 0; i < len(s); i++ {
		if !isIdentChar(s[i]) {
			return nil, fmt.Errorf("invalid reference %q", s)
		}
	}
	path := strings.Split(s, ".")
	for _, seg := range path {
		if seg == "" {
			return nil, fmt.Errorf("invalid reference %q", s)
		}
	}
	return path, nil
}
