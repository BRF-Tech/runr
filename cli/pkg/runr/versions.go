package runr

import (
	"context"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"
)

// ListVersions returns a project's versions, newest first.
func (c *Client) ListVersions(ctx context.Context, projectID string) ([]Version, error) {
	var out struct {
		Data []Version `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/versions"}, &out); err != nil {
		return nil, err
	}
	return out.Data, nil
}

// GetVersion returns one version by id.
func (c *Client) GetVersion(ctx context.Context, projectID, versionID string) (*Version, error) {
	versions, err := c.ListVersions(ctx, projectID)
	if err != nil {
		return nil, err
	}
	for i := range versions {
		if versions[i].ID == versionID {
			return &versions[i], nil
		}
	}
	return nil, &NotFoundError{Kind: "version", Ref: versionID}
}

// FindVersion resolves "v3", "3", "latest" or a version id.
func (c *Client) FindVersion(ctx context.Context, projectID, ref string) (*Version, error) {
	versions, err := c.ListVersions(ctx, projectID)
	if err != nil {
		return nil, err
	}
	return PickVersion(versions, ref)
}

// PickVersion resolves ref in versions (see FindVersion).
func PickVersion(versions []Version, ref string) (*Version, error) {
	ref = strings.TrimSpace(ref)
	if ref == "" || strings.EqualFold(ref, "latest") {
		if len(versions) == 0 {
			return nil, &NotFoundError{Kind: "version", Ref: "latest"}
		}
		best := 0
		for i := range versions {
			if versions[i].Number > versions[best].Number {
				best = i
			}
		}
		return &versions[best], nil
	}
	if n, err := strconv.Atoi(strings.TrimPrefix(strings.ToLower(ref), "v")); err == nil {
		for i := range versions {
			if versions[i].Number == n {
				return &versions[i], nil
			}
		}
	}
	for i := range versions {
		if versions[i].ID == ref || versions[i].Label == ref {
			return &versions[i], nil
		}
	}
	return nil, &NotFoundError{Kind: "version", Ref: ref}
}

// VersionFiles returns the files of a version (code projects).
func (c *Client) VersionFiles(ctx context.Context, projectID, versionID string) (map[string]string, error) {
	var out struct {
		Files rawFiles `json:"files"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/versions/" + versionID + "/files"}, &out); err != nil {
		return nil, err
	}
	return decodeFileMap([]byte(out.Files))
}

type rawFiles []byte

func (r *rawFiles) UnmarshalJSON(b []byte) error {
	*r = append((*r)[:0], b...)
	return nil
}

// BuildError means a version's build failed or its build image is gone.
type BuildError struct {
	Version *Version
}

func (e *BuildError) Error() string {
	msg := e.Version.BuildError
	if msg == "" {
		msg = e.Version.BuildStatus
	}
	return fmt.Sprintf("build of %s failed: %s", e.Version.Label, msg)
}

// TimeoutError means a wait gave up.
type TimeoutError struct {
	What    string
	Timeout time.Duration
	Last    string
}

func (e *TimeoutError) Error() string {
	s := fmt.Sprintf("timed out after %s waiting for %s", e.Timeout, e.What)
	if e.Last != "" {
		s += " (last status: " + e.Last + ")"
	}
	return s
}

// WaitOptions control the polling of WaitVersion / WaitDeployment.
type WaitOptions struct {
	Timeout  time.Duration
	Interval time.Duration
	// Progress is called with every new status.
	Progress func(status string)
}

func (o WaitOptions) withDefaults() WaitOptions {
	if o.Timeout <= 0 {
		o.Timeout = 10 * time.Minute
	}
	if o.Interval <= 0 {
		o.Interval = 2 * time.Second
	}
	return o
}

// WaitVersion waits until a version's build is ready (compiled runtimes and functions with
// dependencies are built in the cluster).
func (c *Client) WaitVersion(ctx context.Context, projectID string, v *Version, opts WaitOptions) (*Version, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	last := ""
	cur := v
	for {
		if cur.BuildStatus == "" || cur.BuildStatus == BuildReady {
			return cur, nil
		}
		if cur.BuildStatus == BuildFailed || cur.BuildStatus == BuildPruned {
			return cur, &BuildError{Version: cur}
		}
		if cur.BuildStatus != last {
			last = cur.BuildStatus
			if opts.Progress != nil {
				opts.Progress(last)
			}
		}
		select {
		case <-ctx.Done():
			return cur, &TimeoutError{What: "the build of " + cur.Label, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
		next, err := c.GetVersion(ctx, projectID, cur.ID)
		if err != nil {
			if ctx.Err() != nil {
				return cur, &TimeoutError{What: "the build of " + cur.Label, Timeout: opts.Timeout, Last: last}
			}
			return cur, err
		}
		cur = next
	}
}
