package dev

import (
	"regexp"
	"strings"
)

// Placeholders of runtime manifest strings (api Placeholders): {version}, {image}, {file},
// {handler}, {Handler}, {mode}, {trigger} and {semver} (the version when it is a plain X.Y or
// X.Y.Z number, else empty). {if:NAME}…{end} keeps its content only when NAME is non-empty.
// Anything else in braces stays as written.

var (
	placeholderNames = "version|image|file|handler|Handler|mode|trigger|semver"
	ifPlaceholder    = regexp.MustCompile(`(?s)\{if:(` + placeholderNames + `)\}(.*?)\{end\}`)
	placeholder      = regexp.MustCompile(`\{(` + placeholderNames + `)\}`)
	semverPattern    = regexp.MustCompile(`^\d+\.\d+(\.\d+)?$`)
)

// renderPlaceholders resolves the placeholders of a manifest string.
func renderPlaceholders(text string, values map[string]string) string {
	v := map[string]string{}
	for k, x := range values {
		v[k] = x
	}
	if _, ok := v["semver"]; !ok {
		if semverPattern.MatchString(v["version"]) {
			v["semver"] = v["version"]
		} else {
			v["semver"] = ""
		}
	}
	if _, ok := v["Handler"]; !ok && v["handler"] != "" {
		v["Handler"] = strings.ToUpper(v["handler"][:1]) + v["handler"][1:]
	}
	text = ifPlaceholder.ReplaceAllStringFunc(text, func(m string) string {
		sub := ifPlaceholder.FindStringSubmatch(m)
		if v[sub[1]] != "" {
			return sub[2]
		}
		return ""
	})
	return placeholder.ReplaceAllStringFunc(text, func(m string) string {
		return v[m[1:len(m)-1]]
	})
}
