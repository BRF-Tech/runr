package cmd

import (
	"fmt"
	"strconv"
	"strings"

	"github.com/spf13/cobra"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/pkg/runr"
)

// Model administration: removing an offline copy and reading its Job logs
// (runr models snapshots rm|logs) and the GPU quota (runr models gpu-quota).

func addModelAdminCommands(app *App, models *cobra.Command) {
	for _, sub := range models.Commands() {
		if sub.Name() == "snapshots" {
			sub.AddCommand(newModelSnapshotRmCmd(app), newModelSnapshotLogsCmd(app))
		}
	}
	models.AddCommand(newModelsGPUQuotaCmd(app))
}

// mdlaSnapshot resolves MODEL and SNAPSHOT (a snapshot id, or the name or id of its region:
// a model has one offline copy per region).
func mdlaSnapshot(cmd *cobra.Command, app *App, modelRef, snapRef string) (*runr.Client, *runr.AIModel, *runr.ModelSnapshot, error) {
	c, err := app.Client()
	if err != nil {
		return nil, nil, nil, err
	}
	m, err := c.FindModel(cmd.Context(), modelRef)
	if err != nil {
		return nil, nil, nil, err
	}
	list, err := c.ListSnapshots(cmd.Context(), m.ID)
	if err != nil {
		return nil, nil, nil, err
	}
	for i := range list {
		if list[i].ID == snapRef {
			return c, m, &list[i], nil
		}
	}
	for i := range list {
		if list[i].RegionID == snapRef || strings.EqualFold(list[i].RegionName, snapRef) {
			return c, m, &list[i], nil
		}
	}
	return nil, nil, nil, &runr.NotFoundError{Kind: "offline copy of " + m.Name, Ref: snapRef}
}

func newModelSnapshotRmCmd(app *App) *cobra.Command {
	var yes bool
	cmd := &cobra.Command{
		Use:     "rm MODEL SNAPSHOT",
		Aliases: []string{"remove", "delete"},
		Short:   "Remove an offline copy of a model (models.manage; refused while services use it)",
		Long: "Remove the offline copy of a model in a region: its files leave the region's model volume.\n" +
			"SNAPSHOT is the copy's id or its region. A copy that offline model services use is refused\n" +
			"(exit code 5).",
		Example: "  runr models snapshots rm minilm eu-1 --yes",
		Args:    cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, m, sn, err := mdlaSnapshot(cmd, app, args[0], args[1])
			if err != nil {
				return err
			}
			where := output.Dash(sn.RegionName)
			if err := app.confirm("Remove the offline copy of "+m.Name+" in "+where, yes); err != nil {
				return err
			}
			if err := c.DeleteSnapshot(cmd.Context(), m.ID, sn.ID); err != nil {
				return err
			}
			app.Progress("Removed the offline copy of %s in %s.", m.Name, where)
			return app.Printer().Print(map[string]any{"model": m.Name, "snapshot": sn.ID, "region": sn.RegionName, "deleted": true},
				func(*output.TableWriter) {})
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

func newModelSnapshotLogsCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:     "logs MODEL SNAPSHOT",
		Short:   "Logs of the mirror or import Job of an offline copy",
		Example: "  runr models snapshots logs minilm eu-1",
		Args:    cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, m, sn, err := mdlaSnapshot(cmd, app, args[0], args[1])
			if err != nil {
				return err
			}
			logs, err := c.SnapshotLogs(cmd.Context(), m.ID, sn.ID)
			if err != nil {
				return err
			}
			if app.Printer().Machine() {
				return app.Printer().Print(map[string]any{"model": m.Name, "snapshot": sn.ID, "region": sn.RegionName,
					"status": sn.Status, "logs": logs}, nil)
			}
			if strings.TrimSpace(logs) == "" {
				app.Progress("No logs (the Job of the copy has ended and its pod is gone, or it has not started).")
				return nil
			}
			writeLogs(app, logs)
			return nil
		},
	}
}

func newModelsGPUQuotaCmd(app *App) *cobra.Command {
	var region string
	cmd := &cobra.Command{
		Use:   "gpu-quota",
		Short: "The organization's GPU quota on a region and how many GPUs are in use",
		Long: "GPUs the organization's GPU projects and model services may reserve on a region (count ×\n" +
			"max(1, min scale)) and how many they hold. `set` changes an organization's quota (platform\n" +
			"administrators of a multi-tenant installation).",
		Example: "  runr models gpu-quota --region eu-1\n" +
			"  runr models gpu-quota set --tenant 01J… --region eu-1 --gpus 4",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			reg, err := c.FindRegion(cmd.Context(), region)
			if err != nil {
				return err
			}
			q, err := c.GPUQuota(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(q, func(t *output.TableWriter) {
				t.KV("Region", reg.Name)
				t.KV("Quota", mdlaQuotaLabel(q.Quota))
				t.KV("In use", strconv.Itoa(q.Used))
				t.KV("Remaining", mdlaQuotaLabel(q.Remaining))
			})
		},
	}
	cmd.Flags().StringVar(&region, "region", "", "region name or id (default: the only active region)")

	var tenant, setRegion string
	var gpus int
	var unlimited bool
	set := &cobra.Command{
		Use:   "set --tenant TENANT --region REGION (--gpus N | --unlimited)",
		Short: "Set an organization's GPU quota on a region (platform administrators)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			fl := cmd.Flags()
			if tenant == "" {
				return usageErrorf("give --tenant (the organization's id)")
			}
			if fl.Changed("gpus") == unlimited {
				return usageErrorf("give --gpus N or --unlimited")
			}
			if fl.Changed("gpus") && gpus < 0 {
				return usageErrorf("--gpus must be 0 or more (0 allows no GPUs)")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			reg, err := c.FindRegion(cmd.Context(), setRegion)
			if err != nil {
				return err
			}
			var quota *int
			if !unlimited {
				quota = &gpus
			}
			res, err := c.SetTenantGPUQuota(cmd.Context(), tenant, reg.ID, quota)
			if runr.IsNotFound(err) {
				return fmt.Errorf("%w (the organization has no access to %s, or this installation is single-tenant)", err, reg.Name)
			}
			if err != nil {
				return err
			}
			app.Progress("GPU quota of %s on %s: %s.", res.TenantID, reg.Name, mdlaQuotaLabel(res.GPUQuota))
			return app.Printer().Print(res, func(t *output.TableWriter) {
				t.KV("Organization", res.TenantID)
				t.KV("Region", reg.Name)
				t.KV("Quota", mdlaQuotaLabel(res.GPUQuota))
			})
		},
	}
	set.Flags().StringVar(&tenant, "tenant", "", "organization (tenant) id")
	set.Flags().StringVar(&setRegion, "region", "", "region name or id (default: the only active region)")
	set.Flags().IntVar(&gpus, "gpus", 0, "GPUs the organization may reserve (0: none)")
	set.Flags().BoolVar(&unlimited, "unlimited", false, "no GPU limit")
	cmd.AddCommand(set)
	return cmd
}

func mdlaQuotaLabel(n *int) string {
	if n == nil {
		return "unlimited"
	}
	return strconv.Itoa(*n)
}
