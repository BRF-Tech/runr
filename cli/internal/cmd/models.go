package cmd

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/pkg/runr"
)

// runr models: the model library, Hugging Face Hub search, model services, offline copies,
// uploads into air-gapped regions and the models a project uses (docs/huggingface.md).

func init() { registerCommand(newModelsCmd) }

func newModelsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "models",
		Aliases: []string{"model"},
		Short:   "Hugging Face models, model services and the models a project uses",
		Long: "Search the Hugging Face Hub, keep models in the organization's library (pinned to a commit),\n" +
			"serve them as model services on CPU or GPU (online: from the Hub, offline: from a copy in the\n" +
			"region), upload models into air-gapped regions and bind model services to projects: a bound\n" +
			"project gets RUNR_MODEL_<ALIAS>_URL and friends on its next deploy.",
		Example: "  runr models search minilm --task sentence-similarity\n" +
			"  runr models deploy sentence-transformers/all-MiniLM-L6-v2 --region eu-1\n" +
			"  runr models invoke all-minilm-l6-v2 --data '{\"input\": [\"hello\"]}'\n" +
			"  runr models bind all-minilm-l6-v2 --alias EMBED -p orders-api",
	}
	ls := newModelsLsCmd(app)
	cmd.Args = cobra.NoArgs
	cmd.RunE = ls.RunE
	cmd.AddCommand(ls, newModelsGetCmd(app), newModelsSearchCmd(app), newModelsInfoCmd(app), newModelsAddCmd(app),
		newModelsRmCmd(app), newModelsEnginesCmd(app), newModelsHubTokenCmd(app), newModelsSnapshotCmd(app),
		newModelsSnapshotsCmd(app), newModelsUploadCmd(app), newModelsDeployCmd(app), newModelServicesCmd(app),
		newModelsInvokeCmd(app), newModelsBindCmd(app), newModelsUnbindCmd(app), newModelsBindingsCmd(app))
	addModelAdminCommands(app, cmd) // models_admin.go
	return cmd
}

// ---------------------------------------------------------------------------------------
// Library

func newModelsLsCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the models of the library",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListModels(cmd.Context())
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.AIModel{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("NAME", "SOURCE", "MODEL", "TASK", "SIZE", "OFFLINE COPIES", "SERVICES")
				for _, m := range list {
					t.Row(m.Name, m.Source, output.Dash(modelOrigin(&m)), output.Dash(m.Task), modelSize(int64(m.SizeBytes)),
						modelSnapshotsLabel(m.Snapshots), strconv.Itoa(m.ServicesCount))
				}
			})
		},
	}
}

func newModelsGetCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "get MODEL",
		Short: "Show a model of the library (name, id or Hub id)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			m, err := c.FindModel(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(m, func(t *output.TableWriter) { modelDetail(t, m) })
		},
	}
}

func modelDetail(t *output.TableWriter, m *runr.AIModel) {
	t.KV("Name", m.Name)
	t.KV("ID", m.ID)
	t.KV("Source", m.Source)
	if m.HubID != "" {
		rev := m.Revision
		if m.RevisionSHA != "" {
			rev += " (" + m.RevisionSHA + ")"
		}
		t.KV("Hub model", m.HubID)
		t.KV("Revision", rev)
	}
	if m.SourceRef != "" {
		t.KV("Import", m.SourceRef)
	}
	t.KV("Task", output.Dash(m.Task))
	t.KV("Library", output.Dash(m.Library))
	if m.License != "" {
		t.KV("License", m.License)
	}
	if m.Gated {
		t.KV("Gated", "yes (the organization's Hugging Face token must have access)")
	}
	t.KV("Size", modelSize(int64(m.SizeBytes)))
	if m.Parameters > 0 {
		t.KV("Parameters", strconv.FormatInt(int64(m.Parameters), 10))
	}
	t.KV("Services", strconv.Itoa(m.ServicesCount))
	if len(m.Snapshots) > 0 {
		t.Header("REGION", "STATUS", "REVISION", "SIZE", "MESSAGE")
		for _, s := range m.Snapshots {
			t.Row(output.Dash(s.RegionName), s.Status, output.Truncate(s.Revision, 12), modelSize(int64(s.SizeBytes)),
				output.Dash(output.Truncate(s.Message, 60)))
		}
	}
}

func modelOrigin(m *runr.AIModel) string {
	if m.HubID != "" {
		return m.HubID
	}
	return m.SourceRef
}

func modelSnapshotsLabel(list []runr.ModelSnapshot) string {
	if len(list) == 0 {
		return "-"
	}
	parts := make([]string, 0, len(list))
	for _, s := range list {
		region := s.RegionName
		if region == "" {
			region = s.RegionID
		}
		parts = append(parts, region+":"+s.Status)
	}
	return strings.Join(parts, ", ")
}

// modelSize renders a size with a binary unit ("-" for unknown).
func modelSize(n int64) string {
	if n <= 0 {
		return "-"
	}
	const unit = 1024
	if n < unit {
		return strconv.FormatInt(n, 10) + " B"
	}
	div, exp := int64(unit), 0
	for m := n / unit; m >= unit; m /= unit {
		div *= unit
		exp++
	}
	return fmt.Sprintf("%.1f %ciB", float64(n)/float64(div), "KMGTPE"[exp])
}

func newModelsSearchCmd(app *App) *cobra.Command {
	var s runr.HubSearch
	cmd := &cobra.Command{
		Use:   "search [QUERY]",
		Short: "Search the Hugging Face Hub",
		Example: "  runr models search llama --task text-generation --sort likes\n" +
			"  runr models search --library sentence-transformers --limit 5",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) == 1 {
				s.Search = args[0]
			}
			switch s.Sort {
			case "", "downloads", "likes", "trending", "updated":
			default:
				return usageErrorf("--sort must be downloads, likes, trending or updated")
			}
			if s.Limit < 0 || s.Limit > 50 {
				return usageErrorf("--limit must be between 1 and 50")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, next, err := c.SearchHub(cmd.Context(), s)
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.HubModel{}
			}
			return app.Printer().Print(map[string]any{"data": list, "next_cursor": next}, func(t *output.TableWriter) {
				t.Header("MODEL", "TASK", "LIBRARY", "DOWNLOADS", "LIKES", "GATED")
				for _, m := range list {
					t.Row(m.ID, output.Dash(m.Task), output.Dash(m.Library), strconv.FormatInt(m.Downloads, 10),
						strconv.FormatInt(m.Likes, 10), yesNo(m.Gated))
				}
				if next != "" {
					t.Note("More results: --cursor %s", next)
				}
			})
		},
	}
	f := cmd.Flags()
	f.StringVar(&s.Task, "task", "", "pipeline tag (text-generation, feature-extraction, text-classification, …)")
	f.StringVar(&s.Library, "library", "", "library (transformers, sentence-transformers, diffusers, …)")
	f.StringVar(&s.Sort, "sort", "", "downloads (default), likes, trending or updated")
	f.IntVar(&s.Limit, "limit", 0, "results per page (1-50, default 20)")
	f.StringVar(&s.Cursor, "cursor", "", "cursor of the next page (printed after the results)")
	return cmd
}

func newModelsInfoCmd(app *App) *cobra.Command {
	var revision, region string
	cmd := &cobra.Command{
		Use:   "info HF_ID",
		Short: "Show a Hub model with the engines that serve it and RunR's suggested settings",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			regionID := ""
			if region != "" {
				r, err := c.FindRegion(cmd.Context(), region)
				if err != nil {
					return err
				}
				regionID = r.ID
			}
			info, err := c.HubInfo(cmd.Context(), args[0], revision, regionID)
			if err != nil {
				return err
			}
			return app.Printer().Print(info, func(t *output.TableWriter) {
				t.KV("Model", info.ID)
				if info.SHA != "" {
					t.KV("Commit", info.SHA)
				}
				t.KV("Task", output.Dash(info.Task))
				t.KV("Library", output.Dash(info.Library))
				t.KV("License", output.Dash(info.License))
				t.KV("Gated", yesNo(info.Gated))
				t.KV("Size", modelSize(int64(info.SizeBytes)))
				if s := info.Suggested; s != nil {
					t.KV("Suggested", fmt.Sprintf("engine %v on %v, cpu %v, memory %v MiB", s["engine"], s["device"], s["cpu"], s["memory_mb"]))
				}
				for _, w := range info.Warnings {
					t.KV("Warning", modelWarning(w))
				}
				t.Header("ENGINE", "DEVICES", "API", "RECOMMENDED")
				for _, e := range info.Engines {
					t.Row(e.Engine, strings.Join(e.Devices, ", "), e.API, yesNo(e.Recommended))
				}
			})
		},
	}
	cmd.Flags().StringVar(&revision, "revision", "", "branch, tag or commit (default main)")
	cmd.Flags().StringVar(&region, "region", "", "size the suggestion for this region's GPUs")
	return cmd
}

func modelWarning(w string) string {
	switch w {
	case "gated":
		return "gated: the organization's token is used to download it"
	case "gated_needs_token":
		return "gated: accept the terms on the Hub and set a token (runr models hub-token set)"
	}
	return w
}

func newModelsAddCmd(app *App) *cobra.Command {
	var in runr.AddModelInput
	var region string
	cmd := &cobra.Command{
		Use:   "add [HF_ID]",
		Short: "Add a model to the library: a Hub model, an empty model to upload into, or an import",
		Long: "Without --source the model comes from the Hugging Face Hub, pinned to the commit --revision\n" +
			"(default main) points to. --source upload creates an empty model for `runr models upload`;\n" +
			"--source pvc (a folder of the region's model volume, --path) and --source oci (an OCI\n" +
			"artifact, --reference) import a model in --region; they need models.manage.",
		Example: "  runr models add sentence-transformers/all-MiniLM-L6-v2\n" +
			"  runr models add meta-llama/Llama-3.1-8B-Instruct --revision main --name llama-8b\n" +
			"  runr models add --source upload --name minilm --task feature-extraction\n" +
			"  runr models add --source pvc --path minilm --region eu-1 --name minilm --task feature-extraction",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if in.Source == "" {
				in.Source = runr.ModelSourceHub
			}
			switch in.Source {
			case runr.ModelSourceHub:
				if len(args) != 1 {
					return usageErrorf("give the Hub id of the model (organization/name)")
				}
				in.HubID = args[0]
			case runr.ModelSourceUpload, runr.ModelSourcePVC, runr.ModelSourceOCI:
				if len(args) == 1 {
					return usageErrorf("a Hub id applies to --source hub only")
				}
				if in.Task == "" {
					return usageErrorf("--source %s needs --task (the pipeline tag, e.g. feature-extraction)", in.Source)
				}
			default:
				return usageErrorf("--source must be hub, upload, pvc or oci")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			if in.Source == runr.ModelSourcePVC || in.Source == runr.ModelSourceOCI {
				r, err := c.FindRegion(cmd.Context(), region)
				if err != nil {
					return err
				}
				in.RegionID = r.ID
			}
			m, err := c.AddModel(cmd.Context(), in)
			if err != nil {
				return err
			}
			app.Progress("Added %s to the library.", m.Name)
			return app.Printer().Print(m, func(t *output.TableWriter) { modelDetail(t, m) })
		},
	}
	f := cmd.Flags()
	f.StringVar(&in.Source, "source", "", "hub (default), upload, pvc or oci")
	f.StringVar(&in.Revision, "revision", "", "Hub branch, tag or commit (default main)")
	f.StringVar(&in.Name, "name", "", "name in the library (default: from the Hub id)")
	f.StringVar(&in.Task, "task", "", "pipeline tag (upload, pvc, oci)")
	f.StringVar(&in.Library, "library", "", "library (transformers, sentence-transformers, …)")
	f.StringVar(&in.Path, "path", "", "folder under imports/ of the region's model volume (--source pvc)")
	f.StringVar(&in.Reference, "reference", "", "OCI artifact reference (--source oci)")
	f.StringVar(&region, "region", "", "region of an import (pvc, oci)")
	return cmd
}

func newModelsRmCmd(app *App) *cobra.Command {
	var yes bool
	cmd := &cobra.Command{
		Use:     "rm MODEL",
		Aliases: []string{"remove", "delete"},
		Short:   "Remove a model and its offline copies from the library",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			m, err := c.FindModel(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Remove the model "+m.Name+" and its offline copies", yes); err != nil {
				return err
			}
			if err := c.DeleteModel(cmd.Context(), m.ID); err != nil {
				return err
			}
			app.Progress("Removed %s.", m.Name)
			return nil
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

func newModelsEnginesCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "engines",
		Short: "List the serving engines and the tasks they serve",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			cat, err := c.ModelEngines(cmd.Context())
			if err != nil {
				return err
			}
			names := make([]string, 0, len(cat.Engines))
			for n := range cat.Engines {
				names = append(names, n)
			}
			sort.Strings(names)
			return app.Printer().Print(cat, func(t *output.TableWriter) {
				t.Header("ENGINE", "LABEL", "DEVICES", "API", "TASKS")
				for _, n := range names {
					e := cat.Engines[n]
					t.Row(n, e.Label, strings.Join(e.Devices, ", "), e.API, output.Truncate(strings.Join(e.Tasks, ", "), 60))
				}
			})
		},
	}
}

func newModelsHubTokenCmd(app *App) *cobra.Command {
	status := func(cmd *cobra.Command, args []string) error {
		c, err := app.Client()
		if err != nil {
			return err
		}
		st, err := c.HubToken(cmd.Context())
		if err != nil {
			return err
		}
		return printHubToken(app, st)
	}
	cmd := &cobra.Command{
		Use:   "hub-token",
		Short: "Show, set or remove the organization's Hugging Face token (gated and private models)",
		Args:  cobra.NoArgs,
		RunE:  status,
	}
	cmd.AddCommand(&cobra.Command{Use: "status", Short: "Show whether a token is set (never its value)", Args: cobra.NoArgs, RunE: status})

	var fromStdin, validate bool
	set := &cobra.Command{
		Use:   "set",
		Short: "Store the token (read from a prompt or --token-stdin, never from an argument)",
		Example: "  runr models hub-token set\n" +
			"  echo \"$HF_TOKEN\" | runr models hub-token set --token-stdin --validate",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			var token string
			switch {
			case fromStdin:
				line, err := app.readLine("")
				if err != nil {
					return fmt.Errorf("read the token from stdin: %w", err)
				}
				token = strings.TrimSpace(line)
			case app.interactive():
				t, err := app.ReadSecret("Hugging Face token: ")
				if err != nil {
					return err
				}
				token = strings.TrimSpace(t)
			default:
				return usageErrorf("pipe the token with --token-stdin")
			}
			if token == "" {
				return usageErrorf("no token given")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			st, err := c.SetHubToken(cmd.Context(), token, validate)
			if err != nil {
				return err
			}
			app.Progress("Stored the Hugging Face token; the regions get it in the background.")
			return printHubToken(app, st)
		},
	}
	set.Flags().BoolVar(&fromStdin, "token-stdin", false, "read the token from stdin")
	set.Flags().BoolVar(&validate, "validate", false, "check the token with the Hub first")
	cmd.AddCommand(set)

	var yes bool
	rm := &cobra.Command{
		Use:     "rm",
		Aliases: []string{"remove", "delete"},
		Short:   "Remove the token",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if err := app.confirm("Remove the organization's Hugging Face token", yes); err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			if err := c.DeleteHubToken(cmd.Context()); err != nil {
				return err
			}
			app.Progress("Removed the Hugging Face token.")
			return nil
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(rm)
	return cmd
}

func printHubToken(app *App, st *runr.HubTokenStatus) error {
	return app.Printer().Print(st, func(t *output.TableWriter) {
		t.KV("Configured", map[bool]string{true: "yes", false: "no"}[st.Configured])
		if st.Hint != "" {
			t.KV("Token", st.Hint)
		}
		if st.UpdatedAt != "" {
			t.KV("Updated", st.UpdatedAt)
		}
	})
}

// ---------------------------------------------------------------------------------------
// Offline copies and uploads

// modelWaitFlags are --wait / --no-wait / --timeout.
type modelWaitFlags struct {
	wait, noWait bool
	timeout      time.Duration
}

func (w *modelWaitFlags) register(f *pflag.FlagSet, what string, timeout time.Duration) {
	f.BoolVar(&w.wait, "wait", true, "wait until "+what+" (--wait=false or --no-wait returns at once)")
	f.BoolVar(&w.noWait, "no-wait", false, "return at once")
	f.DurationVar(&w.timeout, "timeout", timeout, "how long to wait")
}

func (w *modelWaitFlags) waiting() bool { return w.wait && !w.noWait }

func newModelsSnapshotCmd(app *App) *cobra.Command {
	var region string
	var w modelWaitFlags
	cmd := &cobra.Command{
		Use:   "snapshot MODEL",
		Short: "Make an offline copy of a Hub model in a region (closed circuit, air-gapped serving)",
		Long: "Mirrors the model's pinned commit into the region's model volume; offline model services\n" +
			"serve that copy without network access. A failed mirror runs again; a ready copy is kept.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			m, err := c.FindModel(ctx, args[0])
			if err != nil {
				return err
			}
			r, err := c.FindRegion(ctx, region)
			if err != nil {
				return err
			}
			s, err := c.CreateSnapshot(ctx, m.ID, r.ID)
			if err != nil {
				return err
			}
			app.Progress("Offline copy of %s in %s: %s", m.Name, r.Name, s.Status)
			if w.waiting() && s.Status != runr.SnapshotReady {
				s, err = c.WaitSnapshot(ctx, m.ID, s.ID, app.waitOptions(w.timeout, "Snapshot"))
				if s != nil {
					_ = printModelSnapshot(app, s)
				}
				return err
			}
			return printModelSnapshot(app, s)
		},
	}
	cmd.Flags().StringVar(&region, "region", "", "region (name or id; default: the only active region)")
	w.register(cmd.Flags(), "the copy is ready", time.Hour)
	return cmd
}

func printModelSnapshot(app *App, s *runr.ModelSnapshot) error {
	return app.Printer().Print(s, func(t *output.TableWriter) {
		t.KV("Snapshot", s.ID)
		t.KV("Region", output.Dash(s.RegionName))
		t.KV("Status", s.Status)
		if s.Message != "" {
			t.KV("Message", s.Message)
		}
		t.KV("Revision", output.Dash(s.Revision))
		t.KV("Size", modelSize(int64(s.SizeBytes)))
		if s.FilesCount > 0 {
			t.KV("Files", strconv.Itoa(s.FilesCount))
		}
	})
}

func newModelsSnapshotsCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "snapshots MODEL",
		Short: "List the offline copies of a model",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			m, err := c.FindModel(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			list, err := c.ListSnapshots(cmd.Context(), m.ID)
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.ModelSnapshot{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("ID", "REGION", "STATUS", "REVISION", "SIZE", "FILES", "COMPLETED")
				for _, s := range list {
					files := "-"
					if s.FilesCount > 0 {
						files = strconv.Itoa(s.FilesCount)
					}
					t.Row(s.ID, output.Dash(s.RegionName), s.Status, output.Truncate(output.Dash(s.Revision), 12),
						modelSize(int64(s.SizeBytes)), files, output.Dash(s.CompletedAt))
				}
			})
		},
	}
}

func newModelsUploadCmd(app *App) *cobra.Command {
	var modelRef, task, library, region, resume string
	var noChecksum bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "upload DIR",
		Short: "Upload a model folder into a region (air-gapped regions)",
		Long: "Uploads every file of DIR (subfolders included; .git and .cache are skipped) in chunks through\n" +
			"the RunR API into the region's model volume; the files become the model's offline copy there.\n" +
			"The model must have source upload: an unknown --model is created with --task. Every file's\n" +
			"sha256 is checked by the region (--no-checksum skips it). An upload that fails stays open for\n" +
			"24 hours: --resume ID continues it; Ctrl+C aborts it.",
		Example: "  runr models upload ./all-MiniLM-L6-v2 --model minilm --task feature-extraction --region eu-1\n" +
			"  runr models upload ./all-MiniLM-L6-v2 --model minilm --region eu-1 --resume 01J9ZKF…",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if modelRef == "" {
				return usageErrorf("--model is required (the model to upload into; created with --task when it does not exist)")
			}
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			files, skipped, err := runr.ScanModelDir(args[0], !noChecksum)
			if err != nil {
				return err
			}
			for _, p := range skipped {
				app.Warn("%s is empty and is not uploaded", p)
			}
			if len(files) == 0 {
				return usageErrorf("%s holds no files to upload", args[0])
			}
			r, err := c.FindRegion(ctx, region)
			if err != nil {
				return err
			}
			m, err := c.FindModel(ctx, modelRef)
			var nf *runr.NotFoundError
			switch {
			case errors.As(err, &nf):
				if task == "" {
					return usageErrorf("the model %s does not exist: give --task to create it", modelRef)
				}
				m, err = c.AddModel(ctx, runr.AddModelInput{Source: runr.ModelSourceUpload, Name: modelRef, Task: task, Library: library})
				if err != nil {
					return err
				}
				app.Progress("Created the model %s (source upload).", m.Name)
			case err != nil:
				return err
			}
			if m.Source != runr.ModelSourceUpload {
				return usageErrorf("%s is a %s model; files are uploaded into models of source upload", m.Name, m.Source)
			}
			var total int64
			for _, f := range files {
				total += f.Size
			}
			app.Progress("Uploading %s in %s (%s) into %s in %s", countLabel(len(files), "file"), modelSize(total),
				map[bool]string{true: "resuming " + resume, false: "new upload"}[resume != ""], m.Name, r.Name)

			lastPct := -10
			var uploadID string
			opts := runr.UploadOptions{
				ResumeID: resume,
				Ready:    app.waitOptions(timeout, "Receiver"),
				Started:  func(u *runr.ModelUpload) { uploadID = u.ID },
				Progress: func(p runr.UploadProgress) {
					pct := 100
					if p.Total > 0 {
						pct = int(p.Done * 100 / p.Total)
					}
					if p.FileBytes == p.FileSize {
						app.Progress("  %s (%s) uploaded, %d%%", p.File, modelSize(p.FileSize), pct)
						lastPct = pct
						return
					}
					if pct/10 != lastPct/10 {
						lastPct = pct
						app.Progress("  %s %s / %s, %d%%", p.File, modelSize(p.FileBytes), modelSize(p.FileSize), pct)
					}
				},
			}
			up, snap, err := c.UploadModel(ctx, m.ID, r.ID, files, opts)
			if err != nil {
				if uploadID == "" && up != nil {
					uploadID = up.ID
				}
				if ctx.Err() != nil && uploadID != "" {
					actx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
					defer cancel()
					if aerr := c.AbortUpload(actx, m.ID, uploadID); aerr == nil {
						app.Progress("Aborted the upload %s.", uploadID)
					}
					return errAborted
				}
				if uploadID != "" {
					app.Warn("the upload %s stays open for 24 hours: continue it with `runr models upload %s --model %s --region %s --resume %s`",
						uploadID, args[0], m.Name, r.Name, uploadID)
				}
				return err
			}
			app.Progress("Upload complete: the offline copy of %s in %s is %s.", m.Name, r.Name, snap.Status)
			return app.Printer().Print(map[string]any{"upload": up, "snapshot": snap}, func(t *output.TableWriter) {
				t.KV("Model", m.Name)
				t.KV("Region", r.Name)
				t.KV("Upload", up.ID)
				t.KV("Snapshot", snap.ID+" ("+snap.Status+")")
				t.KV("Files", strconv.Itoa(len(up.Files)))
				t.KV("Size", modelSize(total))
				t.Note("")
				t.Note("Serve it offline: runr models deploy %s --mode offline --region %s", m.Name, r.Name)
			})
		},
	}
	f := cmd.Flags()
	f.StringVar(&modelRef, "model", "", "model to upload into (name or id; created with --task when it does not exist)")
	f.StringVar(&task, "task", "", "pipeline tag of a model the upload creates (feature-extraction, text-generation, …)")
	f.StringVar(&library, "library", "", "library of a model the upload creates")
	f.StringVar(&region, "region", "", "region (name or id; default: the only active region)")
	f.StringVar(&resume, "resume", "", "continue the open upload with this id")
	f.BoolVar(&noChecksum, "no-checksum", false, "do not compute and check sha256 checksums")
	f.DurationVar(&timeout, "timeout", 10*time.Minute, "how long to wait for the region's receiver")
	return cmd
}

// ---------------------------------------------------------------------------------------
// Model services

// modelSettingsFlags are the settings flags of `models deploy` and `models services update`.
type modelSettingsFlags struct {
	mode, device, engine, cpu, image, healthPath, servedName, gpuType  string
	gpu, gpuMemory, memoryMB, minScale, maxScale, requestTimeout, port int
	scaleToZero                                                        bool
	args, env                                                          []string
}

func (m *modelSettingsFlags) register(f *pflag.FlagSet) {
	f.StringVar(&m.mode, "mode", "", "online (from the Hub, cached in the region) or offline (the region's copy, no egress)")
	f.StringVar(&m.device, "device", "", "cpu or gpu (default: RunR's pick)")
	f.StringVar(&m.engine, "engine", "", "vllm, tgi, tei, transformers, diffusers, custom or auto (default)")
	f.IntVar(&m.gpu, "gpu", 0, "number of GPUs (implies --device gpu)")
	f.StringVar(&m.gpuType, "gpu-type", "", "GPU product (node label), e.g. \"NVIDIA A100-SXM4-80GB\"")
	f.IntVar(&m.gpuMemory, "gpu-memory", 0, "least GPU memory per GPU in MiB")
	f.StringVar(&m.cpu, "cpu", "", "CPU (Kubernetes quantity, default: from the model size)")
	f.IntVar(&m.memoryMB, "memory-mb", 0, "memory in MiB (default: from the model size)")
	f.IntVar(&m.minScale, "min-scale", 0, "minimum instances (1 keeps one warm)")
	f.IntVar(&m.maxScale, "max-scale", 0, "maximum instances")
	f.BoolVar(&m.scaleToZero, "scale-to-zero", true, "scale to zero instances when idle")
	f.IntVar(&m.requestTimeout, "request-timeout", 0, "request timeout in seconds")
	f.StringVar(&m.image, "image", "", "engine image instead of the engine's default (a mirror, a custom image)")
	f.StringArrayVar(&m.args, "arg", nil, "argument appended to the engine's (repeatable)")
	f.StringArrayVarP(&m.env, "env", "e", nil, "environment variable KEY=VALUE (repeatable; replaces the service's set)")
	f.IntVar(&m.port, "port", 0, "port a custom image listens on")
	f.StringVar(&m.healthPath, "health-path", "", "readiness path of a custom image")
	f.StringVar(&m.servedName, "served-name", "", "model name of the OpenAI API (default: the model's name)")
}

// settings returns the settings whose flags were given.
func (m *modelSettingsFlags) settings(f *pflag.FlagSet) (runr.ModelServiceSettings, error) {
	var s runr.ModelServiceSettings
	str := func(name, v string) *string {
		if !f.Changed(name) {
			return nil
		}
		return &v
	}
	num := func(name string, v int) *int {
		if !f.Changed(name) {
			return nil
		}
		return &v
	}
	if f.Changed("mode") && m.mode != "online" && m.mode != "offline" {
		return s, usageErrorf("--mode must be online or offline")
	}
	if f.Changed("device") && m.device != "cpu" && m.device != "gpu" {
		return s, usageErrorf("--device must be cpu or gpu")
	}
	s.Mode, s.Device, s.Engine = str("mode", m.mode), str("device", m.device), str("engine", m.engine)
	if f.Changed("gpu") || f.Changed("gpu-type") || f.Changed("gpu-memory") {
		count := 1
		if f.Changed("gpu") {
			if m.gpu < 1 {
				return s, usageErrorf("--gpu must be at least 1")
			}
			count = m.gpu
		}
		s.GPU = &runr.GPURequest{Count: count, Type: m.gpuType, MemoryMin: m.gpuMemory}
		if s.Device == nil {
			gpu := "gpu"
			s.Device = &gpu
		} else if *s.Device != "gpu" {
			return s, usageErrorf("--gpu needs --device gpu")
		}
	}
	s.CPU = str("cpu", m.cpu)
	s.MemoryMB = num("memory-mb", m.memoryMB)
	s.MinScale = num("min-scale", m.minScale)
	s.MaxScale = num("max-scale", m.maxScale)
	if s.MinScale != nil && s.MaxScale != nil && *s.MinScale > *s.MaxScale {
		return s, usageErrorf("--min-scale must not be greater than --max-scale")
	}
	if f.Changed("scale-to-zero") {
		v := m.scaleToZero
		s.ScaleToZero = &v
	}
	s.TimeoutSeconds = num("request-timeout", m.requestTimeout)
	s.Image = str("image", m.image)
	if f.Changed("arg") {
		args := append([]string{}, m.args...)
		s.Args = &args
	}
	if f.Changed("env") {
		env, err := parseKeyValues("--env", m.env)
		if err != nil {
			return s, err
		}
		for k := range env {
			if err := runr.ValidateEnvName(k); err != nil {
				return s, usageErrorf("%v", err)
			}
		}
		s.EnvVars = &env
	}
	s.Port = num("port", m.port)
	s.HealthPath = str("health-path", m.healthPath)
	s.ServedName = str("served-name", m.servedName)
	return s, nil
}

func newModelsDeployCmd(app *App) *cobra.Command {
	var name, region, revision string
	var noStart bool
	var flags modelSettingsFlags
	var w modelWaitFlags
	cmd := &cobra.Command{
		Use:   "deploy MODEL",
		Short: "Serve a model: create a model service and deploy it",
		Long: "MODEL is a model of the library (name or id) or a Hugging Face Hub id, which is added to the\n" +
			"library pinned to the commit --revision points to. RunR picks the engine, device, size and\n" +
			"scaling unless flags set them. The command waits until the service is ready (exit code 6 when\n" +
			"it fails, 7 on --timeout).",
		Example: "  runr models deploy sentence-transformers/all-MiniLM-L6-v2 --region eu-1\n" +
			"  runr models deploy Qwen/Qwen2.5-0.5B-Instruct --gpu 1 --engine vllm --min-scale 1 --arg=--max-model-len --arg=8192\n" +
			"  runr models deploy minilm --mode offline --name minilm-offline",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			settings, err := flags.settings(cmd.Flags())
			if err != nil {
				return err
			}
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			r, err := c.FindRegion(ctx, region)
			if err != nil {
				return err
			}
			in := runr.CreateModelServiceInput{Name: name, RegionID: r.ID, ModelServiceSettings: settings}
			if noStart {
				f := false
				in.Deploy = &f
			}
			if strings.Contains(args[0], "/") && revision != "" {
				in.HubID, in.Revision = args[0], revision
			} else {
				m, err := c.FindModel(ctx, args[0])
				var nf *runr.NotFoundError
				switch {
				case err == nil:
					in.AIModelID = m.ID
				case errors.As(err, &nf) && strings.Contains(args[0], "/"):
					in.HubID, in.Revision = args[0], revision
				default:
					return err
				}
			}
			svc, err := c.CreateModelService(ctx, in)
			if err != nil {
				return err
			}
			app.Progress("Model service %s (%s on %s, %s) in %s: %s", svc.Name, svc.Engine, svc.Device, svc.Mode, r.Name, svc.Status)
			return finishModelService(ctx, app, c, svc, w)
		},
	}
	f := cmd.Flags()
	f.StringVar(&name, "name", "", "service name (default: from the model's name)")
	f.StringVar(&region, "region", "", "region (name or id; default: the only active region)")
	f.StringVar(&revision, "revision", "", "Hub branch, tag or commit of a Hub model (default main)")
	f.BoolVar(&noStart, "no-start", false, "create the service stopped (start it with `runr models services start`)")
	flags.register(f)
	w.register(f, "the service is ready", 30*time.Minute)
	return cmd
}

// finishModelService waits for a deploying service when asked to and prints it.
func finishModelService(ctx context.Context, app *App, c *runr.Client, svc *runr.ModelService, w modelWaitFlags) error {
	if w.waiting() && svc.Running() && svc.Status != runr.ModelServiceReady {
		ready, err := c.WaitModelService(ctx, svc.ID, app.waitOptions(w.timeout, "Model service "+svc.Name))
		if ready != nil {
			svc = ready
		}
		if err != nil {
			_ = printModelService(app, svc)
			return err
		}
	}
	return printModelService(app, svc)
}

func printModelService(app *App, s *runr.ModelService) error {
	return app.Printer().Print(s, func(t *output.TableWriter) {
		t.KV("Name", s.Name)
		t.KV("ID", s.ID)
		t.KV("Model", output.Dash(s.ModelName()))
		t.KV("Region", output.Dash(s.RegionName))
		status := s.Status
		if s.StatusMessage != "" {
			status += " (" + s.StatusMessage + ")"
		}
		t.KV("Status", status)
		t.KV("Engine", fmt.Sprintf("%s on %s, %s mode, %s API", s.Engine, s.Device, s.Mode, output.Dash(s.API)))
		if s.GPU != nil {
			gpu := strconv.Itoa(s.GPU.Count)
			if s.GPU.Type != "" {
				gpu += " × " + s.GPU.Type
			}
			if s.GPU.MemoryMin > 0 {
				gpu += fmt.Sprintf(" (≥ %d MiB)", s.GPU.MemoryMin)
			}
			t.KV("GPU", gpu)
		}
		t.KV("Resources", fmt.Sprintf("cpu %s, memory %d MiB, timeout %ds", output.Dash(s.CPU), s.MemoryMB, s.TimeoutSeconds))
		scale := fmt.Sprintf("%d-%d", s.MinScale, s.MaxScale)
		if s.ScaleToZero {
			scale += ", scales to zero"
		}
		t.KV("Scale", scale)
		if s.Image != "" {
			t.KV("Image", s.Image)
		}
		t.KV("URL", output.Dash(s.URL))
		if s.OpenAIBase != "" {
			t.KV("OpenAI base", s.OpenAIBase)
		}
		if s.ServedName != "" {
			t.KV("Served name", s.ServedName)
		}
		if s.Playground != nil {
			t.KV("Invoke", "runr models invoke "+s.Name+" --path "+s.Playground.Path+" --data '…'")
		}
		t.KV("Bindings", strconv.Itoa(s.BindingsCount))
	})
}

func newModelServicesCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "services",
		Aliases: []string{"service", "svc"},
		Short:   "List and manage model services",
	}
	var region string
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List model services",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			regionID := ""
			if region != "" {
				r, err := c.FindRegion(cmd.Context(), region)
				if err != nil {
					return err
				}
				regionID = r.ID
			}
			list, err := c.ListModelServices(cmd.Context(), regionID)
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.ModelService{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("NAME", "MODEL", "REGION", "ENGINE", "DEVICE", "MODE", "STATUS", "URL")
				for _, s := range list {
					t.Row(s.Name, output.Dash(s.ModelName()), output.Dash(s.RegionName), s.Engine, s.Device, s.Mode, s.Status, output.Dash(s.URL))
				}
			})
		},
	}
	ls.Flags().StringVar(&region, "region", "", "only the services of this region")
	cmd.Args = cobra.NoArgs
	cmd.RunE = ls.RunE
	cmd.Flags().AddFlagSet(ls.Flags())
	cmd.AddCommand(ls)

	cmd.AddCommand(&cobra.Command{
		Use:   "get SERVICE",
		Short: "Show a model service",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, svc, err := findModelService(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			if svc.Model == nil {
				if full, err := c.GetModelService(cmd.Context(), svc.ID); err == nil {
					svc = full
				}
			}
			return printModelService(app, svc)
		},
	})

	var startWait modelWaitFlags
	start := &cobra.Command{
		Use:     "start SERVICE",
		Aliases: []string{"deploy"},
		Short:   "Deploy a stopped (or failed) model service",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, svc, err := findModelService(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			svc, err = c.DeployModelService(cmd.Context(), svc.ID)
			if err != nil {
				return err
			}
			app.Progress("Model service %s: %s", svc.Name, svc.Status)
			return finishModelService(cmd.Context(), app, c, svc, startWait)
		},
	}
	startWait.register(start.Flags(), "the service is ready", 30*time.Minute)
	cmd.AddCommand(start)

	cmd.AddCommand(&cobra.Command{
		Use:   "stop SERVICE",
		Short: "Stop a model service (its settings stay; start it again with `start`)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, svc, err := findModelService(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			svc, err = c.StopModelService(cmd.Context(), svc.ID)
			if err != nil {
				return err
			}
			app.Progress("Model service %s: %s", svc.Name, svc.Status)
			return printModelService(app, svc)
		},
	})

	var flags modelSettingsFlags
	var updWait modelWaitFlags
	update := &cobra.Command{
		Use:   "update SERVICE",
		Short: "Change settings of a model service; a running service is redeployed with them",
		Example: "  runr models services update minilm --min-scale 1\n" +
			"  runr models services update chat --gpu 2 --gpu-memory 40000",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			settings, err := flags.settings(cmd.Flags())
			if err != nil {
				return err
			}
			if settings.Empty() {
				return usageErrorf("give at least one setting to change (see --help)")
			}
			c, svc, err := findModelService(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			svc, err = c.UpdateModelService(cmd.Context(), svc.ID, settings)
			if err != nil {
				return err
			}
			app.Progress("Updated %s: %s", svc.Name, svc.Status)
			return finishModelService(cmd.Context(), app, c, svc, updWait)
		},
	}
	flags.register(update.Flags())
	updWait.register(update.Flags(), "a redeployed service is ready", 30*time.Minute)
	cmd.AddCommand(update)

	var tail int
	logs := &cobra.Command{
		Use:   "logs SERVICE",
		Short: "Print the last log lines of the engine container of up to five pods",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, svc, err := findModelService(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			pods, err := c.ModelServiceLogs(cmd.Context(), svc.ID, tail)
			if err != nil {
				return err
			}
			if pods == nil {
				pods = []runr.ModelServicePod{}
			}
			if app.Printer().Machine() {
				return app.Printer().Print(map[string]any{"pods": pods}, nil)
			}
			if len(pods) == 0 {
				app.Progress("%s has no pods (status %s).", svc.Name, svc.Status)
				return nil
			}
			for _, p := range pods {
				if len(pods) > 1 {
					fmt.Fprintf(app.Out, "==> %s (%s, ready: %s) <==\n", p.Name, p.Phase, strconv.FormatBool(p.Ready))
				}
				writeLogs(app, p.Logs)
			}
			return nil
		},
	}
	logs.Flags().IntVar(&tail, "tail", 200, "lines per pod (10-2000)")
	cmd.AddCommand(logs)

	cmd.AddCommand(&cobra.Command{
		Use:   "bindings SERVICE",
		Short: "List the projects that use a model service",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, svc, err := findModelService(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			list, err := c.ModelServiceBindings(cmd.Context(), svc.ID)
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.ModelBinding{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("PROJECT", "ALIAS", "MOUNT WEIGHTS")
				for _, b := range list {
					project := b.ProjectID
					if b.Project != nil && b.Project.Name != "" {
						project = b.Project.Name
					}
					t.Row(project, b.Alias, yesNo(b.MountWeights))
				}
			})
		},
	})

	var yes bool
	rm := &cobra.Command{
		Use:     "rm SERVICE",
		Aliases: []string{"remove", "delete"},
		Short:   "Tear a model service down and delete it",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, svc, err := findModelService(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Delete the model service "+svc.Name, yes); err != nil {
				return err
			}
			if err := c.DeleteModelService(cmd.Context(), svc.ID); err != nil {
				return err
			}
			app.Progress("Deleted %s.", svc.Name)
			return nil
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(rm, newModelServiceSecurityCmd(app))
	return cmd
}

func findModelService(ctx context.Context, app *App, ref string) (*runr.Client, *runr.ModelService, error) {
	c, err := app.Client()
	if err != nil {
		return nil, nil, err
	}
	svc, err := c.FindModelService(ctx, ref)
	if err != nil {
		return nil, nil, err
	}
	return c, svc, nil
}

func newModelsInvokeCmd(app *App) *cobra.Command {
	var path, method, data string
	cmd := &cobra.Command{
		Use:   "invoke SERVICE",
		Short: "Send one request to a model service (it wakes a service scaled to zero)",
		Long: "Sends the request through the RunR API to the service and prints its answer. --path is one of\n" +
			"the engine's paths (default: the service's playground path: /v1/chat/completions,\n" +
			"/v1/embeddings, /predict, …); --data is the JSON body, inline, @file.json or - for stdin.",
		Example: "  runr models invoke minilm --data '{\"model\": \"minilm\", \"input\": [\"hello\"]}'\n" +
			"  runr models invoke chat --path /v1/chat/completions --data @request.json\n" +
			"  runr models invoke chat --path /v1/models --method GET",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			method = strings.ToUpper(method)
			if method != "" && method != http.MethodGet && method != http.MethodPost {
				return usageErrorf("--method must be GET or POST")
			}
			body, err := readJSONArg(app, "--data", data)
			if err != nil {
				return err
			}
			c, svc, err := findModelService(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			if path == "" {
				path = "/predict"
				if svc.Playground != nil && svc.Playground.Path != "" {
					path = svc.Playground.Path
				}
			}
			res, err := c.InvokeModelService(cmd.Context(), svc.ID, runr.InvokeInput{Path: path, Method: method, Body: body})
			if err != nil {
				return err
			}
			if app.Printer().Machine() {
				if err := app.Printer().Print(res, nil); err != nil {
					return err
				}
			} else {
				app.Progress("%d %s (%d ms)", res.Status, output.Dash(res.ContentType), res.DurationMS)
				fmt.Fprintln(app.Out, res.BodyText())
			}
			if res.Status >= 400 {
				return fmt.Errorf("the model service answered %d", res.Status)
			}
			return nil
		},
	}
	f := cmd.Flags()
	f.StringVar(&path, "path", "", "request path (default: the service's playground path)")
	f.StringVarP(&method, "method", "X", "", "POST (default) or GET")
	f.StringVarP(&data, "data", "d", "", "JSON body, @file.json or - (stdin)")
	return cmd
}

// ---------------------------------------------------------------------------------------
// Models of a project

func newModelsBindCmd(app *App) *cobra.Command {
	var alias string
	var mountWeights bool
	cmd := &cobra.Command{
		Use:   "bind SERVICE",
		Short: "Bind a model service to the project (RUNR_MODEL_<ALIAS>_* on its next deploy)",
		Long: "Gives the project's containers RUNR_MODEL_<ALIAS>_URL, _MODEL, _API, _TASK, _OPENAI_BASE (and\n" +
			"_PATH with --mount-weights) on the next deploy. The service must run in the project's region;\n" +
			"--mount-weights needs a ready offline copy of the model there.",
		Example: "  runr models bind minilm --alias EMBED -p orders-api\n" +
			"  runr models bind minilm --mount-weights",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			svc, err := c.FindModelService(ctx, args[0])
			if err != nil {
				return err
			}
			in := runr.BindModelInput{ServiceID: svc.ID, Alias: strings.ToUpper(alias)}
			if cmd.Flags().Changed("mount-weights") {
				in.MountWeights = &mountWeights
			}
			b, err := c.BindModel(ctx, p.ID, in)
			if err != nil {
				return err
			}
			app.Progress("Bound %s to %s as %s; the variables apply on the next deploy (runr deploy).", svc.Name, p.Name, b.Alias)
			return printModelBinding(app, b)
		},
	}
	cmd.Flags().StringVar(&alias, "alias", "", "alias of the variables (A-Z, 0-9, _; default: from the service name)")
	cmd.Flags().BoolVar(&mountWeights, "mount-weights", false, "mount the model's offline copy read-only (RUNR_MODEL_<ALIAS>_PATH)")
	return cmd
}

func printModelBinding(app *App, b *runr.ModelBinding) error {
	return app.Printer().Print(b, func(t *output.TableWriter) {
		t.KV("Alias", b.Alias)
		if b.Service != nil {
			t.KV("Service", b.Service.Name)
		}
		t.KV("Mount weights", map[bool]string{true: "yes", false: "no"}[b.MountWeights])
		if len(b.Env) > 0 {
			t.Header("VARIABLE", "VALUE")
			for _, k := range b.Env.Keys() {
				t.Row(k, b.Env[k])
			}
		}
	})
}

func newModelsUnbindCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "unbind SERVICE|ALIAS",
		Short: "Remove a model binding from the project (the variables go with its next deploy)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			list, _, err := c.ProjectModels(ctx, p.ID)
			if err != nil {
				return err
			}
			b, err := runr.FindModelBinding(list, args[0])
			if err != nil {
				return err
			}
			if err := c.UnbindModel(ctx, p.ID, b.ID); err != nil {
				return err
			}
			app.Progress("Removed the binding %s from %s; the variables go with the next deploy.", b.Alias, p.Name)
			return nil
		},
	}
}

func newModelsBindingsCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "bindings",
		Short: "List the model services the project uses and those of its region it can bind",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			list, avail, err := c.ProjectModels(ctx, p.ID)
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.ModelBinding{}
			}
			if avail == nil {
				avail = []runr.ModelServiceRef{}
			}
			return app.Printer().Print(map[string]any{"bindings": list, "available": avail}, func(t *output.TableWriter) {
				t.Header("ALIAS", "SERVICE", "STATUS", "MOUNT WEIGHTS", "URL")
				for _, b := range list {
					name, status, url := b.AIModelServiceID, "-", "-"
					if b.Service != nil {
						name, status, url = b.Service.Name, b.Service.Status, output.Dash(b.Service.URL)
					}
					t.Row(b.Alias, name, status, yesNo(b.MountWeights), url)
				}
				var unbound []string
				for _, a := range avail {
					bound := false
					for _, b := range list {
						bound = bound || b.AIModelServiceID == a.ID
					}
					if !bound {
						unbound = append(unbound, a.Name)
					}
				}
				if len(unbound) > 0 {
					t.Note("Model services of the region to bind: %s (runr models bind SERVICE)", strings.Join(unbound, ", "))
				}
			})
		},
	}
}
