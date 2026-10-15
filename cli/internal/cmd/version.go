package cmd

import (
	"context"
	"encoding/json"
	"net/http"
	"runtime"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/internal/version"
)

// VersionInfo is the output of runr version.
type VersionInfo struct {
	Client    string `json:"client"`
	Commit    string `json:"commit,omitempty"`
	BuildDate string `json:"build_date,omitempty"`
	Platform  string `json:"platform"`
	Server    string `json:"server,omitempty"`
	ServerErr string `json:"server_error,omitempty"`
	Latest    string `json:"latest_release,omitempty"`
}

func newVersionCmd(app *App) *cobra.Command {
	var clientOnly, checkUpdate bool
	cmd := &cobra.Command{
		Use:   "version",
		Short: "Print the CLI and API versions",
		Long: "Print the CLI version and, when logged in, the API version. --check-update looks up\n" +
			"the latest release on GitHub (also when update_check: true is set in the config file);\n" +
			"the CLI never contacts GitHub otherwise.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			info := VersionInfo{Client: version.Version, Commit: version.Commit, BuildDate: version.Date,
				Platform: runtime.GOOS + "/" + runtime.GOARCH}
			if !clientOnly {
				if c, err := app.Client(); err == nil {
					ctx, cancel := context.WithTimeout(cmd.Context(), 5*time.Second)
					v, err := c.ServerVersion(ctx)
					cancel()
					if err != nil {
						info.ServerErr = err.Error()
					}
					info.Server = v
				}
			}
			if !checkUpdate {
				if cfg, err := app.Config(); err == nil && cfg.UpdateCheck {
					checkUpdate = true
				}
			}
			if checkUpdate {
				info.Latest = latestRelease(cmd.Context(), app)
			}
			return app.Printer().Print(info, func(t *output.TableWriter) {
				client := info.Client
				if info.Commit != "" {
					client += " (" + info.Commit + ")"
				}
				t.KV("Client", client+" "+info.Platform)
				if info.Server != "" {
					t.KV("Server", info.Server)
				} else if info.ServerErr != "" {
					t.KV("Server", "unreachable: "+info.ServerErr)
				}
				if info.Latest != "" {
					t.KV("Latest release", info.Latest)
					if strings.TrimPrefix(info.Latest, "v") != strings.TrimPrefix(info.Client, "v") {
						t.Note("A newer runr is available: %s", info.Latest)
					}
				}
			})
		},
	}
	cmd.Flags().BoolVar(&clientOnly, "client", false, "print only the CLI version")
	cmd.Flags().BoolVar(&checkUpdate, "check-update", false, "look up the latest release on GitHub")
	return cmd
}

func latestRelease(ctx context.Context, app *App) string {
	if app.ReleaseURL == "" {
		return ""
	}
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, app.ReleaseURL, nil)
	if err != nil {
		return ""
	}
	req.Header.Set("Accept", "application/vnd.github+json")
	hc := app.HTTPClient
	if hc == nil {
		hc = http.DefaultClient
	}
	resp, err := hc.Do(req)
	if err != nil {
		return ""
	}
	defer resp.Body.Close()
	var rel struct {
		TagName string `json:"tag_name"`
	}
	if resp.StatusCode != 200 || json.NewDecoder(resp.Body).Decode(&rel) != nil {
		return ""
	}
	return rel.TagName
}
