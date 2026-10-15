package main

import (
	"bufio"
	"fmt"
	"io"
	"strings"
)

type stmtKind int

const (
	stmtSet stmtKind = iota
	stmtPrint
	stmtHeader
	stmtStatus
	stmtLog
	stmtReturn
	stmtIf
)

type stmt struct {
	kind stmtKind
	line int
	name string // set: variable, header: header name
	expr expr   // value, or the condition of an if (nil for a bare return)
	then *stmt
	els  *stmt
}

type program struct {
	stmts []*stmt
}

// Request accessors: the roots of a reference that are not variables.
var accessors = map[string]int{ // root -> number of path segments (0 = one or more)
	"method": 1, "path": 1, "body": 1, "trigger": 1, "id": 1,
	"query": 2, "header": 2, "event": 2, "data": 0,
}

var keywords = map[string]bool{
	"set": true, "print": true, "header": true, "status": true, "log": true, "return": true,
	"if": true, "then": true, "else": true, "or": true, "and": true, "not": true,
	"true": true, "false": true,
}

// parse reads a Q script: one statement per line, "#" comments, blank lines ignored.
func parse(r io.Reader) (*program, error) {
	prog := &program{}
	sc := bufio.NewScanner(r)
	sc.Buffer(make([]byte, 64*1024), 1024*1024)
	for n := 1; sc.Scan(); n++ {
		toks, err := lex(sc.Text())
		if err != nil {
			return nil, fmt.Errorf("line %d: %v", n, err)
		}
		if len(toks) == 0 {
			continue
		}
		p := &parser{toks: toks, line: n}
		s, err := p.statement()
		if err == nil && !p.atEnd() {
			err = fmt.Errorf("unexpected %s", p.peek().describe())
		}
		if err != nil {
			return nil, fmt.Errorf("line %d: %v", n, err)
		}
		prog.stmts = append(prog.stmts, s)
	}
	if err := sc.Err(); err != nil {
		return nil, err
	}
	return prog, nil
}

func (t token) describe() string {
	switch t.kind {
	case tokString:
		return "string"
	case tokNumber:
		return "number " + t.text
	default:
		return fmt.Sprintf("%q", t.text)
	}
}

type parser struct {
	toks []token
	pos  int
	line int
}

func (p *parser) atEnd() bool { return p.pos >= len(p.toks) }

func (p *parser) peek() token {
	if p.atEnd() {
		return token{kind: tokOp, text: "end of line"}
	}
	return p.toks[p.pos]
}

func (p *parser) isWord(w string) bool {
	return !p.atEnd() && p.toks[p.pos].kind == tokIdent && p.toks[p.pos].text == w
}

func (p *parser) isOp(op string) bool {
	return !p.atEnd() && p.toks[p.pos].kind == tokOp && p.toks[p.pos].text == op
}

func (p *parser) expectOp(op string) error {
	if !p.isOp(op) {
		return fmt.Errorf("expected %q, found %s", op, p.peek().describe())
	}
	p.pos++
	return nil
}

func (p *parser) name(what string) (string, error) {
	t := p.peek()
	if t.kind != tokIdent || keywords[t.text] {
		return "", fmt.Errorf("expected %s, found %s", what, t.describe())
	}
	p.pos++
	return t.text, nil
}

func (p *parser) statement() (*stmt, error) {
	t := p.peek()
	if t.kind != tokIdent {
		return nil, fmt.Errorf("expected a statement, found %s", t.describe())
	}
	p.pos++
	kw := t.text
	s := &stmt{line: p.line}
	var err error
	switch kw {
	case "set":
		s.kind = stmtSet
		if s.name, err = p.name("a variable name"); err != nil {
			return nil, err
		}
		if _, isAccessor := accessors[s.name]; isAccessor || strings.ContainsAny(s.name, ".-") {
			return nil, fmt.Errorf("%q can not be a variable name", s.name)
		}
		if err = p.expectOp("="); err != nil {
			return nil, err
		}
		s.expr, err = p.expr()
	case "header":
		s.kind = stmtHeader
		if s.name, err = p.name("a header name"); err != nil {
			return nil, err
		}
		if strings.Contains(s.name, ".") {
			return nil, fmt.Errorf("invalid header name %q", s.name)
		}
		if err = p.expectOp("="); err != nil {
			return nil, err
		}
		s.expr, err = p.expr()
	case "print", "status", "log":
		s.kind = map[string]stmtKind{"print": stmtPrint, "status": stmtStatus, "log": stmtLog}[kw]
		s.expr, err = p.expr()
	case "return":
		s.kind = stmtReturn
		if !p.atEnd() && !p.isWord("else") {
			s.expr, err = p.expr()
		}
	case "if":
		s.kind = stmtIf
		if s.expr, err = p.expr(); err != nil {
			return nil, err
		}
		if !p.isWord("then") {
			return nil, fmt.Errorf("expected \"then\", found %s", p.peek().describe())
		}
		p.pos++
		if s.then, err = p.statement(); err != nil {
			return nil, err
		}
		if p.isWord("else") {
			p.pos++
			s.els, err = p.statement()
		}
	default:
		return nil, fmt.Errorf("unknown statement %q", kw)
	}
	if err != nil {
		return nil, err
	}
	return s, nil
}

// Expression grammar, lowest precedence first:
//
//	or  := and ("or" and)*
//	and := cmp ("and" cmp)*
//	cmp := add (("==" | "!=") add)?
//	add := unary ("+" unary)*
//	unary := "not" unary | primary
//	primary := string | number | true | false | reference | "(" or ")"
func (p *parser) expr() (expr, error) { return p.binary(0) }

var levels = [][]string{{"or"}, {"and"}, {"==", "!="}, {"+"}}

func (p *parser) binary(level int) (expr, error) {
	if level == len(levels) {
		return p.unary()
	}
	left, err := p.binary(level + 1)
	if err != nil {
		return nil, err
	}
	for {
		op := ""
		for _, candidate := range levels[level] {
			if p.isOp(candidate) || p.isWord(candidate) {
				op = candidate
			}
		}
		if op == "" {
			return left, nil
		}
		p.pos++
		right, err := p.binary(level + 1)
		if err != nil {
			return nil, err
		}
		left = binExpr{op: op, l: left, r: right}
		if op == "==" || op == "!=" {
			return left, nil
		}
	}
}

func (p *parser) unary() (expr, error) {
	if p.isWord("not") {
		p.pos++
		x, err := p.unary()
		if err != nil {
			return nil, err
		}
		return notExpr{x: x}, nil
	}
	return p.primary()
}

func (p *parser) primary() (expr, error) {
	t := p.peek()
	switch {
	case p.atEnd():
		return nil, fmt.Errorf("expected a value, found end of line")
	case t.kind == tokString:
		p.pos++
		for _, part := range t.parts {
			if part.ref != nil {
				if err := checkRef(part.ref); err != nil {
					return nil, err
				}
			}
		}
		return strExpr{parts: t.parts}, nil
	case t.kind == tokNumber:
		p.pos++
		if strings.Count(t.text, ".") > 1 || strings.HasSuffix(t.text, ".") {
			return nil, fmt.Errorf("invalid number %s", t.text)
		}
		return litExpr{v: t.text}, nil
	case t.kind == tokOp && t.text == "(":
		p.pos++
		x, err := p.expr()
		if err != nil {
			return nil, err
		}
		return x, p.expectOp(")")
	case t.kind == tokIdent && (t.text == "true" || t.text == "false"):
		p.pos++
		return litExpr{v: t.text}, nil
	case t.kind == tokIdent && !keywords[t.text]:
		p.pos++
		ref, err := splitRef(t.text)
		if err != nil {
			return nil, err
		}
		if err := checkRef(ref); err != nil {
			return nil, err
		}
		return refExpr{path: ref}, nil
	}
	return nil, fmt.Errorf("expected a value, found %s", t.describe())
}

// checkRef validates the shape of a reference: a plain variable name, or an accessor with its
// number of segments (query.<name>, header.<name>, event.<field>, data[.<key>...]).
func checkRef(ref []string) error {
	n, isAccessor := accessors[ref[0]]
	switch {
	case !isAccessor && len(ref) == 1:
		if strings.Contains(ref[0], "-") {
			return fmt.Errorf("invalid variable name %q", ref[0])
		}
		return nil
	case !isAccessor:
		return fmt.Errorf("unknown accessor %q", ref[0])
	case n == 0 || len(ref) == n:
		return nil
	case n == 1:
		return fmt.Errorf("%q has no fields", ref[0])
	default:
		return fmt.Errorf("%q needs a name, e.g. %s.name", strings.Join(ref, "."), ref[0])
	}
}
