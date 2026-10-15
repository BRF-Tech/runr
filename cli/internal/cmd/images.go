package cmd

import (
	"fmt"
	"strings"

	"github.com/spf13/cobra"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/pkg/runr"
)

func newImagesCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "images",
		Aliases: []string{"image"},
		Short:   "Upload container images and WebAssembly modules into a region registry",
		Long: "Uploaded images (docs/images.md) are docker save archives, OCI image-layout tars (gzip'ed or\n" +
			"not, multi-platform included) or .wasm modules, sent through RunR into a region's registry — the\n" +
			"way regions without internet access get images. CaaS projects run them, runtime versions\n" +
			"name them (upload:), wasm-exec functions package uploaded modules (wasm.upload).",
	}
	cmd.AddCommand(newImagesPushCmd(app), newImagesLsCmd(app), newImagesGetCmd(app), newImagesRmCmd(app),
		newImagesUploadsCmd(app))
	return cmd
}

func newImagesUploadsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "uploads",
		Short: "Unfinished uploads: list them, or abort one",
		Long: "Uploads that have not finished: still receiving chunks, or being verified and pushed by the\n" +
			"region. `runr images push` of the same file resumes one; `uploads rm` aborts it and frees\n" +
			"the region's receiver.",
		Example: "  runr images uploads\n" +
			"  runr images uploads rm 01J… --yes",
		Args: cobra.NoArgs,
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List unfinished uploads",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListImageUploads(cmd.Context())
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.ImageUpload{}
			}
			regions := map[string]string{}
			if rs, err := c.ListRegions(cmd.Context()); err == nil {
				for _, r := range rs {
					regions[r.ID] = r.Name
				}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("ID", "NAME", "REGION", "FILE", "RECEIVED", "STATUS")
				for _, u := range list {
					region := regions[u.RegionID]
					if region == "" {
						region = u.RegionID
					}
					received := bytesText(u.UploadedBytes) + " / " + bytesText(u.SizeBytes)
					t.Row(u.ID, output.Dash(u.Name), output.Dash(region), output.Dash(u.Filename), received, u.Status)
				}
				if len(list) == 0 {
					t.Note("No unfinished uploads.")
				}
			})
		},
	}
	var yes bool
	rm := &cobra.Command{
		Use:     "rm ID...",
		Aliases: []string{"abort", "delete"},
		Short:   "Abort unfinished uploads",
		Args:    cobra.MinimumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if err := app.confirm("Abort "+countLabel(len(args), "upload"), yes); err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			for _, id := range args {
				if err := c.AbortImageUpload(cmd.Context(), id); err != nil {
					return err
				}
				app.Progress("Aborted upload %s.", id)
			}
			return app.Printer().Print(map[string]any{"aborted": args}, func(*output.TableWriter) {})
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.RunE = ls.RunE
	cmd.AddCommand(ls, rm)
	return cmd
}

func newImagesGetCmd(app *App) *cobra.Command {
	var region string
	get := &cobra.Command{
		Use:   "get <name|id>",
		Short: "Show an uploaded image: references, digest, platforms, layers, scan",
		Long: "Show an uploaded image (repository:tag — :latest when the tag is left out — or its id).\n" +
			"image_ref pins the digest in the region registry: what a CaaS project or a runtime version\n" +
			"runs; tag_ref moves when the name is uploaded again.",
		Example: "  runr images get myapp:1.0 --region eu-1\n" +
			"  runr images get myapp:1.0 -o json | jq -r .image_ref",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			regionID := ""
			if region != "" {
				reg, err := c.FindRegion(ctx, region)
				if err != nil {
					return err
				}
				regionID = reg.ID
			}
			list, _, err := c.ListImages(ctx, regionID, "")
			if err != nil {
				return err
			}
			found, err := runr.FindImage(list, args[0], regionID)
			if err != nil {
				return err
			}
			img, err := c.GetImage(ctx, found.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(img, func(t *output.TableWriter) {
				t.KV("Name", img.Name)
				t.KV("ID", img.ID)
				t.KV("Region", output.Dash(img.RegionName))
				t.KV("Kind", fmt.Sprintf("%s (from %s)", img.Kind, output.Dash(img.SourceFormat)))
				t.KV("Image ref", output.Dash(img.ImageRef))
				t.KV("Tag ref", output.Dash(img.TagRef))
				t.KV("Digest", output.Dash(img.Digest))
				t.KV("Media type", output.Dash(img.MediaType))
				t.KV("Platforms", output.Dash(strings.Join(img.Platforms, ", ")))
				if img.Layers > 0 {
					t.KV("Layers", fmt.Sprint(img.Layers))
				}
				t.KV("Size", bytesText(img.SizeBytes))
				if img.ArchiveBytes > 0 {
					t.KV("Uploaded file", fmt.Sprintf("%s (%s)", output.Dash(img.SourceFilename), bytesText(img.ArchiveBytes)))
				}
				t.KV("Scan", scanText(*img))
				if img.Note != "" {
					t.KV("Note", img.Note)
				}
				if img.CreatedAt != nil {
					t.KV("Uploaded", img.CreatedAt.UTC().Format("2006-01-02 15:04:05Z"))
				}
			})
		},
	}
	get.Flags().StringVar(&region, "region", "", "region of the image (when the name is uploaded in several)")
	return get
}

func newImagesPushCmd(app *App) *cobra.Command {
	var region, name, format string
	push := &cobra.Command{
		Use:   "push <file>",
		Short: "Upload a docker save / OCI layout tar or a .wasm file (resumes an interrupted upload)",
		Example: "  docker save myapp:1.0 -o myapp.tar\n" +
			"  runr images push myapp.tar --region eu-1 --name myapp:1.0\n" +
			"  runr images push q.wasm --name q-interpreter:1.0",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			reg, err := c.FindRegion(ctx, region)
			if err != nil {
				return err
			}
			switch format {
			case "", "auto", "docker", "oci", "wasm":
			default:
				return usageErrorf("unknown --format %q (auto, docker, oci or wasm)", format)
			}

			last := ""
			img, err := c.PushImage(ctx, args[0], runr.PushOptions{
				RegionID:     reg.ID,
				Name:         name,
				Format:       format,
				PollInterval: app.PollInterval,
				Progress: func(phase string, uploaded, total int64) {
					switch phase {
					case "uploading":
						if total > 0 {
							pct := uploaded * 100 / total
							line := fmt.Sprintf("Uploading %s: %d%% (%s / %s)", args[0], pct, bytesText(uploaded), bytesText(total))
							if line != last && (pct%10 == 0 || uploaded == total) {
								app.Progress("%s", line)
								last = line
							}
						}
					case "resume":
						app.Progress("Resuming an earlier upload of %s at %s", args[0], bytesText(uploaded))
					default:
						if phase != last {
							app.Progress("%s", phaseText(phase, reg.Name))
							last = phase
						}
					}
				},
			})
			if err != nil {
				return err
			}
			return app.Printer().Print(img, func(t *output.TableWriter) {
				t.KV("Image", img.Name)
				t.KV("Region", output.Dash(img.RegionName))
				t.KV("Kind", img.Kind)
				t.KV("Platforms", output.Dash(strings.Join(img.Platforms, ", ")))
				t.KV("Digest", img.Digest)
				t.KV("Reference", img.ImageRef)
				if img.Note != "" {
					t.Note("%s", img.Note)
				}
			})
		},
	}
	push.Flags().StringVar(&region, "region", "", "region to upload into (default: the only active region)")
	push.Flags().StringVar(&name, "name", "", "repository[:tag] (default: from the file name, tag latest)")
	push.Flags().StringVar(&format, "format", "", "auto, docker, oci or wasm (default: detected from the file)")
	return push
}

func phaseText(phase, region string) string {
	switch phase {
	case runr.UploadPreparing:
		return "Starting the receiver in " + region + "…"
	case runr.UploadVerifying:
		return "Verifying the file in " + region + "…"
	case runr.UploadPushing:
		return "Pushing into the registry of " + region + "…"
	case runr.UploadCompleted:
		return "Uploaded."
	}
	return phase
}

func newImagesLsCmd(app *App) *cobra.Command {
	var region, kind string
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List uploaded images",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			regionID := ""
			if region != "" {
				reg, err := c.FindRegion(ctx, region)
				if err != nil {
					return err
				}
				regionID = reg.ID
			}
			list, quota, err := c.ListImages(ctx, regionID, kind)
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.UploadedImage{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("NAME", "REGION", "KIND", "PLATFORMS", "SIZE", "SCAN", "DIGEST")
				for _, img := range list {
					t.Row(img.Name, output.Dash(img.RegionName), img.Kind, output.Dash(strings.Join(img.Platforms, ",")),
						bytesText(img.SizeBytes), scanText(img), shortDigest(img.Digest))
				}
				if quota != nil {
					if quota.LimitBytes > 0 {
						t.Note("%s of %s used", bytesText(quota.UsedBytes), bytesText(quota.LimitBytes))
					} else {
						t.Note("%s used", bytesText(quota.UsedBytes))
					}
				}
			})
		},
	}
	ls.Flags().StringVar(&region, "region", "", "only this region")
	ls.Flags().StringVar(&kind, "kind", "", "only image, wasm or spin")
	return ls
}

func newImagesRmCmd(app *App) *cobra.Command {
	var region string
	var yes bool
	rm := &cobra.Command{
		Use:     "rm <name|id>",
		Aliases: []string{"delete"},
		Short:   "Delete an uploaded image and its manifest in the region registry",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			regionID := ""
			if region != "" {
				reg, err := c.FindRegion(ctx, region)
				if err != nil {
					return err
				}
				regionID = reg.ID
			}
			list, _, err := c.ListImages(ctx, regionID, "")
			if err != nil {
				return err
			}
			img, err := runr.FindImage(list, args[0], regionID)
			if err != nil {
				return err
			}
			if err := app.confirm(fmt.Sprintf("Delete %s from %s", img.Name, output.Dash(img.RegionName)), yes); err != nil {
				return err
			}
			if err := c.DeleteImage(ctx, img.ID); err != nil {
				return err
			}
			app.Progress("Deleted %s from %s.", img.Name, output.Dash(img.RegionName))
			return nil
		},
	}
	rm.Flags().StringVar(&region, "region", "", "region of the image (when the name is uploaded in several)")
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return rm
}

func scanText(img runr.UploadedImage) string {
	s := img.Security
	if img.Kind != "image" {
		return "-"
	}
	if s == nil {
		return "not scanned"
	}
	if s.Status == "pending" || s.Status == "running" {
		return s.Status
	}
	if s.ScanStatus != "ok" {
		return "failed"
	}
	return fmt.Sprintf("C%d H%d", s.Counts["critical"], s.Counts["high"])
}

func shortDigest(d string) string {
	if strings.HasPrefix(d, "sha256:") && len(d) > 19 {
		return d[:19]
	}
	return d
}

func bytesText(n int64) string {
	const unit = 1024
	if n < unit {
		return fmt.Sprintf("%d B", n)
	}
	div, exp := int64(unit), 0
	for m := n / unit; m >= unit && exp < 4; m /= unit {
		div *= unit
		exp++
	}
	return fmt.Sprintf("%.1f %ciB", float64(n)/float64(div), "KMGTP"[exp])
}
