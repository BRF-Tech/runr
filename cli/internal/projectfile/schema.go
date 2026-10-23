package projectfile

import (
	_ "embed"
	"encoding/json"
	"fmt"
	"math"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
	_ "time/tzdata" // format timezone: the IANA zones without the system's zoneinfo
	"unicode/utf8"
)

// triggerSchemaJSON is api/resources/triggers/triggers.schema.json, the schema of one runr.yaml
// `triggers:` entry that the API derives from its trigger types' validation rules (`php artisan
// triggers:schema`, App\Triggers\TriggerSchema). A test keeps the copy identical to the API's.
//
//go:embed triggers.schema.json
var triggerSchemaJSON []byte

// triggerSchemaDoc is the schema document: the fields of every entry and the config of each type.
type triggerSchemaDoc struct {
	Version int                    `json:"version"`
	Entry   *jsonSchema            `json:"entry"`
	Types   map[string]*jsonSchema `json:"types"`
}

// jsonSchema is the keyword subset the API's generator emits, with Laravel's meaning where it
// differs from JSON Schema (the document's $comment):
//   - type: string, integer (a string of digits too), number (a numeric string too), boolean
//     (also 0, 1, "0", "1"), array (a list or a mapping), object, null;
//   - required: present and not empty (not null, a blank string, an empty list or mapping);
//   - items: every element of a list, every value of a mapping;
//   - enum: compared as PHP strings ("1" matches 1 and true);
//   - pattern / not.pattern: RE2; format: cron or timezone.
type jsonSchema struct {
	Type       schemaTypes            `json:"type"`
	Format     string                 `json:"format"`
	Enum       []string               `json:"enum"`
	Pattern    string                 `json:"pattern"`
	AllOf      []*jsonSchema          `json:"allOf"`
	AnyOf      []*jsonSchema          `json:"anyOf"`
	Not        *jsonSchema            `json:"not"`
	MinLength  *int                   `json:"minLength"`
	MaxLength  *int                   `json:"maxLength"`
	Minimum    *float64               `json:"minimum"`
	Maximum    *float64               `json:"maximum"`
	MinItems   *int                   `json:"minItems"`
	MaxItems   *int                   `json:"maxItems"`
	Required   []string               `json:"required"`
	Properties map[string]*jsonSchema `json:"properties"`
	Items      *jsonSchema            `json:"items"`
}

// schemaTypes is "type": one name or a list of names.
type schemaTypes []string

func (t *schemaTypes) UnmarshalJSON(b []byte) error {
	var one string
	if err := json.Unmarshal(b, &one); err == nil {
		*t = schemaTypes{one}
		return nil
	}
	var many []string
	if err := json.Unmarshal(b, &many); err != nil {
		return err
	}
	*t = many
	return nil
}

func (t schemaTypes) allows(name string) bool {
	for _, n := range t {
		if n == name {
			return true
		}
	}
	return false
}

var (
	schemaOnce sync.Once
	schemaDoc  *triggerSchemaDoc
	schemaErr  error

	regexMu    sync.Mutex
	regexCache = map[string]*regexp.Regexp{}
)

// triggerSchema returns the embedded schema document.
func triggerSchema() (*triggerSchemaDoc, error) {
	schemaOnce.Do(func() {
		var d triggerSchemaDoc
		if err := json.Unmarshal(triggerSchemaJSON, &d); err != nil {
			schemaErr = fmt.Errorf("embedded trigger schema: %w", err)
			return
		}
		schemaDoc = &d
	})
	return schemaDoc, schemaErr
}

// compiled returns the RE2 form of a pattern; nil when Go cannot compile it (the check is then
// left to the server).
func compiled(pattern string) *regexp.Regexp {
	regexMu.Lock()
	defer regexMu.Unlock()
	if re, ok := regexCache[pattern]; ok {
		return re
	}
	re, err := regexp.Compile(pattern)
	if err != nil {
		re = nil
	}
	regexCache[pattern] = re
	return re
}

// validate checks v against s and returns the problems, each prefixed with path.
func (s *jsonSchema) validate(path string, v any) []string {
	if s == nil {
		return nil
	}
	if v == nil {
		// nullable, or no type said: nothing else applies (Laravel stops at a nullable null)
		if len(s.Type) == 0 || s.Type.allows("null") {
			return nil
		}
		return []string{path + " must " + s.typeLabel()}
	}
	if len(s.Type) > 0 && !s.typeMatches(v) {
		return []string{path + " must " + s.typeLabel()}
	}

	var p []string
	kind := s.valueKind(v)
	switch kind {
	case "integer", "number":
		n, _ := number(v)
		p = append(p, s.numberBounds(path, n)...)
	case "array":
		n := count(v)
		switch {
		case s.MinItems != nil && s.MaxItems != nil && (n < *s.MinItems || n > *s.MaxItems):
			p = append(p, fmt.Sprintf("%s must list between %d and %d entries", path, *s.MinItems, *s.MaxItems))
		case s.MinItems != nil && n < *s.MinItems:
			p = append(p, fmt.Sprintf("%s must list at least %s", path, entries(*s.MinItems)))
		case s.MaxItems != nil && n > *s.MaxItems:
			p = append(p, fmt.Sprintf("%s must list at most %s", path, entries(*s.MaxItems)))
		}
	case "string":
		n := utf8.RuneCountInString(v.(string))
		switch {
		case s.MinLength != nil && s.MaxLength != nil && *s.MinLength == *s.MaxLength && n != *s.MinLength:
			p = append(p, fmt.Sprintf("%s must be %d characters long", path, *s.MinLength))
		case s.MinLength != nil && n < *s.MinLength:
			p = append(p, fmt.Sprintf("%s must be at least %s long", path, characters(*s.MinLength)))
		case s.MaxLength != nil && n > *s.MaxLength:
			p = append(p, fmt.Sprintf("%s must be at most %s long", path, characters(*s.MaxLength)))
		}
	}

	if len(s.Enum) > 0 {
		str, ok := phpString(v)
		if !ok || !containsString(s.Enum, str) {
			p = append(p, fmt.Sprintf("%s must be one of %s", path, strings.Join(s.Enum, ", ")))
		}
	}
	if s.Pattern != "" {
		if re := compiled(s.Pattern); re != nil {
			str, ok := phpString(v)
			if _, scalar := v.(string); !scalar && !isNumber(v) {
				ok = false
			}
			if !ok || !re.MatchString(str) {
				p = append(p, fmt.Sprintf("%s must match %s", path, displayPattern(s.Pattern)))
			}
		}
	}
	for _, sub := range s.AllOf {
		p = append(p, sub.validate(path, v)...)
	}
	if s.Not != nil && s.Not.matches(v) {
		p = append(p, fmt.Sprintf("%s must not match %s", path, s.Not.describe()))
	}
	switch s.Format {
	case "cron":
		if str, ok := v.(string); ok && !validCron(str) {
			p = append(p, path+" must be a cron expression with five fields (minute hour day-of-month month day-of-week), e.g. \"*/5 * * * *\", or @hourly, @daily, @weekly, @monthly, @yearly")
		}
	case "timezone":
		if str, ok := v.(string); ok && !validTimeZone(str) {
			p = append(p, fmt.Sprintf("%s must be a time zone of the IANA database, e.g. Europe/Berlin or UTC (got %q)", path, str))
		}
	}

	// properties of a mapping, required ones present and not empty
	if m, ok := v.(map[string]any); ok {
		missing := map[string]bool{}
		for _, k := range s.Required {
			if empty(m[k]) {
				p = append(p, join(path, k)+" is required")
				missing[k] = true
			}
		}
		for _, k := range sortedKeysOf(s.Properties) {
			if val, ok := m[k]; ok && !missing[k] {
				p = append(p, s.Properties[k].validate(join(path, k), val)...)
			}
		}
	}
	// items: the elements of a list, the values of a mapping
	if s.Items != nil {
		switch t := v.(type) {
		case []any:
			for i, el := range t {
				p = append(p, s.Items.validate(fmt.Sprintf("%s[%d]", path, i), el)...)
			}
		case map[string]any:
			for _, k := range sortedKeysOf(t) {
				p = append(p, s.Items.validate(join(path, k), t[k])...)
			}
		}
	}
	return p
}

// matches reports whether v passes s (anyOf: one of them).
func (s *jsonSchema) matches(v any) bool {
	if len(s.AnyOf) > 0 {
		for _, sub := range s.AnyOf {
			if sub.matches(v) {
				return true
			}
		}
		return false
	}
	return len(s.validate("", v)) == 0
}

func (s *jsonSchema) describe() string {
	if s.Pattern != "" {
		return displayPattern(s.Pattern)
	}
	var parts []string
	for _, sub := range s.AnyOf {
		parts = append(parts, sub.describe())
	}
	return strings.Join(parts, " or ")
}

// typeMatches checks a value against the type names the way Laravel's rules take it.
func (s *jsonSchema) typeMatches(v any) bool {
	for _, t := range s.Type {
		switch t {
		case "string":
			if _, ok := v.(string); ok {
				return true
			}
		case "integer":
			if isInteger(v) {
				return true
			}
		case "number":
			if _, ok := number(v); ok {
				return true
			}
		case "boolean":
			if _, ok := laravelBool(v); ok {
				return true
			}
		case "array":
			switch v.(type) {
			case []any, map[string]any:
				return true
			}
		case "object":
			if _, ok := v.(map[string]any); ok {
				return true
			}
		}
	}
	return false
}

// valueKind is the type a value counts as for the size keywords.
func (s *jsonSchema) valueKind(v any) string {
	for _, t := range s.Type {
		switch t {
		case "integer", "number":
			if _, ok := number(v); ok {
				return t
			}
		case "array":
			switch v.(type) {
			case []any, map[string]any:
				return t
			}
		case "string":
			if _, ok := v.(string); ok {
				return t
			}
		}
	}
	return ""
}

func (s *jsonSchema) typeLabel() string {
	var names []string
	for _, t := range s.Type {
		switch t {
		case "string":
			names = append(names, "a string")
		case "integer":
			names = append(names, "a whole number")
		case "number":
			names = append(names, "a number")
		case "boolean":
			names = append(names, "true or false")
		case "array":
			names = append(names, "a list")
		case "object":
			names = append(names, "a mapping")
		}
	}
	if len(names) == 0 {
		return "be set"
	}
	return "be " + strings.Join(names, " or ")
}

func (s *jsonSchema) numberBounds(path string, n float64) []string {
	switch {
	case s.Minimum != nil && s.Maximum != nil && (n < *s.Minimum || n > *s.Maximum):
		return []string{fmt.Sprintf("%s must be between %s and %s", path, formatNumber(*s.Minimum), formatNumber(*s.Maximum))}
	case s.Minimum != nil && n < *s.Minimum:
		return []string{fmt.Sprintf("%s must be at least %s", path, formatNumber(*s.Minimum))}
	case s.Maximum != nil && n > *s.Maximum:
		return []string{fmt.Sprintf("%s must be at most %s", path, formatNumber(*s.Maximum))}
	}
	return nil
}

// unknownKeys lists the keys of m (and of its nested mappings with known properties) that s does
// not describe, dotted: the server drops them.
func (s *jsonSchema) unknownKeys(prefix string, m map[string]any) []string {
	if s == nil || s.Properties == nil {
		return nil
	}
	var out []string
	for _, k := range sortedKeysOf(m) {
		sub, ok := s.Properties[k]
		if !ok {
			out = append(out, prefix+k)
			continue
		}
		if nested, ok := m[k].(map[string]any); ok && sub.Properties != nil {
			out = append(out, sub.unknownKeys(prefix+k+".", nested)...)
		}
	}
	return out
}

func join(path, key string) string {
	if path == "" {
		return key
	}
	return path + "." + key
}

func sortedKeysOf[V any](m map[string]V) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

// empty is Laravel's "required" failing: null, a blank string, an empty list or mapping.
func empty(v any) bool {
	switch t := v.(type) {
	case nil:
		return true
	case string:
		return strings.TrimSpace(t) == ""
	case []any:
		return len(t) == 0
	case map[string]any:
		return len(t) == 0
	}
	return false
}

func isNumber(v any) bool {
	switch v.(type) {
	case int, int8, int16, int32, int64, uint, uint8, uint16, uint32, uint64, float32, float64:
		return true
	}
	return false
}

var intString = regexp.MustCompile(`^\s*[+-]?\d+\s*$`)

// isInteger: Laravel's integer rule — a whole number, or a string of one.
func isInteger(v any) bool {
	switch t := v.(type) {
	case int, int8, int16, int32, int64, uint, uint8, uint16, uint32, uint64:
		return true
	case float64:
		return t == math.Trunc(t) && !math.IsInf(t, 0)
	case float32:
		return float64(t) == math.Trunc(float64(t))
	case string:
		return intString.MatchString(t)
	}
	return false
}

// number: a numeric value or numeric string (PHP's is_numeric).
func number(v any) (float64, bool) {
	switch t := v.(type) {
	case int:
		return float64(t), true
	case int8:
		return float64(t), true
	case int16:
		return float64(t), true
	case int32:
		return float64(t), true
	case int64:
		return float64(t), true
	case uint:
		return float64(t), true
	case uint8:
		return float64(t), true
	case uint16:
		return float64(t), true
	case uint32:
		return float64(t), true
	case uint64:
		return float64(t), true
	case float32:
		return float64(t), true
	case float64:
		return t, true
	case string:
		f, err := strconv.ParseFloat(strings.TrimSpace(t), 64)
		return f, err == nil
	}
	return 0, false
}

// laravelBool: Laravel's boolean rule — true, false, 0, 1, "0", "1".
func laravelBool(v any) (bool, bool) {
	switch t := v.(type) {
	case bool:
		return t, true
	case string:
		switch t {
		case "0":
			return false, true
		case "1":
			return true, true
		}
		return false, false
	}
	if isNumber(v) {
		if n, _ := number(v); n == 0 || n == 1 {
			return n == 1, true
		}
	}
	return false, false
}

// phpString is (string) $value of a scalar: true "1", false "", numbers without a trailing .0.
func phpString(v any) (string, bool) {
	switch t := v.(type) {
	case string:
		return t, true
	case bool:
		if t {
			return "1", true
		}
		return "", true
	case float64:
		return strconv.FormatFloat(t, 'f', -1, 64), true
	case float32:
		return strconv.FormatFloat(float64(t), 'f', -1, 32), true
	}
	if isNumber(v) {
		return fmt.Sprint(v), true
	}
	return "", false
}

func count(v any) int {
	switch t := v.(type) {
	case []any:
		return len(t)
	case map[string]any:
		return len(t)
	}
	return 0
}

func formatNumber(f float64) string { return strconv.FormatFloat(f, 'f', -1, 64) }

func entries(n int) string {
	if n == 1 {
		return "one entry"
	}
	return fmt.Sprintf("%d entries", n)
}

func characters(n int) string {
	if n == 1 {
		return "1 character"
	}
	return fmt.Sprintf("%d characters", n)
}

// displayPattern shows a pattern as written in the API (without the generator's "\n?" that keeps
// PCRE's "$ before a final newline").
func displayPattern(p string) string {
	return strings.ReplaceAll(p, `\n?$`, "$")
}

// validTimeZone: an IANA zone name (backward-compatible names included), not Local.
func validTimeZone(name string) bool {
	if name == "" || name == "Local" || strings.TrimSpace(name) != name {
		return false
	}
	_, err := time.LoadLocation(name)
	return err == nil
}

var (
	cronMacros    = []string{"@yearly", "@annually", "@monthly", "@weekly", "@daily", "@midnight", "@hourly"}
	cronNumeric   = regexp.MustCompile(`^[0-9*?/,-]+$`)
	cronNamed     = regexp.MustCompile(`^[0-9A-Za-z*?/,-]+$`)
	cronMonths    = []string{"JAN", "FEB", "MAR", "APR", "MAY", "JUN", "JUL", "AUG", "SEP", "OCT", "NOV", "DEC"}
	cronWeekdays  = []string{"SUN", "MON", "TUE", "WED", "THU", "FRI", "SAT"}
	cronFieldSpan = [5][2]int{{0, 59}, {0, 23}, {1, 31}, {1, 12}, {0, 7}}
)

// validCron is App\Rules\CronSchedule as far as it can be said here: a macro of the Kubernetes
// CronJob controller, or five fields (minute hour day-of-month month day-of-week) of numbers,
// ranges, steps and lists in their ranges, month and day names in the last two fields, no
// Quartz "L". What it is unsure about it accepts: the server checks again.
func validCron(expr string) bool {
	e := strings.TrimSpace(expr)
	if containsString(cronMacros, strings.ToLower(e)) {
		return true
	}
	fields := strings.Fields(e)
	if len(fields) != 5 {
		return false
	}
	for i, f := range fields {
		if i < 3 && !cronNumeric.MatchString(f) {
			return false
		}
		if i >= 3 && !cronNamed.MatchString(f) {
			return false
		}
		if i == 4 && strings.ContainsAny(f, "lL") {
			return false
		}
		for _, part := range strings.Split(f, ",") {
			if !cronPart(part, i) {
				return false
			}
		}
	}
	return true
}

// cronPart checks one list element of field i: *, ?, a value, a range, each with an optional step.
func cronPart(part string, field int) bool {
	if part == "" {
		return false
	}
	base, step, hasStep := strings.Cut(part, "/")
	if hasStep {
		n, err := strconv.Atoi(step)
		if err != nil || n < 0 {
			return false
		}
	}
	if base == "*" || base == "?" {
		return true
	}
	lo, hi, isRange := strings.Cut(base, "-")
	if _, ok := cronValue(lo, field); !ok {
		return false
	}
	if isRange {
		if _, ok := cronValue(hi, field); !ok {
			return false
		}
	}
	return true
}

func cronValue(v string, field int) (int, bool) {
	if n, err := strconv.Atoi(v); err == nil {
		return n, n >= cronFieldSpan[field][0] && n <= cronFieldSpan[field][1]
	}
	names := map[int][]string{3: cronMonths, 4: cronWeekdays}[field]
	for i, name := range names {
		if strings.EqualFold(name, v) {
			if field == 3 {
				return i + 1, true
			}
			return i, true
		}
	}
	return 0, false
}
