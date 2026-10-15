// Package scaffold writes the starter of a new project directory (`runr init`).
package scaffold

import (
	"bytes"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"text/template"

	"github.com/runrsh/core/cli/internal/projectfile"
	"github.com/runrsh/core/cli/internal/runtimes"
	"github.com/runrsh/core/cli/pkg/runr"
)

// Options of a scaffold.
type Options struct {
	Dir            string
	Name           string
	Type           string
	Runtime        string
	RuntimeVersion string
	Region         string
	// Image of a caas project.
	Image string
	// Force overwrites existing files.
	Force bool
	// Template holds the starter files of a runtime plugin (GET /runtimes/{id}/template);
	// built-in runtimes use their own starter.
	Template map[string]string
	// Filename is the script of an xaas project of a runtime plugin (catalog handler.filename).
	Filename string
	// Handler is the file.function of a faas project (default main.handler; runtime plugins:
	// the catalog's handler.file and handler.function).
	Handler string
}

// DefaultCaaSImage is the starter image of a caas project.
const DefaultCaaSImage = "nginx:1.27-alpine"

var projectTemplate = template.Must(template.New("runr.yaml").Parse(`# RunR project file. Reference: docs/cli.md#runryaml
name: {{.Name}}
type: {{.Type}}            # faas (function) | xaas (single-file script) | caas (container)
{{- if .Runtime}}
runtime: {{.Runtime}}
{{- if .RuntimeVersion}}
runtime_version: "{{.RuntimeVersion}}"
{{- else}}
# runtime_version: ""     # default: the platform's default version
{{- end}}
{{- end}}
{{- if .Region}}
region: {{.Region}}
{{- else}}
# region: eu-1            # region name or id (optional when one region is available)
{{- end}}
{{- if eq .Type "faas"}}
handler: {{.Handler}}     # file.function
{{- end}}
{{- if eq .Type "xaas"}}
file: {{.MainFile}}
handler: handler
{{- end}}
{{- if eq .Type "caas"}}
image: {{.Image}}
ports: [80]
{{- end}}

resources:
  cpu: 100m
  memory_mb: {{if eq .Type "caas"}}256{{else}}128{{end}}
  timeout_seconds: 30

scale:
  min: 0
  max: 5
  to_zero: true

trigger:
  type: http              # http | schedule | queue
  # schedule: "*/5 * * * *"   # type schedule: cron expression
  # queue: orders             # type queue: Redis stream name

env:                      # non-secret variables; secrets: runr env set --secret KEY=value
  GREETING: Hello

# ignore: ["*.md", "tests"]  # extra files not uploaded (.git, node_modules, … are never)
# dev:
#   port: 8080
`))

// Write creates the project directory content and returns the files written.
func Write(o Options) ([]string, error) {
	if o.Dir == "" {
		o.Dir = "."
	}
	if o.Name == "" {
		abs, err := filepath.Abs(o.Dir)
		if err != nil {
			return nil, err
		}
		o.Name = projectfile.Slug(filepath.Base(abs))
	}
	if err := projectfile.ValidateName(o.Name); err != nil {
		return nil, err
	}
	if o.Type == "" {
		o.Type = runr.TypeFaaS
	}
	switch o.Type {
	case runr.TypeFaaS, runr.TypeXaaS:
		if o.Runtime == "" {
			o.Runtime = "python"
		}
		if !runtimes.Known(o.Runtime) && o.Template == nil {
			return nil, fmt.Errorf("unknown runtime %q (built-in: %s)", o.Runtime, strings.Join(runtimes.IDs(), ", "))
		}
	case runr.TypeCaaS:
		o.Runtime = ""
		if o.Image == "" {
			o.Image = DefaultCaaSImage
		}
	default:
		return nil, fmt.Errorf("unknown type %q (faas, xaas or caas)", o.Type)
	}

	handler := o.Handler
	if handler == "" {
		handler = "main.handler"
	}
	pf := &projectfile.File{Name: o.Name, Type: o.Type, Runtime: o.Runtime, Handler: handler}
	mainFile := pf.MainFile()
	if o.Type == runr.TypeXaaS && o.Filename != "" {
		mainFile = o.Filename
	}
	data := struct {
		Options
		Handler  string
		MainFile string
	}{Options: o, Handler: handler, MainFile: mainFile}

	var yamlBuf bytes.Buffer
	if err := projectTemplate.Execute(&yamlBuf, data); err != nil {
		return nil, err
	}
	// The template must stay a valid project file.
	if _, err := projectfile.Parse(yamlBuf.Bytes()); err != nil {
		return nil, fmt.Errorf("scaffold produced an invalid runr.yaml: %w", err)
	}

	files := map[string]string{projectfile.FileName: yamlBuf.String()}
	order := []string{projectfile.FileName}
	switch {
	case o.Type == runr.TypeCaaS:
	case o.Template != nil:
		names := make([]string, 0, len(o.Template))
		for n := range o.Template {
			names = append(names, n)
		}
		sort.Strings(names)
		for _, n := range names {
			if strings.ContainsAny(n, `/\`) || n == projectfile.FileName {
				continue
			}
			files[n] = o.Template[n]
			order = append(order, n)
		}
	default:
		files[mainFile] = runtimes.Starter(o.Runtime)
		order = append(order, mainFile)
	}
	order = append(order, ".gitignore")
	files[".gitignore"] = ".runr/\n.env\n" + map[string]string{
		"python": "__pycache__/\n*.pyc\n.venv/\n", "tensorflow": "__pycache__/\n", "pytorch": "__pycache__/\n",
		"nodejs": "node_modules/\n", "php": "vendor/\n",
	}[o.Runtime]

	if err := os.MkdirAll(o.Dir, 0o755); err != nil {
		return nil, err
	}
	var written []string
	for _, name := range order {
		content, ok := files[name]
		if !ok {
			continue
		}
		p := filepath.Join(o.Dir, name)
		if _, err := os.Stat(p); err == nil && !o.Force {
			if name == ".gitignore" {
				continue
			}
			return written, fmt.Errorf("%s exists (use --force to overwrite)", p)
		}
		if err := os.WriteFile(p, []byte(content), 0o644); err != nil {
			return written, err
		}
		written = append(written, p)
	}
	return written, nil
}
