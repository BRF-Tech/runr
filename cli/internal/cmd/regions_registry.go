package cmd

import (
	"context"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// registryFlags are the registry flags of runr regions create --registry and runr regions
// registry set.
type registryFlags struct {
	registry, storage, storageClass, size, s3File, registryAddress, credFile string
}

func (f *regionCreateFlags) registryFlags() registryFlags {
	return registryFlags{registry: f.registry, storage: f.storage, storageClass: f.storageClass, size: f.size,
		s3File: f.s3File, registryAddress: f.registryAddress, credFile: f.credFile}
}

// clusterDefaultStorageClass is the cluster's default StorageClass ("" when it has none).
func clusterDefaultStorageClass(ctx context.Context, c *runr.Client, kubeconfig string) (string, error) {
	classes, err := c.PreviewStorageClasses(ctx, kubeconfig)
	if err != nil {
		return "", err
	}
	for _, sc := range classes {
		if sc.IsDefault {
			return sc.Name, nil
		}
	}
	return "", nil
}

// readRegistryConfig reads the registry flags; defaultClass finds the cluster's default
// StorageClass when an internal registry on a volume has no --storage-class.
func readRegistryConfig(cmd *cobra.Command, app *App, f registryFlags, defaultClass func() (string, error)) (runr.RegistryConfig, error) {
	cfg := runr.RegistryConfig{RegistryMode: f.registry}
	switch f.registry {
	case "internal":
		if f.registryAddress != "" || f.credFile != "" {
			return cfg, usageErrorf("--registry-address and --registry-credentials apply to --registry external")
		}
		switch f.storage {
		case "", "pvc":
			if f.s3File != "" {
				return cfg, usageErrorf("--registry-s3 applies to --registry-storage s3")
			}
			cfg.StorageType = "pvc"
			pvc := &runr.RegistryPVC{StorageClass: f.storageClass, Size: f.size}
			if pvc.Size == "" {
				pvc.Size = "10Gi"
			} else if !radmSize(pvc.Size) {
				return cfg, usageErrorf("--registry-size is a size such as 10Gi or 1Ti")
			}
			if pvc.StorageClass == "" {
				name, err := defaultClass()
				if err != nil {
					return cfg, err
				}
				if name == "" {
					return cfg, usageErrorf("the cluster has no default StorageClass: choose one with --storage-class")
				}
				pvc.StorageClass = name
				app.Progress("Registry volume: StorageClass %s (the cluster's default).", pvc.StorageClass)
			}
			cfg.PVCConfig = pvc
		case "s3":
			if f.storageClass != "" || f.size != "" {
				return cfg, usageErrorf("--storage-class and --registry-size apply to --registry-storage pvc")
			}
			if f.s3File == "" {
				return cfg, usageErrorf("--registry-storage s3 needs --registry-s3 FILE")
			}
			kv, err := rlcReadKeyValues(app, "registry-s3", f.s3File, registryS3Keys)
			if err != nil {
				return cfg, err
			}
			s3 := &runr.RegistryS3{Endpoint: kv["endpoint"], Bucket: kv["bucket"], Region: kv["region"], AccessKey: kv["access_key"],
				SecretKey: kv["secret_key"]}
			for k, v := range map[string]string{"ENDPOINT": s3.Endpoint, "BUCKET": s3.Bucket, "REGION": s3.Region,
				"ACCESS_KEY": s3.AccessKey, "SECRET_KEY": s3.SecretKey} {
				if v == "" {
					return cfg, usageErrorf("--registry-s3 has no %s", k)
				}
			}
			cfg.StorageType, cfg.S3Config = "s3", s3
		default:
			return cfg, usageErrorf("--registry-storage must be pvc or s3")
		}
	case "external":
		for _, fl := range []string{"registry-storage", "storage-class", "registry-size", "registry-s3"} {
			if cmd.Flags().Changed(fl) {
				return cfg, usageErrorf("--%s applies to --registry internal", fl)
			}
		}
		if f.registryAddress == "" {
			return cfg, usageErrorf("--registry external needs --registry-address")
		}
		cfg.ExternalAddress = f.registryAddress
		if f.credFile != "" {
			kv, err := rlcReadKeyValues(app, "registry-credentials", f.credFile, registryCredentialKeys)
			if err != nil {
				return cfg, err
			}
			cfg.ExternalUsername, cfg.ExternalPassword = kv["username"], kv["password"]
		}
	default:
		return cfg, usageErrorf("--registry must be internal or external")
	}
	return cfg, nil
}

func newRegionRegistrySetCmd(app *App) *cobra.Command {
	var f registryFlags
	var wait bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "set REGION --registry internal|external",
		Short: "Set up the image registry of an existing region (registry.manage)",
		Long: "Set up the registry of a region created without one, or whose registry was removed, with\n" +
			"the flags of runr regions create:\n" +
			"  internal  RunR runs Zot on a volume (--registry-storage pvc, --storage-class, default the\n" +
			"            cluster's default class, --registry-size, default 10Gi) or on S3 (--registry-storage\n" +
			"            s3 --registry-s3 FILE with ENDPOINT, BUCKET, REGION, ACCESS_KEY, SECRET_KEY lines)\n" +
			"  external  --registry-address and --registry-credentials FILE (USERNAME, PASSWORD lines)\n" +
			"An external registry is replaced; an internal one is removed first (runr regions registry rm).\n" +
			"The storage or the registry is tested from the cluster before; the setup runs in the\n" +
			"background: --wait waits (exit code 6 when it fails).",
		Example: "  runr regions registry set eu-1 --registry internal --storage-class fast-ssd --wait\n" +
			"  runr regions registry set eu-1 --registry external --registry-address registry.example.com/team \\\n" +
			"      --registry-credentials registry.env",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if err := rlcOneStdin(map[string]string{"registry-s3": f.s3File, "registry-credentials": f.credFile}); err != nil {
				return err
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			cfg, err := readRegistryConfig(cmd, app, f, func() (string, error) {
				kc, err := c.RegionKubeconfig(ctx, reg.ID)
				if err != nil {
					return "", err
				}
				return clusterDefaultStorageClass(ctx, c, string(kc))
			})
			if err != nil {
				return err
			}
			res, err := c.ConfigureRegionRegistry(ctx, reg.ID, cfg)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			if !wait {
				return printRegionOperation(app, reg, res, "runr regions registry "+reg.Name+" shows the progress.")
			}
			d, err := c.WaitRegionRegistry(ctx, reg.ID, reg.Name, app.waitOptions(timeout, "Registry of "+reg.Name))
			if d == nil {
				return err
			}
			if perr := app.Printer().Print(d, func(t *output.TableWriter) { printRegionDetail(t, d) }); perr != nil && err == nil {
				err = perr
			}
			return err
		},
	}
	fs := cmd.Flags()
	fs.StringVar(&f.registry, "registry", "", "internal or external")
	fs.StringVar(&f.storage, "registry-storage", "", "internal registry storage: pvc (default) or s3")
	fs.StringVar(&f.storageClass, "storage-class", "", "pvc: StorageClass of the registry volume (default: the cluster's default)")
	fs.StringVar(&f.size, "registry-size", "", "pvc: size of the registry volume (default 10Gi)")
	fs.StringVar(&f.s3File, "registry-s3", "", "s3: file (or -) with ENDPOINT, BUCKET, REGION, ACCESS_KEY, SECRET_KEY lines")
	fs.StringVar(&f.registryAddress, "registry-address", "", "external: address of the registry (registry.example.com/team)")
	fs.StringVar(&f.credFile, "registry-credentials", "", "external: file (or -) with USERNAME and PASSWORD lines")
	fs.BoolVar(&wait, "wait", false, "wait until the registry is set up (exit code 6 when it fails)")
	fs.DurationVar(&timeout, "timeout", 20*time.Minute, "how long --wait waits")
	return cmd
}
