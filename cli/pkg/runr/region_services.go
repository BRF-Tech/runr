package runr

import (
	"context"
	"encoding/json"
	"net/http"
	"time"
)

// The services of a region besides Knative: the queue Redis (KnativeRedisController), the image
// registry and its setup flow (RegistryController, RegistrySetupController), the move to another
// cluster (RegionMigrateController), the TLS certificate of function domains
// (KnativeFunctionTlsController), Skupper for private database connections
// (PrivateDatabaseBindingController), the capacity budgets (RegionCapacityController) and the demo
// projects (RegionDemoController).

// Redis modes of a region: RunR installs it (managed), or the region uses one in its cluster
// (in_cluster) or outside it (external). none (on create) means no Redis.
var RedisModes = []string{"managed", "in_cluster", "external"}

// RegionRedisStatus is GET /knative-regions/{region}/redis-status: the Redis queue triggers use.
type RegionRedisStatus struct {
	Mode          string `json:"redis_mode,omitempty"`
	Status        string `json:"knative_redis_status,omitempty"`
	Address       string `json:"redis_address,omitempty"`
	StatusMessage string `json:"knative_redis_status_message,omitempty"`
	HasRedis      bool   `json:"has_redis"`
}

// RegionRedis returns the queue Redis of a region (regions.view).
func (c *Client) RegionRedis(ctx context.Context, regionID string) (*RegionRedisStatus, error) {
	var out RegionRedisStatus
	if err := c.do(ctx, request{method: http.MethodGet, path: regionPath(regionID, "/redis-status")}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// RedisTest is the answer of POST /knative-regions/{region}/test-redis.
type RedisTest struct {
	Reachable bool   `json:"reachable"`
	Message   string `json:"message"`
}

// TestRegionRedis checks from a pod in the region's cluster that a Redis answers PING
// (regions.manage).
func (c *Client) TestRegionRedis(ctx context.Context, regionID, address, password string) (*RedisTest, error) {
	body := map[string]string{"address": address}
	if password != "" {
		body["password"] = password
	}
	var out RedisTest
	if err := c.do(ctx, request{method: http.MethodPost, path: regionPath(regionID, "/test-redis"), body: body}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// RedisConfig is the body of POST /knative-regions/{region}/redis.
type RedisConfig struct {
	// Mode is managed, in_cluster or external; in_cluster and external need Address.
	Mode     string `json:"mode"`
	Address  string `json:"address,omitempty"`
	Password string `json:"password,omitempty"`
}

// ConfigureRegionRedis queues the install (managed) or the connection of the region's queue
// Redis (regions.manage).
func (c *Client) ConfigureRegionRedis(ctx context.Context, regionID string, in RedisConfig) (*RegionOperation, error) {
	return c.regionOperation(ctx, http.MethodPost, regionPath(regionID, "/redis"), in)
}

// RemoveRegionRedis queues the removal of the region's queue Redis (regions.manage).
func (c *Client) RemoveRegionRedis(ctx context.Context, regionID string) (*RegionOperation, error) {
	return c.regionOperation(ctx, http.MethodDelete, regionPath(regionID, "/redis"), nil)
}

// WaitRegionRedis polls the queue Redis until its install or removal ends (ready, or removed);
// a *FailedError for error.
func (c *Client) WaitRegionRedis(ctx context.Context, regionID, regionName string, opts WaitOptions) (*RegionRedisStatus, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	what := "the Redis of " + regionName
	last := ""
	for {
		cur, err := c.RegionRedis(ctx, regionID)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		switch cur.Status {
		case KnativeInstalling, KnativeUninstalling:
		case KnativeError:
			return cur, &FailedError{What: "Redis of region", Ref: regionName, Status: cur.Status, Message: lastLine(cur.StatusMessage)}
		default:
			return cur, nil
		}
		if cur.Status != last {
			last = cur.Status
			if opts.Progress != nil {
				opts.Progress(last)
			}
		}
		select {
		case <-ctx.Done():
			return cur, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
	}
}

// RegionRegistryStatus is GET /knative-regions/{region}/registry-status: the image registry
// builds push to.
type RegionRegistryStatus struct {
	Mode          string `json:"registry_mode,omitempty"`
	Address       string `json:"registry_address,omitempty"`
	Status        string `json:"registry_status,omitempty"`
	StatusMessage string `json:"registry_status_message,omitempty"`
	HasRegistry   bool   `json:"has_registry"`
}

// RegionRegistry returns the image registry of a region (regions.view).
func (c *Client) RegionRegistry(ctx context.Context, regionID string) (*RegionRegistryStatus, error) {
	var out RegionRegistryStatus
	if err := c.do(ctx, request{method: http.MethodGet, path: regionPath(regionID, "/registry-status")}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// RemoveRegionRegistry removes the region's registry (registry.manage): RunR's Zot and its
// storage, or the connection to an external registry.
func (c *Client) RemoveRegionRegistry(ctx context.Context, regionID string) (*RegionDetail, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodDelete, path: regionPath(regionID, "/registry")}, &raw); err != nil {
		return nil, err
	}
	var d RegionDetail
	if err := decodeData(raw, &d); err != nil {
		return nil, err
	}
	return &d, nil
}

// RegistryPVC is the volume of an internal registry on a StorageClass.
type RegistryPVC struct {
	StorageClass string `json:"storageClass"`
	Size         string `json:"size"`
}

// RegistryS3 is the S3 bucket of an internal registry. AccessKey and SecretKey are credentials.
type RegistryS3 struct {
	Endpoint  string `json:"endpoint"`
	Bucket    string `json:"bucket"`
	Region    string `json:"region"`
	AccessKey string `json:"access_key"`
	SecretKey string `json:"secret_key"`
}

// RegistrySetupInput is the body of POST /registry-setup/start: a new region with an image
// registry, created in the background.
type RegistrySetupInput struct {
	RegionCreateInput
	RegistryConfig
}

// RegistryConfig is the registry of a region: of a new one (RegistrySetupInput) or an existing
// one (ConfigureRegionRegistry).
type RegistryConfig struct {
	// RegistryMode is internal (RunR installs Zot) or external.
	RegistryMode string `json:"registry_mode"`
	// StorageType of an internal registry: pvc (PVCConfig) or s3 (S3Config).
	StorageType string       `json:"storage_type,omitempty"`
	PVCConfig   *RegistryPVC `json:"pvc_config,omitempty"`
	S3Config    *RegistryS3  `json:"s3_config,omitempty"`
	// External registry address and credentials.
	ExternalAddress  string `json:"external_address,omitempty"`
	ExternalUsername string `json:"external_username,omitempty"`
	ExternalPassword string `json:"external_password,omitempty"`
}

// ConfigureRegionRegistry sets up the registry of an existing region in the background
// (registry.manage): one created without, or whose registry was removed; an external registry is
// replaced, an internal one has to be removed first. WaitRegionRegistry follows it.
func (c *Client) ConfigureRegionRegistry(ctx context.Context, regionID string, cfg RegistryConfig) (*RegionOperation, error) {
	return c.regionOperation(ctx, http.MethodPost, regionPath(regionID, "/registry"), cfg)
}

// WaitRegionRegistry polls a region until its registry setup ends (a *FailedError for error).
func (c *Client) WaitRegionRegistry(ctx context.Context, regionID, regionName string, opts WaitOptions) (*RegionDetail, error) {
	return c.waitRegion(ctx, regionID, regionName, "Registry", opts,
		func(d *RegionDetail) (string, string) { return d.RegistryStatus, d.RegistryStatusMessage },
		[]string{KnativeInstalling})
}

// RegistrySetup is the answer of POST /registry-setup/start and …/retry: the token to poll.
type RegistrySetup struct {
	Token   string `json:"setup_token"`
	SetupID string `json:"setup_id"`
}

// StartRegistrySetup creates a region with an image registry in the background
// (regions.manage and registry.manage); RegistrySetupStatus follows it.
func (c *Client) StartRegistrySetup(ctx context.Context, in RegistrySetupInput) (*RegistrySetup, error) {
	if in.Nodes == nil {
		in.Nodes = []string{}
	}
	var out RegistrySetup
	if err := c.do(ctx, request{method: http.MethodPost, path: "/registry-setup/start", body: in}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// RetryRegistrySetup starts a failed registry setup again with (possibly changed) settings.
func (c *Client) RetryRegistrySetup(ctx context.Context, token string, in RegistrySetupInput) (*RegistrySetup, error) {
	if in.Nodes == nil {
		in.Nodes = []string{}
	}
	var out RegistrySetup
	if err := c.do(ctx, request{method: http.MethodPost, path: "/registry-setup/" + seg(token) + "/retry", body: in}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// Statuses of a registry setup.
const (
	RegistrySetupPending = "pending"
	RegistrySetupReady   = "ready"
	RegistrySetupError   = "error"
)

// RegistrySetupStatus is GET /registry-setup/{token}/status (kept 30 minutes).
type RegistrySetupStatus struct {
	// Status is pending, ready or error; Phase the step it is at.
	Status   string `json:"status"`
	Phase    string `json:"phase"`
	Message  string `json:"message"`
	RegionID string `json:"region_id,omitempty"`
	Address  string `json:"address,omitempty"`
}

// UnmarshalJSON accepts region_id as a string or a number.
func (s *RegistrySetupStatus) UnmarshalJSON(b []byte) error {
	type plain RegistrySetupStatus
	var raw struct {
		plain
		RegionID FlexID `json:"region_id"`
	}
	if err := json.Unmarshal(b, &raw); err != nil {
		return err
	}
	*s = RegistrySetupStatus(raw.plain)
	s.RegionID = raw.RegionID.String()
	return nil
}

// RegistrySetupState returns the progress of a registry setup (regions.view).
func (c *Client) RegistrySetupState(ctx context.Context, token string) (*RegistrySetupStatus, error) {
	var out RegistrySetupStatus
	if err := c.do(ctx, request{method: http.MethodGet, path: "/registry-setup/" + seg(token) + "/status"}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// WaitRegistrySetup polls a registry setup until the region exists (ready) or the setup failed
// (a *FailedError; RetryRegistrySetup starts it again). label names the setup in errors ("of
// region eu-2", or the token).
func (c *Client) WaitRegistrySetup(ctx context.Context, token, label string, opts WaitOptions) (*RegistrySetupStatus, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	what := "the registry setup " + label
	last := ""
	for {
		cur, err := c.RegistrySetupState(ctx, token)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		switch cur.Status {
		case RegistrySetupReady:
			return cur, nil
		case RegistrySetupError:
			return cur, &FailedError{What: "registry setup", Ref: label, Status: cur.Status, Message: cur.Message}
		}
		if p := cur.Phase; p != last {
			last = p
			if opts.Progress != nil {
				opts.Progress(p)
			}
		}
		select {
		case <-ctx.Done():
			return cur, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
	}
}

// RegistryTest is the answer of the registry-setup tests.
type RegistryTest struct {
	Success bool   `json:"success"`
	Message string `json:"message"`
}

// TestRegistryPVC checks on a cluster that a StorageClass provisions a volume of size
// (registry.manage). The API waits up to its PVC test time (RUNR_REGISTRY_PVC_TEST_SECONDS,
// 180 s by default) for the volume, so the call has no overall HTTP timeout (bounded by ctx).
func (c *Client) TestRegistryPVC(ctx context.Context, kubeconfig string, pvc RegistryPVC, namespace string) (*RegistryTest, error) {
	body := map[string]any{"kubeconfig": kubeconfig, "storageClass": pvc.StorageClass, "size": pvc.Size}
	if namespace != "" {
		body["namespace"] = namespace
	}
	var out RegistryTest
	if err := c.long().do(ctx, request{method: http.MethodPost, path: "/registry-setup/test-pvc", body: body}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// TestRegistryS3 checks from a pod in a cluster that an S3 bucket is reachable with its
// credentials (registry.manage).
func (c *Client) TestRegistryS3(ctx context.Context, kubeconfig string, s3 RegistryS3, namespace string) (*RegistryTest, error) {
	body := map[string]any{"kubeconfig": kubeconfig, "s3_config": s3}
	if namespace != "" {
		body["namespace"] = namespace
	}
	var out RegistryTest
	if err := c.do(ctx, request{method: http.MethodPost, path: "/registry-setup/test-s3", body: body}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// TestRegistryExternal checks from a pod in a cluster that an external registry answers with
// its credentials (registry.manage).
func (c *Client) TestRegistryExternal(ctx context.Context, kubeconfig, address, username, password, namespace string) (*RegistryTest, error) {
	body := map[string]any{"kubeconfig": kubeconfig, "url": address}
	if username != "" {
		body["username"] = username
	}
	if password != "" {
		body["password"] = password
	}
	if namespace != "" {
		body["namespace"] = namespace
	}
	var out RegistryTest
	if err := c.do(ctx, request{method: http.MethodPost, path: "/registry-setup/test-external", body: body}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// Statuses of a region migration.
const (
	MigrationPending    = "pending"
	MigrationInProgress = "in_progress"
	MigrationDNSCheck   = "dns_check"
	MigrationCompleted  = "completed"
	MigrationFailed     = "failed"
)

// RegionMigration is GET /knative-regions/{region}/migration-status.
type RegionMigration struct {
	// Status is pending, in_progress, dns_check (the workloads run on the new cluster; point DNS
	// at CurrentIP, then complete), completed or failed; empty when no migration ran.
	Status      string `json:"migration_status,omitempty"`
	StartedAt   string `json:"migration_started_at,omitempty"`
	OldIP       string `json:"migration_old_ip,omitempty"`
	CurrentIP   string `json:"current_ip,omitempty"`
	IsMigrating bool   `json:"is_migrating"`
	// Projects is what the move did with each project of the region (projects that run
	// nothing are not listed), as long as the move can be rolled back; empty otherwise and on
	// APIs that do not report it.
	Projects []MigrationProject `json:"projects,omitempty"`
}

// Outcomes of a project in a region migration (MigrationProject.Outcome).
const (
	MigrationMoved     = "moved"
	MigrationBuilding  = "building"
	MigrationDeployed  = "deployed"
	MigrationNotMoved  = "not_moved"
	MigrationCancelled = "cancelled"
)

// MigrationProject is what a region migration did with one project.
type MigrationProject struct {
	// Organization is the slug of the organization a platform region's project belongs to in
	// a multi-tenant install; empty otherwise.
	Organization string `json:"organization,omitempty"`
	ProjectID    string `json:"project_id"`
	Project      string `json:"project"`
	// Environment names the environment of the project the row is about (docs/environments.md);
	// empty for the project's own workload.
	Environment string `json:"environment,omitempty"`
	// Outcome is moved (deployed on the new cluster), building (built again there; it runs on
	// the old cluster until it is deployed), deployed (built again and deployed), not_moved
	// (refused, or its build or deploy failed: Message says why) or cancelled (its waiting
	// deploy was replaced by a newer deploy of the project).
	Outcome string `json:"outcome"`
	// Version is the version's number (0 when unknown).
	Version int `json:"version,omitempty"`
	// DeploymentStatus is the status of its deployment on the new cluster, if any.
	DeploymentStatus string `json:"deployment_status,omitempty"`
	Message          string `json:"message,omitempty"`
}

// RegionMigrationState returns the migration state of a region (regions.view).
func (c *Client) RegionMigrationState(ctx context.Context, regionID string) (*RegionMigration, error) {
	var out RegionMigration
	if err := c.do(ctx, request{method: http.MethodGet, path: regionPath(regionID, "/migration-status")}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// MigrationAnswer is the answer of the migration steps.
type MigrationAnswer struct {
	Message string `json:"message"`
	Status  string `json:"status,omitempty"`
}

// StartRegionMigration moves a region to another cluster (regions.manage): RunR installs the
// region's components there and deploys its projects again; a region with a data location moves
// only within it (dataLocation names the new cluster's).
func (c *Client) StartRegionMigration(ctx context.Context, regionID, kubeconfig, dataLocation string) (*MigrationAnswer, error) {
	body := map[string]string{"kubeconfig": kubeconfig}
	if dataLocation != "" {
		body["data_location"] = dataLocation
	}
	var out MigrationAnswer
	if err := c.do(ctx, request{method: http.MethodPost, path: regionPath(regionID, "/migrate"), body: body}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// CompleteRegionMigration ends a migration in dns_check: the old cluster is released
// (regions.manage).
func (c *Client) CompleteRegionMigration(ctx context.Context, regionID string) (*MigrationAnswer, error) {
	var out MigrationAnswer
	if err := c.do(ctx, request{method: http.MethodPost, path: regionPath(regionID, "/complete-migration")}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// RollbackRegionMigration returns a migration in dns_check or failed to the old cluster
// (regions.manage).
func (c *Client) RollbackRegionMigration(ctx context.Context, regionID string) (*MigrationAnswer, error) {
	var out MigrationAnswer
	if err := c.do(ctx, request{method: http.MethodPost, path: regionPath(regionID, "/rollback-migration")}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// WaitRegionMigration polls a migration until it leaves pending / in_progress: dns_check (the
// next step is DNS and complete), completed, or a *FailedError for failed.
func (c *Client) WaitRegionMigration(ctx context.Context, regionID, regionName string, opts WaitOptions) (*RegionMigration, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	what := "the migration of " + regionName
	last := ""
	for {
		cur, err := c.RegionMigrationState(ctx, regionID)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		switch cur.Status {
		case MigrationPending, MigrationInProgress:
		case MigrationFailed:
			return cur, &FailedError{What: "migration of region", Ref: regionName, Status: cur.Status}
		default:
			return cur, nil
		}
		if cur.Status != last {
			last = cur.Status
			if opts.Progress != nil {
				opts.Progress(last)
			}
		}
		select {
		case <-ctx.Done():
			return cur, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
	}
}

// Function-domain TLS modes: the console's certificate (console) or one of your own (custom).
const (
	FunctionTLSConsole = "console"
	FunctionTLSCustom  = "custom"
)

// FunctionTLSInput is the body of PUT /knative-regions/{region}/function-tls. PrivateKey is a
// credential.
type FunctionTLSInput struct {
	Mode        string `json:"mode"`
	Certificate string `json:"certificate,omitempty"`
	PrivateKey  string `json:"privateKey,omitempty"`
	// ReplaceExisting replaces a default TLSStore of the region's Traefik RunR did not create.
	ReplaceExisting bool `json:"replace_existing,omitempty"`
}

// SetRegionFunctionTLS sets the certificate the region's Traefik serves function domains with
// (regions.manage).
func (c *Client) SetRegionFunctionTLS(ctx context.Context, regionID string, in FunctionTLSInput) (*RegionOperation, error) {
	return c.regionOperation(ctx, http.MethodPut, regionPath(regionID, "/function-tls"), in)
}

// RemoveRegionFunctionTLS switches function-domain TLS off (regions.manage): the region's
// Traefik answers HTTPS with its own default certificate again.
func (c *Client) RemoveRegionFunctionTLS(ctx context.Context, regionID string) (*RegionOperation, error) {
	return c.regionOperation(ctx, http.MethodDelete, regionPath(regionID, "/function-tls"), nil)
}

// RegionMonitoringInput is PUT /knative-regions/{region}/monitoring: a key set to a URL points
// the region at an existing in-cluster Prometheus / Loki, set to nil back at RunR's own; keys
// left out are kept.
type RegionMonitoringInput map[string]any

// UpdateRegionMonitoring changes the region's Prometheus and Loki (regions.manage).
func (c *Client) UpdateRegionMonitoring(ctx context.Context, regionID string, in RegionMonitoringInput) (*RegionOperation, error) {
	return c.regionOperation(ctx, http.MethodPut, regionPath(regionID, "/monitoring"), in)
}

// CompleteRegionDomainMigration completes a base-domain migration of the region without waiting
// for its DNS check (regions.manage): the new function domain becomes the region's.
func (c *Client) CompleteRegionDomainMigration(ctx context.Context, regionID string) (*RegionOperation, error) {
	return c.regionOperation(ctx, http.MethodPost, regionPath(regionID, "/complete-domain-migration"), nil)
}

// SkupperSite is the Skupper site of a region's project namespace.
type SkupperSite struct {
	Name    string `json:"name"`
	Status  string `json:"status"`
	Message string `json:"message,omitempty"`
}

// RegionSkupper is GET /knative-regions/{region}/skupper: whether the cluster runs Skupper v2
// (private database connections) and the project namespace's site.
type RegionSkupper struct {
	Available  bool         `json:"available"`
	APIVersion string       `json:"api_version,omitempty"`
	Namespace  string       `json:"namespace,omitempty"`
	Site       *SkupperSite `json:"site"`
}

// RegionSkupper returns the Skupper state of a region (regions.view).
func (c *Client) RegionSkupper(ctx context.Context, regionID string) (*RegionSkupper, error) {
	var out struct {
		Data RegionSkupper `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: regionPath(regionID, "/skupper")}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// EnsureRegionSkupperSite creates the Skupper site of the region's project namespace when it
// has none (regions.manage); refused (422) when the cluster does not run Skupper.
func (c *Client) EnsureRegionSkupperSite(ctx context.Context, regionID string) (*SkupperSite, error) {
	var out struct {
		Data SkupperSite `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: regionPath(regionID, "/skupper/site")}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// CapacityBudget is one resource (CPU in millicores or memory in MiB) of a region's capacity: the
// always-on budget (the capacity, no overcommit) and the FaaS overcommit budget.
type CapacityBudget struct {
	Capacity          int      `json:"capacity"`
	AlwaysOnUsed      int      `json:"always_on_used"`
	AlwaysOnAvailable int      `json:"always_on_available"`
	FaaSOvercommit    float64  `json:"faas_overcommit"`
	FaaSBudget        int      `json:"faas_budget"`
	FaaSUsed          int      `json:"faas_used"`
	FaaSAvailable     int      `json:"faas_available"`
	Utilization       *float64 `json:"utilization"`
}

// RegionCapacityState is GET /knative-regions/{region}/capacity.
type RegionCapacityState struct {
	RegionID             string         `json:"region_id"`
	FaaSCPUOvercommit    float64        `json:"faas_cpu_overcommit"`
	FaaSMemoryOvercommit float64        `json:"faas_memory_overcommit"`
	CPUm                 CapacityBudget `json:"cpu_m"`
	MemoryMB             CapacityBudget `json:"memory_mb"`
	Message              string         `json:"message,omitempty"`
}

// RegionCapacity returns a region's capacity budgets and FaaS overcommit factors (regions.view).
func (c *Client) RegionCapacity(ctx context.Context, regionID string) (*RegionCapacityState, error) {
	var out struct {
		Data RegionCapacityState `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: regionPath(regionID, "/capacity")}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// SetRegionCapacity sets a region's FaaS overcommit factors (1-10; 1 = every reservation against
// the capacity); nil keeps one (regions.manage).
func (c *Client) SetRegionCapacity(ctx context.Context, regionID string, cpu, memory *float64) (*RegionCapacityState, error) {
	body := map[string]float64{}
	if cpu != nil {
		body["faas_cpu_overcommit"] = *cpu
	}
	if memory != nil {
		body["faas_memory_overcommit"] = *memory
	}
	var out struct {
		Message string              `json:"message"`
		Data    RegionCapacityState `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPut, path: regionPath(regionID, "/capacity"), body: body}, &out); err != nil {
		return nil, err
	}
	out.Data.Message = out.Message
	return &out.Data, nil
}

// Statuses of a region's demo projects.
const (
	DemoSeeding  = "seeding"
	DemoReady    = "ready"
	DemoRemoving = "removing"
	DemoError    = "error"
)

// SeedRegionDemo deploys the demo projects to a region (regions.manage); it runs in the
// background and returns the demo status (seeding).
func (c *Client) SeedRegionDemo(ctx context.Context, regionID string) (string, error) {
	var out struct {
		Status string `json:"region_demo_status"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: regionPath(regionID, "/demo-projects")}, &out)
	return out.Status, err
}

// RemoveRegionDemo removes a region's demo projects (regions.manage), in the background.
func (c *Client) RemoveRegionDemo(ctx context.Context, regionID string) (string, error) {
	var out struct {
		Status string `json:"region_demo_status"`
	}
	err := c.do(ctx, request{method: http.MethodDelete, path: regionPath(regionID, "/demo-projects")}, &out)
	return out.Status, err
}

// WaitRegionDemo polls a region until its demo projects are deployed or removed; a
// *FailedError for error.
func (c *Client) WaitRegionDemo(ctx context.Context, regionID, regionName string, opts WaitOptions) (*RegionDetail, error) {
	return c.waitRegion(ctx, regionID, regionName, "demo projects", opts,
		func(d *RegionDetail) (string, string) { return d.DemoStatus, "" }, []string{DemoSeeding, DemoRemoving})
}
