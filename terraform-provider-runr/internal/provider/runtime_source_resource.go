package provider

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework-validators/int64validator"
	"github.com/hashicorp/terraform-plugin-framework-validators/resourcevalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/booldefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/int64planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerResource(NewRuntimeSourceResource) }

var (
	_ resource.Resource                     = &runtimeSourceResource{}
	_ resource.ResourceWithConfigure        = &runtimeSourceResource{}
	_ resource.ResourceWithImportState      = &runtimeSourceResource{}
	_ resource.ResourceWithConfigValidators = &runtimeSourceResource{}
	_ resource.ResourceWithValidateConfig   = &runtimeSourceResource{}
	_ resource.ResourceWithModifyPlan       = &runtimeSourceResource{}
)

// NewRuntimeSourceResource returns the runr_runtime_source resource.
func NewRuntimeSourceResource() resource.Resource { return &runtimeSourceResource{} }

// A runtime source (RuntimeSourceController): an OCI catalog or a Git repository RunR reads
// runtimes from. The credentials are write-only: the API says which are set (credentials), never
// their value, so Terraform keeps what it set — or nothing with the _wo attributes — and writes
// them again when the API's account of them is no longer what it wrote (changed or removed
// outside Terraform).
type runtimeSourceResource struct{ client *Client }

type runtimeSourceModel struct {
	ID                   types.String `tfsdk:"id"`
	Name                 types.String `tfsdk:"name"`
	Kind                 types.String `tfsdk:"kind"`
	URL                  types.String `tfsdk:"url"`
	Ref                  types.String `tfsdk:"ref"`
	Path                 types.String `tfsdk:"path"`
	PublicKey            types.String `tfsdk:"public_key"`
	PatchFeed            types.String `tfsdk:"patch_feed"`
	RegistryHost         types.String `tfsdk:"registry_host"`
	IntervalMinutes      types.Int64  `tfsdk:"interval_minutes"`
	Enabled              types.Bool   `tfsdk:"enabled"`
	GitToken             types.String `tfsdk:"git_token"`
	GitTokenWO           types.String `tfsdk:"git_token_wo"`
	GitUsername          types.String `tfsdk:"git_username"`
	SSHPrivateKey        types.String `tfsdk:"ssh_private_key"`
	SSHPrivateKeyWO      types.String `tfsdk:"ssh_private_key_wo"`
	SSHKnownHosts        types.String `tfsdk:"ssh_known_hosts"`
	RegistryUsername     types.String `tfsdk:"registry_username"`
	RegistryPassword     types.String `tfsdk:"registry_password"`
	RegistryPasswordWO   types.String `tfsdk:"registry_password_wo"`
	CredentialsWOVersion types.String `tfsdk:"credentials_wo_version"`
	SyncTrigger          types.String `tfsdk:"sync_trigger"`
	Scope                types.String `tfsdk:"scope"`
	Credentials          types.Object `tfsdk:"credentials"`
	Status               types.String `tfsdk:"status"`
	Error                types.String `tfsdk:"error"`
	Problems             types.List   `tfsdk:"problems"`
	Revision             types.String `tfsdk:"revision"`
	SignatureVerified    types.Bool   `tfsdk:"signature_verified"`
	RuntimesCount        types.Int64  `tfsdk:"runtimes_count"`
	CheckedAt            types.String `tfsdk:"checked_at"`
	SyncedAt             types.String `tfsdk:"synced_at"`
}

var (
	runtimeSourceCredentialsAttrTypes = map[string]attr.Type{
		"git": types.StringType, "git_username": types.StringType, "git_token_hint": types.StringType,
		"ssh_key_set": types.BoolType, "known_hosts_set": types.BoolType, "registry_username": types.StringType,
		"registry_password_set": types.BoolType,
	}
	runtimeSourceProblemAttrTypes = map[string]attr.Type{
		"file": types.StringType, "runtime": types.StringType, "path": types.StringType, "message": types.StringType,
	}
)

// writtenCredentialsKey is the private state key of the credentials as the API described them
// after Terraform last wrote them.
const writtenCredentialsKey = "written_credentials"

func (r *runtimeSourceResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_runtime_source"
}

// runtimeSourceCredentialsSchema is the read-only account of the credentials (resource and data source).
func runtimeSourceCredentialsAttributes() map[string]string {
	return map[string]string{
		"git":                   "`token` (HTTPS), `ssh` (a deploy key) or null.",
		"git_username":          "User name of the Git token (null: `x-access-token`).",
		"git_token_hint":        "The token's last four characters behind a mask (tokens longer than eight characters).",
		"ssh_key_set":           "A deploy key is set.",
		"known_hosts_set":       "`known_hosts` is set (without it the host key is accepted on first use).",
		"registry_username":     "The registry user.",
		"registry_password_set": "A registry password is set.",
	}
}

func (r *runtimeSourceResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	str := func(desc string) schema.StringAttribute {
		return schema.StringAttribute{Computed: true, MarkdownDescription: desc}
	}
	creds := map[string]schema.Attribute{}
	for k, desc := range runtimeSourceCredentialsAttributes() {
		if strings.HasSuffix(k, "_set") {
			creds[k] = schema.BoolAttribute{Computed: true, MarkdownDescription: desc}
		} else {
			creds[k] = schema.StringAttribute{Computed: true, MarkdownDescription: desc}
		}
	}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A runtime source ([runtime sources](https://runr.sh/docs/runtimes/#runtime-sources)): an OCI " +
			"catalog of signed manifests (`kind = \"oci-catalog\"`) or a Git repository with `<path>/<id>/runtime.yaml` " +
			"(`kind = \"git\"`), public or private, that RunR reads runtimes from — when it is connected or changed, every " +
			"`interval_minutes` and when `sync_trigger` changes. A platform source (a token with `runtimes.manage`) gives every " +
			"organization its runtimes; a source of the organization (`runtimes.use_custom`, while the platform allows custom " +
			"runtimes) only its members.\n\n" +
			"A read that fails (`status` `unreachable`, `not_found`, `auth_failed`, `invalid_signature`, `invalid_catalog`) leaves the " +
			"source's runtimes as they were and is a warning, not an error: the source is connected. A runtime manifest with " +
			"problems is refused (`status = \"partial\"`, `problems` with file and JSON path), the others are read. Check the " +
			"read with a `postcondition` on `status` where it must succeed.\n\n" +
			"The credentials are write-only: RunR never returns them (`credentials` says which are set). Terraform keeps what " +
			"it set (`git_token`, `ssh_private_key`, `registry_password`, sensitive), or with the `_wo` attributes (Terraform " +
			"1.11 and later) nothing — change `credentials_wo_version` to write new ones. Credentials changed or removed " +
			"outside Terraform are written again. Destroying the source deletes its runtimes no project uses; the others stay, " +
			"orphaned, until their sunset. RunR's own catalog (the default source) is the installation's: read it with " +
			"`data.runr_runtime_source`. Import with the source's id or name (the credentials are not imported).",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Source id."},
			"name": schema.StringAttribute{Required: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 64)},
				MarkdownDescription: "Name, unique in its scope (at most 64 characters)."},
			"kind": schema.StringAttribute{Required: true, Validators: []validator.String{stringvalidator.OneOf(runr.RuntimeSourceGit, runr.RuntimeSourceOCI)},
				MarkdownDescription: "`git` (a repository of `<path>/<id>/runtime.yaml`) or `oci-catalog` (a signed catalog of manifests)."},
			"url": schema.StringAttribute{Required: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 512)},
				MarkdownDescription: "The repository (`https://…` or `git@host:owner/repo.git`) or the catalog's OCI reference (`registry/repo:tag`)."},
			"ref": schema.StringAttribute{Optional: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 255)},
				MarkdownDescription: "`git`: branch, tag or commit (default the repository's default branch)."},
			"path": schema.StringAttribute{Optional: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 255)},
				MarkdownDescription: "`git`: directory of the runtimes (default `runtimes`)."},
			"public_key": schema.StringAttribute{Optional: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 8192)},
				MarkdownDescription: "cosign public key (PEM) the catalog and the patch feed must verify with, e.g. `file(\"cosign.pub\")`."},
			"patch_feed": schema.StringAttribute{Optional: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 512)},
				MarkdownDescription: "OCI reference of the source's signed runtime patch feed (needs `public_key`)."},
			"registry_host": schema.StringAttribute{Optional: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 255)},
				MarkdownDescription: "Registry the registry credentials are for (`ghcr.io`, `registry.example.com:5000`); required with registry credentials of a `git` source (the registry of its runtime images)."},
			"interval_minutes": schema.Int64Attribute{Optional: true, Computed: true,
				PlanModifiers:       []planmodifier.Int64{int64planmodifier.UseStateForUnknown()},
				Validators:          []validator.Int64{int64validator.Between(1, 10080)},
				MarkdownDescription: "Minutes between reads: at least the platform's minimum (5 unless configured otherwise), at most a week. Left out: 60 for a new source, an existing one keeps its interval."},
			"enabled": schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(true),
				MarkdownDescription: "Whether RunR reads the source on its interval (default `true`); off, its runtimes stay as last read."},
			"git_token": schema.StringAttribute{Optional: true, Sensitive: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 4096)},
				MarkdownDescription: "`git` over HTTPS: access token (sensitive). One of `git_token`, `git_token_wo`, `ssh_private_key` and `ssh_private_key_wo` at most."},
			"git_token_wo": schema.StringAttribute{Optional: true, Sensitive: true, WriteOnly: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 4096)},
				MarkdownDescription: "`git_token` that Terraform never stores (write-only, Terraform 1.11 and later); change `credentials_wo_version` to write a new one."},
			"git_username": schema.StringAttribute{Optional: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 255)},
				MarkdownDescription: "`git` over HTTPS: user name of the token (default `x-access-token`)."},
			"ssh_private_key": schema.StringAttribute{Optional: true, Sensitive: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 16384)},
				MarkdownDescription: "`git` over SSH (`git@…` URL): the deploy key (sensitive), e.g. `file(\"deploy_key\")`."},
			"ssh_private_key_wo": schema.StringAttribute{Optional: true, Sensitive: true, WriteOnly: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 16384)},
				MarkdownDescription: "`ssh_private_key` that Terraform never stores (write-only, Terraform 1.11 and later); change `credentials_wo_version` to write a new one."},
			"ssh_known_hosts": schema.StringAttribute{Optional: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 16384)},
				MarkdownDescription: "`git` over SSH: `known_hosts` of the host; without it the host key is accepted on first use."},
			"registry_username": schema.StringAttribute{Optional: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 255)},
				MarkdownDescription: "Registry user for a private catalog, patch feed or runtime images (with `registry_password` or `registry_password_wo`)."},
			"registry_password": schema.StringAttribute{Optional: true, Sensitive: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 4096)},
				MarkdownDescription: "Registry password or token (sensitive)."},
			"registry_password_wo": schema.StringAttribute{Optional: true, Sensitive: true, WriteOnly: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 4096)},
				MarkdownDescription: "`registry_password` that Terraform never stores (write-only, Terraform 1.11 and later); change `credentials_wo_version` to write a new one."},
			"credentials_wo_version": schema.StringAttribute{Optional: true,
				MarkdownDescription: "Any string; a change writes the `_wo` credentials again (for example a rotation date)."},
			"sync_trigger": schema.StringAttribute{Optional: true,
				MarkdownDescription: "Any string; a change reads the source now (for example the tag or commit runtimes were released with). Every other change reads it as well."},
			"scope": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "`platform` (connected with `runtimes.manage`: every organization) or `organization`."},
			"credentials": schema.SingleNestedAttribute{Computed: true, Attributes: creds,
				MarkdownDescription: "Which credentials the source has, as RunR reports them (never a value)."},
			"status": str("The last read: `never`, `ok`, `partial` (some manifests refused: `problems`), `unreachable`, `not_found`, " +
				"`auth_failed`, `invalid_signature`, `invalid_catalog` or `disabled`."),
			"error": str("Why the last read failed."),
			"problems": schema.ListNestedAttribute{Computed: true,
				MarkdownDescription: "The problems of the last read: `file`, `runtime`, `path` (JSON path in the manifest), `message`.",
				NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
					"file":    str("The file (a Git source's `<path>/<id>/runtime.yaml`)."),
					"runtime": str("The runtime id or directory."),
					"path":    str("JSON path in the manifest."),
					"message": str("The problem."),
				}}},
			"revision":           str("Catalog digest or commit of the last good read."),
			"signature_verified": schema.BoolAttribute{Computed: true, MarkdownDescription: "The last read's catalog verified with `public_key`."},
			"runtimes_count":     schema.Int64Attribute{Computed: true, MarkdownDescription: "Runtimes the source gives."},
			"checked_at":         str("When RunR last read the source."),
			"synced_at":          str("When the last good read was."),
		},
	}
}

func (r *runtimeSourceResource) ConfigValidators(context.Context) []resource.ConfigValidator {
	return []resource.ConfigValidator{
		resourcevalidator.Conflicting(path.MatchRoot("git_token"), path.MatchRoot("git_token_wo"),
			path.MatchRoot("ssh_private_key"), path.MatchRoot("ssh_private_key_wo")),
		resourcevalidator.Conflicting(path.MatchRoot("registry_password"), path.MatchRoot("registry_password_wo")),
	}
}

func (r *runtimeSourceResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var m runtimeSourceModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	set := func(v types.String) bool { return !v.IsNull() }
	if known(m.Kind) && m.Kind.ValueString() == runr.RuntimeSourceOCI {
		for _, a := range []struct {
			name string
			v    types.String
		}{{"ref", m.Ref}, {"path", m.Path}, {"git_token", m.GitToken}, {"git_token_wo", m.GitTokenWO}, {"git_username", m.GitUsername},
			{"ssh_private_key", m.SSHPrivateKey}, {"ssh_private_key_wo", m.SSHPrivateKeyWO}, {"ssh_known_hosts", m.SSHKnownHosts}} {
			if set(a.v) {
				resp.Diagnostics.AddAttributeError(path.Root(a.name), "Not for an OCI catalog", a.name+" applies to a git source only.")
			}
		}
	}
	if set(m.PatchFeed) && m.PublicKey.IsNull() {
		resp.Diagnostics.AddAttributeError(path.Root("patch_feed"), "Missing public key", "A patch feed needs the public_key it is signed with.")
	}
	hasPassword := set(m.RegistryPassword) || set(m.RegistryPasswordWO)
	if set(m.RegistryUsername) != hasPassword {
		resp.Diagnostics.AddAttributeError(path.Root("registry_username"), "Incomplete registry credentials",
			"Give registry_username together with registry_password or registry_password_wo.")
	}
	if set(m.GitUsername) && !set(m.GitToken) && !set(m.GitTokenWO) {
		resp.Diagnostics.AddAttributeError(path.Root("git_username"), "git_username without a token", "git_username is the user of git_token (or git_token_wo).")
	}
	if set(m.SSHKnownHosts) && !set(m.SSHPrivateKey) && !set(m.SSHPrivateKeyWO) {
		resp.Diagnostics.AddAttributeError(path.Root("ssh_known_hosts"), "ssh_known_hosts without a deploy key", "ssh_known_hosts goes with ssh_private_key (or ssh_private_key_wo).")
	}
	if !m.CredentialsWOVersion.IsNull() && m.GitTokenWO.IsNull() && m.SSHPrivateKeyWO.IsNull() && m.RegistryPasswordWO.IsNull() {
		resp.Diagnostics.AddAttributeWarning(path.Root("credentials_wo_version"), "credentials_wo_version without a _wo credential",
			"credentials_wo_version only takes effect together with git_token_wo, ssh_private_key_wo or registry_password_wo.")
	}
}

func (r *runtimeSourceResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

// optionalText is a field that is removed with "" (sent as null).
func optionalText(v types.String) *string {
	s := ""
	if known(v) {
		s = v.ValueString()
	}
	return &s
}

// fieldsInput are the source's fields (not the credentials) as the plan has them.
func (m *runtimeSourceModel) fieldsInput() runr.RuntimeSourceInput {
	in := runr.RuntimeSourceInput{
		Name: strPtr(m.Name), Kind: strPtr(m.Kind), URL: strPtr(m.URL),
		PublicKey: optionalText(m.PublicKey), PatchFeed: optionalText(m.PatchFeed), RegistryHost: optionalText(m.RegistryHost),
		IntervalMinutes: intPtr(m.IntervalMinutes), IsEnabled: boolPtr(m.Enabled),
	}
	if m.Kind.ValueString() == runr.RuntimeSourceGit {
		in.Ref, in.Path = optionalText(m.Ref), optionalText(m.Path)
	}
	return in
}

// withCredentials sets every credential to the configured one ("" removes it): the plan's value,
// or the write-only one of the configuration.
func (m *runtimeSourceModel) withCredentials(in *runr.RuntimeSourceInput, cfg runtimeSourceModel) {
	in.GitToken = optionalText(types.StringValue(configured(m.GitToken, cfg.GitTokenWO)))
	in.GitUsername = optionalText(m.GitUsername)
	in.SSHPrivateKey = optionalText(types.StringValue(configured(m.SSHPrivateKey, cfg.SSHPrivateKeyWO)))
	in.SSHKnownHosts = optionalText(m.SSHKnownHosts)
	in.RegistryUsername = optionalText(m.RegistryUsername)
	in.RegistryPassword = optionalText(types.StringValue(configured(m.RegistryPassword, cfg.RegistryPasswordWO)))
}

// sameText keeps the prior value while the API's is the same text normalized (trimmed, CRLF as
// LF), so reading back never shows a difference the configuration did not make.
func sameText(prior types.String, api string) types.String {
	if known(prior) && strings.TrimSpace(strings.ReplaceAll(prior.ValueString(), "\r\n", "\n")) == api {
		return prior
	}
	return strOrNull(api)
}

// fromAPI copies the API's source into the model; the credentials stay as Terraform set them
// (git_username and registry_username, which RunR returns, are read back).
func (m *runtimeSourceModel) fromAPI(s *runr.RuntimeSource, diags *diag.Diagnostics) {
	m.ID = types.StringValue(s.ID)
	m.Name = sameText(m.Name, s.Name)
	m.Kind = types.StringValue(s.Kind)
	m.URL = sameText(m.URL, s.URL)
	m.Ref = sameText(m.Ref, s.Ref)
	m.Path = sameText(m.Path, s.Path)
	m.PublicKey = sameText(m.PublicKey, s.PublicKey)
	m.PatchFeed = sameText(m.PatchFeed, s.PatchFeed)
	m.RegistryHost = sameText(m.RegistryHost, s.RegistryHost)
	m.IntervalMinutes = types.Int64Value(int64(s.IntervalMinutes))
	m.Enabled = types.BoolValue(s.IsEnabled)
	m.GitUsername = sameText(m.GitUsername, s.Credentials.GitUsername)
	m.RegistryUsername = sameText(m.RegistryUsername, s.Credentials.RegistryUsername)
	m.Scope = types.StringValue(s.Scope)
	m.Credentials = runtimeSourceCredentialsValue(s.Credentials, diags)
	m.Status = types.StringValue(s.Status)
	m.Error = strOrNull(s.Error)
	m.Problems = runtimeSourceProblemsValue(s.Problems, diags)
	m.Revision = strOrNull(s.Revision)
	m.SignatureVerified = types.BoolValue(s.SignatureVerified)
	m.RuntimesCount = types.Int64Value(int64(s.RuntimesCount))
	m.CheckedAt = strOrNull(s.CheckedAt)
	m.SyncedAt = strOrNull(s.SyncedAt)
	m.GitTokenWO, m.SSHPrivateKeyWO, m.RegistryPasswordWO = types.StringNull(), types.StringNull(), types.StringNull()
}

func runtimeSourceCredentialsValue(c runr.RuntimeSourceCredentials, diags *diag.Diagnostics) types.Object {
	v, d := types.ObjectValue(runtimeSourceCredentialsAttrTypes, map[string]attr.Value{
		"git": strOrNull(c.Git), "git_username": strOrNull(c.GitUsername), "git_token_hint": strOrNull(c.GitTokenHint),
		"ssh_key_set": types.BoolValue(c.SSHKeySet), "known_hosts_set": types.BoolValue(c.KnownHostsSet),
		"registry_username": strOrNull(c.RegistryUsername), "registry_password_set": types.BoolValue(c.RegistryPasswordSet),
	})
	diags.Append(d...)
	return v
}

func runtimeSourceProblemsValue(problems []runr.RuntimeSourceProblem, diags *diag.Diagnostics) types.List {
	objType := types.ObjectType{AttrTypes: runtimeSourceProblemAttrTypes}
	items := make([]attr.Value, 0, len(problems))
	for _, p := range problems {
		items = append(items, types.ObjectValueMust(runtimeSourceProblemAttrTypes, map[string]attr.Value{
			"file": strOrNull(p.File), "runtime": strOrNull(p.Runtime), "path": types.StringValue(p.Path), "message": types.StringValue(p.Message),
		}))
	}
	l, d := types.ListValue(objType, items)
	diags.Append(d...)
	return l
}

// readWarnings reports a read that failed or refused manifests (the source is connected).
func readWarnings(s *runr.RuntimeSource, diags *diag.Diagnostics) {
	switch {
	case runr.RuntimeSourceFailed(s.Status):
		diags.AddWarning("Runtime source not read",
			fmt.Sprintf("RunR could not read the runtime source %s (%s): %s. Its runtimes stay as last read; fix the source and apply again.",
				s.Name, s.Status, s.Error))
	case s.Status == runr.RuntimeSourcePartial:
		lines := make([]string, 0, len(s.Problems))
		for i, p := range s.Problems {
			if i == 10 {
				lines = append(lines, fmt.Sprintf("… and %d more", len(s.Problems)-10))
				break
			}
			lines = append(lines, fmt.Sprintf("%s %s: %s", p.File, p.Path, p.Message))
		}
		diags.AddWarning("Runtime manifests refused",
			fmt.Sprintf("The runtime source %s was read; %d problem(s) refused the runtimes concerned:\n%s", s.Name, len(s.Problems), strings.Join(lines, "\n")))
	}
}

// writtenCredentials stores the API's account of the credentials Terraform wrote.
func writtenCredentials(ctx context.Context, private interface {
	SetKey(context.Context, string, []byte) diag.Diagnostics
}, c runr.RuntimeSourceCredentials, diags *diag.Diagnostics) {
	data, err := json.Marshal(c)
	if err != nil {
		diags.AddError("Store the written credentials", err.Error())
		return
	}
	diags.Append(private.SetKey(ctx, writtenCredentialsKey, data)...)
}

// credentialsDrifted reports whether the API's account of the credentials in the state differs
// from what it was after Terraform last wrote them.
func credentialsDrifted(ctx context.Context, written []byte, state types.Object) bool {
	if len(written) == 0 || !known(state) {
		return false
	}
	var w runr.RuntimeSourceCredentials
	if json.Unmarshal(written, &w) != nil {
		return false
	}
	attrs := state.Attributes()
	s := func(k string) string {
		if v, ok := attrs[k].(types.String); ok {
			return v.ValueString()
		}
		return ""
	}
	b := func(k string) bool {
		if v, ok := attrs[k].(types.Bool); ok {
			return v.ValueBool()
		}
		return false
	}
	now := runr.RuntimeSourceCredentials{Git: s("git"), GitUsername: s("git_username"), GitTokenHint: s("git_token_hint"),
		SSHKeySet: b("ssh_key_set"), KnownHostsSet: b("known_hosts_set"), RegistryUsername: s("registry_username"),
		RegistryPasswordSet: b("registry_password_set")}
	return now != w
}

func (r *runtimeSourceResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, cfg runtimeSourceModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.Config.Get(ctx, &cfg)...)
	if resp.Diagnostics.HasError() {
		return
	}
	in := plan.fieldsInput()
	plan.withCredentials(&in, cfg)
	s, err := r.client.API.CreateRuntimeSource(ctx, in)
	if err != nil {
		apiError(&resp.Diagnostics, "Connect runtime source failed", err)
		return
	}
	readWarnings(s, &resp.Diagnostics)
	plan.fromAPI(s, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
	writtenCredentials(ctx, resp.Private, s.Credentials, &resp.Diagnostics)
}

func (r *runtimeSourceResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state runtimeSourceModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	d, err := r.client.API.GetRuntimeSource(ctx, state.ID.ValueString())
	if sourceGone(err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read runtime source failed", err)
		return
	}
	state.fromAPI(&d.Source, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

// ModifyPlan plans the credentials again when RunR's account of them is no longer what it was
// after Terraform wrote them (changed or removed outside Terraform).
func (r *runtimeSourceResource) ModifyPlan(ctx context.Context, req resource.ModifyPlanRequest, resp *resource.ModifyPlanResponse) {
	if req.State.Raw.IsNull() || req.Plan.Raw.IsNull() {
		return
	}
	written, diags := req.Private.GetKey(ctx, writtenCredentialsKey)
	resp.Diagnostics.Append(diags...)
	var state runtimeSourceModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if credentialsDrifted(ctx, written, state.Credentials) {
		// Writing them reads the source again: what the read reports is known after apply
		resp.Diagnostics.Append(resp.Plan.SetAttribute(ctx, path.Root("credentials"), types.ObjectUnknown(runtimeSourceCredentialsAttrTypes))...)
		resp.Diagnostics.Append(resp.Plan.SetAttribute(ctx, path.Root("problems"), types.ListUnknown(types.ObjectType{AttrTypes: runtimeSourceProblemAttrTypes}))...)
		for _, a := range []string{"status", "error", "revision", "checked_at", "synced_at"} {
			resp.Diagnostics.Append(resp.Plan.SetAttribute(ctx, path.Root(a), types.StringUnknown())...)
		}
		resp.Diagnostics.Append(resp.Plan.SetAttribute(ctx, path.Root("signature_verified"), types.BoolUnknown())...)
		resp.Diagnostics.Append(resp.Plan.SetAttribute(ctx, path.Root("runtimes_count"), types.Int64Unknown())...)
	}
}

func (r *runtimeSourceResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state, cfg runtimeSourceModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	resp.Diagnostics.Append(req.Config.Get(ctx, &cfg)...)
	written, diags := req.Private.GetKey(ctx, writtenCredentialsKey)
	resp.Diagnostics.Append(diags...)
	if resp.Diagnostics.HasError() {
		return
	}
	id := state.ID.ValueString()

	writeCredentials := credentialsDrifted(ctx, written, state.Credentials)
	for _, pair := range [][2]types.String{
		{plan.GitToken, state.GitToken}, {plan.GitUsername, state.GitUsername}, {plan.SSHPrivateKey, state.SSHPrivateKey},
		{plan.SSHKnownHosts, state.SSHKnownHosts}, {plan.RegistryUsername, state.RegistryUsername},
		{plan.RegistryPassword, state.RegistryPassword}, {plan.CredentialsWOVersion, state.CredentialsWOVersion},
	} {
		writeCredentials = writeCredentials || !pair[0].Equal(pair[1])
	}
	in := plan.fieldsInput()
	before := state.fieldsInput()
	changed := writeCredentials || !sameInput(in, before)
	if writeCredentials {
		plan.withCredentials(&in, cfg)
	}

	var s *runr.RuntimeSource
	var err error
	switch {
	case changed:
		s, err = r.client.API.UpdateRuntimeSource(ctx, id, in)
	case !plan.SyncTrigger.Equal(state.SyncTrigger):
		s, err = r.syncNow(ctx, id, &resp.Diagnostics)
	default:
		var d *runr.RuntimeSourceDetail
		if d, err = r.client.API.GetRuntimeSource(ctx, id); err == nil {
			s = &d.Source
		}
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Update runtime source failed", err)
		return
	}
	readWarnings(s, &resp.Diagnostics)
	plan.fromAPI(s, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
	if writeCredentials {
		writtenCredentials(ctx, resp.Private, s.Credentials, &resp.Diagnostics)
	}
}

// syncNow reads the source (POST …/sync): a failed read is the source with its status (a warning
// follows from it), a read already running a warning and the source as it is.
func (r *runtimeSourceResource) syncNow(ctx context.Context, id string, diags *diag.Diagnostics) (*runr.RuntimeSource, error) {
	s, err := r.client.API.SyncRuntimeSource(ctx, id)
	var fe *runr.FailedError
	if errors.As(err, &fe) && s != nil {
		return s, nil
	}
	var ae *runr.APIError
	if errors.As(err, &ae) && ae.StatusCode == http.StatusConflict {
		diags.AddWarning("Runtime source read running", "A read of the source runs already; its outcome shows with the next refresh. "+ae.Message)
		d, gerr := r.client.API.GetRuntimeSource(ctx, id)
		if gerr != nil {
			return nil, gerr
		}
		return &d.Source, nil
	}
	return s, err
}

// sourceGone reports whether err means the source no longer exists.
func sourceGone(err error) bool {
	var nf *runr.NotFoundError
	return runr.IsNotFound(err) || errors.As(err, &nf)
}

// sameInput compares the fields of two inputs (not the credentials).
func sameInput(a, b runr.RuntimeSourceInput) bool {
	eq := func(x, y *string) bool {
		return (x == nil && y == nil) || (x != nil && y != nil && strings.TrimSpace(*x) == strings.TrimSpace(*y))
	}
	eqInt := func(x, y *int) bool { return (x == nil || y == nil) || *x == *y }
	eqBool := func(x, y *bool) bool { return (x == nil || y == nil) || *x == *y }
	return eq(a.Name, b.Name) && eq(a.Kind, b.Kind) && eq(a.URL, b.URL) && eq(a.Ref, b.Ref) && eq(a.Path, b.Path) &&
		eq(a.PublicKey, b.PublicKey) && eq(a.PatchFeed, b.PatchFeed) && eq(a.RegistryHost, b.RegistryHost) &&
		eqInt(a.IntervalMinutes, b.IntervalMinutes) && eqBool(a.IsEnabled, b.IsEnabled)
}

func (r *runtimeSourceResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state runtimeSourceModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if err := r.client.API.DeleteRuntimeSource(ctx, state.ID.ValueString()); err != nil && !sourceGone(err) {
		apiError(&resp.Diagnostics, "Remove runtime source failed", err)
	}
}

// ImportState accepts the source's id or name. RunR's catalog is refused: it is the
// installation's (data.runr_runtime_source reads it).
func (r *runtimeSourceResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	s, err := r.client.API.FindRuntimeSource(ctx, req.ID)
	if err != nil {
		apiError(&resp.Diagnostics, "Import runtime source failed", err)
		return
	}
	if s.IsDefault {
		resp.Diagnostics.AddError("RunR's catalog cannot be imported",
			s.Name+" is the installation's own catalog: it cannot be created or removed, only switched off and rescheduled "+
				"(runr runtimes sources update). Read it with data.runr_runtime_source.")
		return
	}
	m := runtimeSourceModel{Ref: types.StringNull(), Path: types.StringNull(), GitToken: types.StringNull(),
		SSHPrivateKey: types.StringNull(), SSHKnownHosts: types.StringNull(), RegistryPassword: types.StringNull(),
		CredentialsWOVersion: types.StringNull(), SyncTrigger: types.StringNull()}
	m.fromAPI(s, &resp.Diagnostics)
	if c := s.Credentials; c.Git != "" || c.RegistryPasswordSet {
		resp.Diagnostics.AddWarning("Credentials not imported",
			"RunR never returns a runtime source's token, deploy key or password: the state holds none. Keep them in the "+
				"configuration; the next apply writes them (with the _wo attributes: set credentials_wo_version).")
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}
