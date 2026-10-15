// Package version holds the build information, set by -ldflags at release time.
package version

import "runtime/debug"

var (
	// Version of the CLI (goreleaser: {{.Version}}).
	Version = "dev"
	// Commit the binary was built from.
	Commit = ""
	// Date the binary was built.
	Date = ""
)

func init() {
	if Commit != "" {
		return
	}
	if info, ok := debug.ReadBuildInfo(); ok {
		for _, s := range info.Settings {
			if s.Key == "vcs.revision" && len(s.Value) >= 7 {
				Commit = s.Value[:7]
			}
		}
	}
}
