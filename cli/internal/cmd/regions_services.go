package cmd

import (
	"context"
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// The services of a region besides Knative: `runr regions redis | registry | migration |
// function-tls | skupper | capacity | demo`.

func newRegionServiceCommands(app *App) []*cobra.Command {
	return []*cobra.Command{
		newRegionRedisCmd(app), newRegionRegistryCmd(app), newRegionMigrationCmd(app), newRegionFunctionTLSCmd(app),
		newRegionSkupperCmd(app), newRegionCapacityCmd(app), newRegionDemoCmd(app),
	}
}

// ---------------------------------------------------------------------------------------
// Redis

func printRegionRedis(app *App, reg *runr.Region, st *runr.RegionRedisStatus) error {
	return app.Printer().Print(st, func(t *output.TableWriter) {
		t.KV("Region", reg.Name)
		t.KV("Mode", output.Dash(st.Mode))
		t.KV("Status", rlcStatus(st.Status, st.StatusMessage))
		t.KV("Address", output.Dash(st.Address))
		t.KV("Queue triggers", map[bool]string{true: "available", false: "unavailable"}[st.HasRedis])
	})
}

func newRegionRedisCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "redis [REGION]",
		Short: "The Redis of a region that queue triggers use",
		Long: "Queue-triggered projects read their streams from the region's Redis: one RunR installs in the\n" +
			"cluster (managed), or yours in the cluster (in_cluster) or outside it (external). Passwords\n" +
			"come from a file or stdin (-), never from an argument. Reading needs regions.view, changes\n" +
			"regions.manage.",
		Example: "  runr regions redis eu-1\n" +
			"  runr regions redis test eu-1 --address redis.example:6379 --password-file redis.pass\n" +
			"  runr regions redis set eu-1 --mode external --address redis.example:6379 --password-file redis.pass --wait\n" +
			"  runr regions redis rm eu-1 --yes",
		Args: cobra.MaximumNArgs(1),
	}
	status := &cobra.Command{
		Use:     "status [REGION]",
		Aliases: []string{"get"},
		Short:   "Show the Redis of a region",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			st, err := c.RegionRedis(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return printRegionRedis(app, reg, st)
		},
	}
	cmd.AddCommand(status)
	cmd.RunE = status.RunE

	var address, passwordFile string
	test := &cobra.Command{
		Use:   "test REGION --address HOST:PORT",
		Short: "Check from the region's cluster that a Redis answers (regions.manage)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if address == "" {
				return usageErrorf("--address HOST:PORT is required")
			}
			password := ""
			if passwordFile != "" {
				var err error
				if password, err = rlcReadSecret(app, "password-file", passwordFile); err != nil {
					return err
				}
			}
			c, reg, err := radmRegion(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			res, err := c.TestRegionRedis(cmd.Context(), reg.ID, address, password)
			if err != nil {
				return err
			}
			if err := app.Printer().Print(res, func(t *output.TableWriter) {
				t.KV("Redis", address)
				t.KV("Reachable", yesNoWord(res.Reachable))
				t.KV("Answer", output.Dash(res.Message))
			}); err != nil {
				return err
			}
			if !res.Reachable {
				return &runr.FailedError{What: "Redis test of", Ref: address, Message: res.Message}
			}
			return nil
		},
	}
	test.Flags().StringVar(&address, "address", "", "host:port of the Redis")
	test.Flags().StringVar(&passwordFile, "password-file", "", "file (or -) with the Redis password")
	cmd.AddCommand(test, newRegionRedisSetCmd(app), newRegionRedisRmCmd(app))
	return cmd
}

func regionRedisWait(ctx context.Context, app *App, c *runr.Client, reg *runr.Region, timeout time.Duration) error {
	st, err := c.WaitRegionRedis(ctx, reg.ID, reg.Name, app.waitOptions(timeout, "Redis of "+reg.Name))
	if st == nil {
		return err
	}
	if perr := printRegionRedis(app, reg, st); perr != nil && err == nil {
		err = perr
	}
	return err
}

func newRegionRedisSetCmd(app *App) *cobra.Command {
	var mode, address, passwordFile string
	var wait bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:     "set REGION --mode managed|in_cluster|external",
		Aliases: []string{"install"},
		Short:   "Install or connect the Redis of a region (regions.manage)",
		Long: "Give the region a Redis for queue triggers: managed (RunR installs one in the cluster),\n" +
			"in_cluster or external (--address host:port, --password-file FILE or -). The change runs in\n" +
			"the background: --wait waits (exit code 6 when it fails).",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			in := runr.RedisConfig{Mode: mode, Address: address}
			switch mode {
			case "managed":
				if address != "" || passwordFile != "" {
					return usageErrorf("--address and --password-file apply to in_cluster and external")
				}
			case "in_cluster", "external":
				if address == "" {
					return usageErrorf("--mode %s needs --address", mode)
				}
			default:
				return usageErrorf("--mode must be managed, in_cluster or external")
			}
			if passwordFile != "" {
				var err error
				if in.Password, err = rlcReadSecret(app, "password-file", passwordFile); err != nil {
					return err
				}
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			res, err := c.ConfigureRegionRedis(ctx, reg.ID, in)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			if wait {
				return regionRedisWait(ctx, app, c, reg, timeout)
			}
			return printRegionOperation(app, reg, res, "runr regions redis "+reg.Name+" shows the progress.")
		},
	}
	cmd.Flags().StringVar(&mode, "mode", "", "managed, in_cluster or external")
	cmd.Flags().StringVar(&address, "address", "", "in_cluster / external: host:port of the Redis")
	cmd.Flags().StringVar(&passwordFile, "password-file", "", "file (or -) with the Redis password")
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the Redis is ready (exit code 6 when it fails)")
	cmd.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long --wait waits")
	return cmd
}

func newRegionRedisRmCmd(app *App) *cobra.Command {
	var wait, yes bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:     "rm REGION",
		Aliases: []string{"remove", "uninstall"},
		Short:   "Remove the Redis of a region (queue triggers stop) (regions.manage)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Remove the Redis of "+reg.Name+" (queue-triggered projects stop; a managed Redis is deleted with its data)", yes); err != nil {
				return err
			}
			res, err := c.RemoveRegionRedis(ctx, reg.ID)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			if wait {
				return regionRedisWait(ctx, app, c, reg, timeout)
			}
			return printRegionOperation(app, reg, res, "")
		},
	}
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the Redis is removed")
	cmd.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long --wait waits")
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

// ---------------------------------------------------------------------------------------
// Registry

func newRegionRegistryCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "registry [REGION]",
		Short: "The image registry of a region: status, setup, removal",
		Long: "Builds push their images to the region's registry: RunR's Zot (internal) or yours\n" +
			"(external). A region gets one when it is created with runr regions create --registry (its\n" +
			"setup is followed with setup-status), or later with set. test checks a volume, an S3 bucket\n" +
			"or an external registry from a cluster before. Reading needs regions.view, the rest\n" +
			"registry.manage.",
		Example: "  runr regions registry eu-1\n" +
			"  runr regions registry set eu-1 --registry internal --storage-class fast-ssd --wait\n" +
			"  runr regions registry setup-status 8c0f6a1e-… --wait\n" +
			"  runr regions registry test pvc --kubeconfig eu-2.yaml --storage-class fast-ssd\n" +
			"  runr regions registry rm eu-1 --yes",
		Args: cobra.MaximumNArgs(1),
	}
	status := &cobra.Command{
		Use:     "status [REGION]",
		Aliases: []string{"get"},
		Short:   "Show the image registry of a region",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			st, err := c.RegionRegistry(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(st, func(t *output.TableWriter) {
				t.KV("Region", reg.Name)
				t.KV("Mode", output.Dash(st.Mode))
				t.KV("Status", rlcStatus(st.Status, st.StatusMessage))
				t.KV("Address", output.Dash(st.Address))
				if !st.HasRegistry {
					t.Note("Region %s has no registry ready: builds in the region (runtime plugins with a build step, WebAssembly functions) need one.", reg.Name)
				}
			})
		},
	}
	cmd.AddCommand(status)
	cmd.RunE = status.RunE

	var yes bool
	rm := &cobra.Command{
		Use:     "rm REGION",
		Aliases: []string{"remove", "uninstall"},
		Short:   "Remove the image registry of a region (registry.manage)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Remove the registry of "+reg.Name+" (RunR's registry is deleted with its images)", yes); err != nil {
				return err
			}
			d, err := c.RemoveRegionRegistry(ctx, reg.ID)
			if err != nil {
				return err
			}
			app.Progress("Removed the registry of %s.", reg.Name)
			return app.Printer().Print(d, func(t *output.TableWriter) { printRegionDetail(t, d) })
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")

	var wait bool
	var timeout time.Duration
	setup := &cobra.Command{
		Use:   "setup-status TOKEN",
		Short: "Follow the setup of a region created with --registry",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			if wait {
				return regionSetupWait(cmd.Context(), app, c, args[0], "", timeout)
			}
			st, err := c.RegistrySetupState(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(st, func(t *output.TableWriter) {
				t.KV("Status", st.Status)
				t.KV("Phase", output.Dash(st.Phase))
				t.KV("Message", output.Dash(st.Message))
				t.KV("Region", output.Dash(st.RegionID))
			})
		},
	}
	setup.Flags().BoolVar(&wait, "wait", false, "wait until the region exists (exit code 6 when the setup fails)")
	setup.Flags().DurationVar(&timeout, "timeout", 20*time.Minute, "how long --wait waits")
	cmd.AddCommand(rm, setup, newRegionRegistryTestCmd(app), newRegionRegistrySetCmd(app))
	return cmd
}

func newRegionRegistryTestCmd(app *App) *cobra.Command {
	var kubeconfig, storageClass, size, s3File, address, credFile, namespace string
	cmd := &cobra.Command{
		Use:   "test pvc|s3|external --kubeconfig FILE",
		Short: "Check registry storage or an external registry from a cluster (registry.manage)",
		Long: "Check from a pod in a cluster what a registry setup needs: that a StorageClass provisions a\n" +
			"volume (pvc: --storage-class, --size default 10Gi), that an S3 bucket is reachable (s3:\n" +
			"--s3 FILE with ENDPOINT, BUCKET, REGION, ACCESS_KEY, SECRET_KEY lines) or that an external\n" +
			"registry answers (external: --address, --credentials FILE with USERNAME and PASSWORD lines).\n" +
			"Exit code 6 when the check fails.",
		Example: "  runr regions registry test pvc --kubeconfig eu-2.yaml --storage-class fast-ssd\n" +
			"  runr regions registry test external --kubeconfig eu-2.yaml --address registry.example.com --credentials reg.env",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			kind := strings.ToLower(args[0])
			if err := rlcOneStdin(map[string]string{"kubeconfig": kubeconfig, "s3": s3File, "credentials": credFile}); err != nil {
				return err
			}
			allowed := map[string][]string{"pvc": {"storage-class", "size"}, "s3": {"s3"}, "external": {"address", "credentials"}}[kind]
			if allowed == nil {
				return usageErrorf("test pvc, s3 or external, not %q", args[0])
			}
			for _, fl := range []string{"storage-class", "size", "s3", "address", "credentials"} {
				if !cmd.Flags().Changed(fl) {
					continue
				}
				ok := false
				for _, a := range allowed {
					ok = ok || a == fl
				}
				if !ok {
					return usageErrorf("--%s does not apply to a %s test", fl, kind)
				}
			}
			kc, err := rlcReadKubeconfig(app, "kubeconfig", kubeconfig)
			if err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			ctx := cmd.Context()
			var res *runr.RegistryTest
			switch kind {
			case "pvc":
				if storageClass == "" {
					return usageErrorf("--storage-class is required")
				}
				if size == "" {
					size = "10Gi"
				}
				res, err = c.TestRegistryPVC(ctx, kc, runr.RegistryPVC{StorageClass: storageClass, Size: size}, namespace)
			case "s3":
				if s3File == "" {
					return usageErrorf("--s3 FILE is required")
				}
				kv, rerr := rlcReadKeyValues(app, "s3", s3File, registryS3Keys)
				if rerr != nil {
					return rerr
				}
				res, err = c.TestRegistryS3(ctx, kc, runr.RegistryS3{Endpoint: kv["endpoint"], Bucket: kv["bucket"], Region: kv["region"],
					AccessKey: kv["access_key"], SecretKey: kv["secret_key"]}, namespace)
			case "external":
				if address == "" {
					return usageErrorf("--address is required")
				}
				var user, pass string
				if credFile != "" {
					kv, rerr := rlcReadKeyValues(app, "credentials", credFile, registryCredentialKeys)
					if rerr != nil {
						return rerr
					}
					user, pass = kv["username"], kv["password"]
				}
				res, err = c.TestRegistryExternal(ctx, kc, address, user, pass, namespace)
			}
			if err != nil {
				return err
			}
			if err := app.Printer().Print(res, func(t *output.TableWriter) {
				t.KV("Test", kind)
				t.KV("Result", map[bool]string{true: "passed", false: "failed"}[res.Success])
				t.KV("Message", output.Dash(res.Message))
			}); err != nil {
				return err
			}
			if !res.Success {
				return &runr.FailedError{What: "registry", Ref: kind + " test", Message: res.Message}
			}
			return nil
		},
	}
	fs := cmd.Flags()
	fs.StringVar(&kubeconfig, "kubeconfig", "", "kubeconfig file of the cluster (- for stdin)")
	fs.StringVar(&storageClass, "storage-class", "", "pvc: StorageClass to test")
	fs.StringVar(&size, "size", "", "pvc: volume size (default 10Gi)")
	fs.StringVar(&s3File, "s3", "", "s3: file (or -) with ENDPOINT, BUCKET, REGION, ACCESS_KEY, SECRET_KEY lines")
	fs.StringVar(&address, "address", "", "external: address of the registry")
	fs.StringVar(&credFile, "credentials", "", "external: file (or -) with USERNAME and PASSWORD lines")
	fs.StringVar(&namespace, "namespace", "", "namespace the test pod runs in (default runr-registry)")
	return cmd
}

// ---------------------------------------------------------------------------------------
// Migration

func printRegionMigration(app *App, reg *runr.Region, st *runr.RegionMigration) error {
	return app.Printer().Print(st, func(t *output.TableWriter) {
		t.KV("Region", reg.Name)
		status := output.Dash(st.Status)
		if st.Status == "" {
			status = "no migration"
		}
		t.KV("Status", status)
		t.KV("Started", output.Dash(st.StartedAt))
		t.KV("Old IP", output.Dash(st.OldIP))
		t.KV("Current IP", output.Dash(st.CurrentIP))
		// What the move did with each project, while it can be rolled back
		if len(st.Projects) > 0 {
			t.Header("PROJECT", "ORGANIZATION", "OUTCOME", "VERSION", "DEPLOYMENT", "MESSAGE")
			for _, p := range st.Projects {
				version := "-"
				if p.Version > 0 {
					version = "v" + strconv.Itoa(p.Version)
				}
				t.Row(p.Project, output.Dash(p.Organization), p.Outcome, version, output.Dash(p.DeploymentStatus), output.Dash(p.Message))
			}
		}
		switch st.Status {
		case runr.MigrationDNSCheck:
			t.Note("Point the region's DNS at %s, then: runr regions migration complete %s (or rollback).", output.Dash(st.CurrentIP), reg.Name)
		case runr.MigrationFailed:
			t.Note("runr regions migration rollback %s returns the region to its old cluster.", reg.Name)
		}
	})
}

func newRegionMigrationCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "migration [REGION]",
		Aliases: []string{"migrate"},
		Short:   "Move a region to another cluster: start, status, complete, rollback",
		Long: "Move a region to a new cluster: start installs the region's components there and deploys its\n" +
			"projects again (in the background; --wait waits for the DNS step). Then point the region's\n" +
			"DNS at the new address and complete the move, which releases the old cluster, or roll it back.\n" +
			"A region with a data location moves only within it (--data-location names the new cluster's).\n" +
			"Reading needs regions.view, the steps regions.manage.",
		Example: "  runr regions migration start eu-1 --kubeconfig new-cluster.yaml --yes --wait\n" +
			"  runr regions migration eu-1\n" +
			"  runr regions migration complete eu-1",
		Args: cobra.MaximumNArgs(1),
	}
	status := &cobra.Command{
		Use:     "status [REGION]",
		Aliases: []string{"get"},
		Short:   "Show the migration of a region",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			st, err := c.RegionMigrationState(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return printRegionMigration(app, reg, st)
		},
	}
	cmd.AddCommand(status)
	cmd.RunE = status.RunE

	var kubeconfig, dataLocation string
	var wait, yes bool
	var timeout time.Duration
	start := &cobra.Command{
		Use:   "start REGION --kubeconfig FILE",
		Short: "Start moving a region to another cluster (regions.manage)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			kc, err := rlcReadKubeconfig(app, "kubeconfig", kubeconfig)
			if err != nil {
				return err
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Move "+reg.Name+" to another cluster (its projects are deployed there again)", yes); err != nil {
				return err
			}
			res, err := c.StartRegionMigration(ctx, reg.ID, kc, dataLocation)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			if !wait {
				st, err := c.RegionMigrationState(ctx, reg.ID)
				if err != nil {
					return err
				}
				return printRegionMigration(app, reg, st)
			}
			st, err := c.WaitRegionMigration(ctx, reg.ID, reg.Name, app.waitOptions(timeout, "Migration of "+reg.Name))
			if st == nil {
				return err
			}
			if perr := printRegionMigration(app, reg, st); perr != nil && err == nil {
				err = perr
			}
			return err
		},
	}
	start.Flags().StringVar(&kubeconfig, "kubeconfig", "", "kubeconfig file of the new cluster (- for stdin)")
	start.Flags().StringVar(&dataLocation, "data-location", "", "data location of the new cluster (a region with one moves only within it)")
	start.Flags().BoolVar(&wait, "wait", false, "wait until the workloads run on the new cluster (exit code 6 when it fails)")
	start.Flags().DurationVar(&timeout, "timeout", time.Hour, "how long --wait waits")
	start.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")

	var domain bool
	complete := &cobra.Command{
		Use:   "complete REGION [--domain]",
		Short: "Complete a migration once DNS points at the new cluster (regions.manage)",
		Long: "Complete the region's move to another cluster (in its DNS step). With --domain, complete the\n" +
			"region's move to a new base domain instead: the new function domain becomes the region's\n" +
			"without waiting for RunR to see its DNS record, and the old one is no longer served.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			if domain {
				op, err := c.CompleteRegionDomainMigration(cmd.Context(), reg.ID)
				if err != nil {
					return err
				}
				app.Progress("%s", op.Message)
				return printRegionOperation(app, reg, op, "")
			}
			res, err := c.CompleteRegionMigration(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			return app.Printer().Print(map[string]any{"region": reg.Name, "message": res.Message}, func(*output.TableWriter) {})
		},
	}
	complete.Flags().BoolVar(&domain, "domain", false, "complete the region's move to a new base domain")
	var rollbackYes bool
	rollback := &cobra.Command{
		Use:   "rollback REGION",
		Short: "Return a migrating region to its old cluster (regions.manage)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Return "+reg.Name+" to its old cluster (its projects are deployed there again)", rollbackYes); err != nil {
				return err
			}
			res, err := c.RollbackRegionMigration(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			return app.Printer().Print(map[string]any{"region": reg.Name, "message": res.Message}, func(*output.TableWriter) {})
		},
	}
	rollback.Flags().BoolVarP(&rollbackYes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(start, complete, rollback)
	return cmd
}

// ---------------------------------------------------------------------------------------
// Function-domain TLS

func newRegionFunctionTLSCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "function-tls [REGION]",
		Short: "The TLS certificate of a region's function domains",
		Long: "The region's Traefik serves the function domains (*.<region domain>) with the console's\n" +
			"certificate (console) or one of your own (custom: --cert and --key PEM files, - for stdin).\n" +
			"Reading needs regions.view, changes regions.manage.",
		Example: "  runr regions function-tls eu-1\n" +
			"  runr regions function-tls set eu-1 --mode custom --cert wildcard.crt --key wildcard.key\n" +
			"  runr regions function-tls set eu-1 --mode console",
		Args: cobra.MaximumNArgs(1),
	}
	get := &cobra.Command{
		Use:   "get [REGION]",
		Short: "Show the function-domain certificate of a region",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			d, err := c.GetRegionDetail(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			out := map[string]any{"region": d.Name, "mode": d.FunctionTLSMode, "expires_at": d.FunctionTLSExpiresAt}
			return app.Printer().Print(out, func(t *output.TableWriter) {
				t.KV("Region", d.Name)
				mode := output.Dash(d.FunctionTLSMode)
				if d.FunctionTLSMode == "" {
					mode = "not set (the region's default certificate)"
				}
				t.KV("Mode", mode)
				t.KV("Expires", output.Dash(d.FunctionTLSExpiresAt))
				if d.DNSInstruction != nil {
					t.KV("Domains", d.DNSInstruction.Wildcard)
				}
			})
		},
	}
	cmd.AddCommand(get)
	cmd.RunE = get.RunE

	var mode, certFile, keyFile string
	var replace bool
	set := &cobra.Command{
		Use:   "set REGION --mode console|custom",
		Short: "Set the certificate of a region's function domains (regions.manage)",
		Long: "Set the certificate the region's Traefik serves function domains with: console (the\n" +
			"console's certificate) or custom (--cert and --key PEM files, - for stdin; the key never\n" +
			"appears in runr's output). --replace-existing replaces a default TLS store of the region's\n" +
			"Traefik that RunR did not create.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			in := runr.FunctionTLSInput{Mode: mode, ReplaceExisting: replace}
			switch mode {
			case runr.FunctionTLSConsole:
				if certFile != "" || keyFile != "" {
					return usageErrorf("--cert and --key apply to --mode custom")
				}
			case runr.FunctionTLSCustom:
				if certFile == "" || keyFile == "" {
					return usageErrorf("--mode custom needs --cert and --key")
				}
				if err := rlcOneStdin(map[string]string{"cert": certFile, "key": keyFile}); err != nil {
					return err
				}
				cert, err := readFileArg(app, certFile)
				if err != nil {
					return err
				}
				key, err := readFileArg(app, keyFile)
				if err != nil {
					return err
				}
				if !strings.Contains(string(cert), "BEGIN CERTIFICATE") {
					return usageErrorf("%s is not a PEM certificate", certFile)
				}
				if !strings.Contains(string(key), "PRIVATE KEY") {
					return usageErrorf("%s is not a PEM private key", keyFile)
				}
				in.Certificate, in.PrivateKey = string(cert), string(key)
			default:
				return usageErrorf("--mode must be console or custom")
			}
			c, reg, err := radmRegion(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			res, err := c.SetRegionFunctionTLS(cmd.Context(), reg.ID, in)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			return printRegionOperation(app, reg, res, "")
		},
	}
	set.Flags().StringVar(&mode, "mode", "", "console or custom")
	set.Flags().StringVar(&certFile, "cert", "", "custom: PEM certificate (chain) file, - for stdin")
	set.Flags().StringVar(&keyFile, "key", "", "custom: PEM private key file, - for stdin")
	set.Flags().BoolVar(&replace, "replace-existing", false, "replace a default TLS store RunR did not create")

	var yes bool
	rm := &cobra.Command{
		Use:     "rm REGION",
		Aliases: []string{"remove", "off"},
		Short:   "Switch function-domain TLS off: the region's Traefik default certificate (regions.manage)",
		Long: "Remove the certificate RunR set for the region's function domains (its Secret and default TLS\n" +
			"store): the region's Traefik answers HTTPS with its own default certificate again.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Remove the function-domain certificate of "+reg.Name, yes); err != nil {
				return err
			}
			res, err := c.RemoveRegionFunctionTLS(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			return printRegionOperation(app, reg, res, "")
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(set, rm)
	return cmd
}

// ---------------------------------------------------------------------------------------
// Skupper

func newRegionSkupperCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "skupper [REGION]",
		Short: "Skupper on a region: private connections of database bindings",
		Long: "Private database connections (docs/bindings.md) run over Skupper v2, which the cluster's\n" +
			"operators install; RunR detects it and creates the Skupper site of the region's project\n" +
			"namespace (site). Reading needs regions.view, site regions.manage.",
		Example: "  runr regions skupper eu-1\n" +
			"  runr regions skupper site eu-1",
		Args: cobra.MaximumNArgs(1),
	}
	status := &cobra.Command{
		Use:     "status [REGION]",
		Aliases: []string{"get"},
		Short:   "Show whether a region runs Skupper and the site of its project namespace",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			st, err := c.RegionSkupper(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(st, func(t *output.TableWriter) {
				t.KV("Region", reg.Name)
				t.KV("Skupper", map[bool]string{true: "installed (" + output.Dash(st.APIVersion) + ")", false: "not installed"}[st.Available])
				t.KV("Namespace", output.Dash(st.Namespace))
				site := "-"
				if st.Site != nil {
					site = st.Site.Name + ", " + output.Dash(st.Site.Status)
					if st.Site.Message != "" {
						site += " (" + st.Site.Message + ")"
					}
				}
				t.KV("Site", site)
				if st.Available && st.Site == nil {
					t.Note("runr regions skupper site %s creates the site.", reg.Name)
				}
			})
		},
	}
	cmd.AddCommand(status)
	cmd.RunE = status.RunE
	site := &cobra.Command{
		Use:   "site REGION",
		Short: "Create the Skupper site of a region's project namespace (regions.manage)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			s, err := c.EnsureRegionSkupperSite(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			app.Progress("Skupper site %s of %s: %s.", s.Name, reg.Name, output.Dash(s.Status))
			return app.Printer().Print(s, func(t *output.TableWriter) {
				t.KV("Region", reg.Name)
				t.KV("Site", s.Name)
				t.KV("Status", output.Dash(s.Status))
			})
		},
	}
	cmd.AddCommand(site)
	return cmd
}

// ---------------------------------------------------------------------------------------
// Capacity

func printRegionCapacity(app *App, reg *runr.Region, st *runr.RegionCapacityState) error {
	return app.Printer().Print(st, func(t *output.TableWriter) {
		t.KV("Region", reg.Name)
		t.KV("FaaS overcommit", fmt.Sprintf("CPU %s, memory %s", rlcFactor(st.FaaSCPUOvercommit), rlcFactor(st.FaaSMemoryOvercommit)))
		util := func(b runr.CapacityBudget) string {
			if b.Utilization == nil {
				return "-"
			}
			return strconv.FormatFloat(*b.Utilization, 'f', 1, 64) + "%"
		}
		t.Header("RESOURCE", "CAPACITY", "ALWAYS-ON USED", "ALWAYS-ON FREE", "FAAS BUDGET", "FAAS USED", "FAAS FREE", "UTILIZATION")
		t.Row("cpu (m)", strconv.Itoa(st.CPUm.Capacity), strconv.Itoa(st.CPUm.AlwaysOnUsed), strconv.Itoa(st.CPUm.AlwaysOnAvailable),
			strconv.Itoa(st.CPUm.FaaSBudget), strconv.Itoa(st.CPUm.FaaSUsed), strconv.Itoa(st.CPUm.FaaSAvailable), util(st.CPUm))
		t.Row("memory (MiB)", strconv.Itoa(st.MemoryMB.Capacity), strconv.Itoa(st.MemoryMB.AlwaysOnUsed),
			strconv.Itoa(st.MemoryMB.AlwaysOnAvailable), strconv.Itoa(st.MemoryMB.FaaSBudget), strconv.Itoa(st.MemoryMB.FaaSUsed),
			strconv.Itoa(st.MemoryMB.FaaSAvailable), util(st.MemoryMB))
	})
}

func newRegionCapacityCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "capacity [REGION]",
		Short: "Capacity budgets of a region and its FaaS overcommit factors",
		Long: "A region admits always-on workloads against its capacity (no overcommit) and scale-to-zero\n" +
			"projects and model services against the FaaS overcommit budget: capacity × factor, minus the\n" +
			"always-on reservations (docs/architecture.md). set changes the factors (1-10, two decimals;\n" +
			"1 = no overcommit). Reading needs regions.view, set regions.manage.",
		Example: "  runr regions capacity eu-1\n" +
			"  runr regions capacity set eu-1 --set faas_cpu_overcommit=4 --set faas_memory_overcommit=1.25",
		Args: cobra.MaximumNArgs(1),
	}
	get := &cobra.Command{
		Use:   "get [REGION]",
		Short: "Show the capacity budgets of a region",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			st, err := c.RegionCapacity(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return printRegionCapacity(app, reg, st)
		},
	}
	cmd.AddCommand(get)
	cmd.RunE = get.RunE
	var sets []string
	set := &cobra.Command{
		Use:   "set REGION --set KEY=VALUE...",
		Short: "Change the FaaS overcommit factors of a region (regions.manage)",
		Long: "Change the factors with --set KEY=VALUE: faas_cpu_overcommit and faas_memory_overcommit\n" +
			"(1-10, at most two decimals). A factor left out is kept.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			pairs, err := parseKeyValues("--set", sets)
			if err != nil {
				return err
			}
			var cpu, mem *float64
			for k, v := range pairs {
				f, err := strconv.ParseFloat(strings.TrimSpace(v), 64)
				if err != nil || f < 1 || f > 10 {
					return usageErrorf("--set %s must be a factor between 1 and 10", k)
				}
				switch strings.ReplaceAll(strings.ToLower(k), "-", "_") {
				case "faas_cpu_overcommit", "cpu":
					cpu = &f
				case "faas_memory_overcommit", "memory":
					mem = &f
				default:
					return usageErrorf("--set takes faas_cpu_overcommit or faas_memory_overcommit")
				}
			}
			if cpu == nil && mem == nil {
				return usageErrorf("give at least one --set KEY=VALUE")
			}
			c, reg, err := radmRegion(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			st, err := c.SetRegionCapacity(cmd.Context(), reg.ID, cpu, mem)
			if err != nil {
				return err
			}
			app.Progress("%s", output.Dash(st.Message))
			return printRegionCapacity(app, reg, st)
		},
	}
	set.Flags().StringArrayVar(&sets, "set", nil, "KEY=VALUE: faas_cpu_overcommit or faas_memory_overcommit (repeatable)")
	cmd.AddCommand(set)
	return cmd
}

// ---------------------------------------------------------------------------------------
// Demo projects

func newRegionDemoCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "demo [REGION]",
		Short: "Demo projects of a region: deploy and remove them",
		Long: "A set of demo projects shows a new region at work. install deploys them (Knative Serving\n" +
			"must be ready), rm removes them; both run in the background (--wait waits). Needs\n" +
			"regions.manage; reading regions.view.",
		Example: "  runr regions demo install eu-1 --wait\n" +
			"  runr regions demo rm eu-1 --yes",
		Args: cobra.MaximumNArgs(1),
	}
	status := &cobra.Command{
		Use:     "status [REGION]",
		Aliases: []string{"get"},
		Short:   "Show whether a region has demo projects",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			d, err := c.GetRegionDetail(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return printRegionDemo(app, d)
		},
	}
	cmd.AddCommand(status)
	cmd.RunE = status.RunE
	var wait, yes bool
	var timeout time.Duration
	install := &cobra.Command{
		Use:     "install REGION",
		Aliases: []string{"deploy"},
		Short:   "Deploy the demo projects to a region (regions.manage)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			return regionDemo(cmd.Context(), app, args[0], true, true, wait, timeout)
		},
	}
	install.Flags().BoolVar(&wait, "wait", false, "wait until the demo projects run (exit code 6 when it fails)")
	install.Flags().DurationVar(&timeout, "timeout", 15*time.Minute, "how long --wait waits")
	rm := &cobra.Command{
		Use:     "rm REGION",
		Aliases: []string{"remove", "uninstall"},
		Short:   "Remove the demo projects of a region (regions.manage)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			return regionDemo(cmd.Context(), app, args[0], false, yes, wait, timeout)
		},
	}
	rm.Flags().BoolVar(&wait, "wait", false, "wait until the demo projects are removed")
	rm.Flags().DurationVar(&timeout, "timeout", 15*time.Minute, "how long --wait waits")
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(install, rm)
	return cmd
}

func printRegionDemo(app *App, d *runr.RegionDetail) error {
	out := map[string]any{"region": d.Name, "region_demo_status": d.DemoStatus}
	return app.Printer().Print(out, func(t *output.TableWriter) {
		t.KV("Region", d.Name)
		status := d.DemoStatus
		if status == "" {
			status = "none"
		}
		t.KV("Demo projects", status)
	})
}

func regionDemo(ctx context.Context, app *App, ref string, install, yes, wait bool, timeout time.Duration) error {
	c, reg, err := radmRegion(ctx, app, ref)
	if err != nil {
		return err
	}
	var status string
	if install {
		status, err = c.SeedRegionDemo(ctx, reg.ID)
	} else {
		if err := app.confirm("Remove the demo projects of "+reg.Name, yes); err != nil {
			return err
		}
		status, err = c.RemoveRegionDemo(ctx, reg.ID)
	}
	if err != nil {
		return err
	}
	app.Progress("Demo projects of %s: %s.", reg.Name, status)
	if !wait {
		return app.Printer().Print(map[string]any{"region": reg.Name, "region_demo_status": status}, func(t *output.TableWriter) {
			t.KV("Region", reg.Name)
			t.KV("Demo projects", status)
		})
	}
	d, err := c.WaitRegionDemo(ctx, reg.ID, reg.Name, app.waitOptions(timeout, "Demo projects of "+reg.Name))
	if d == nil {
		return err
	}
	if perr := printRegionDemo(app, d); perr != nil && err == nil {
		err = perr
	}
	return err
}
