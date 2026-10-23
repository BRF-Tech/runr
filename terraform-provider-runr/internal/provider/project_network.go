package provider

import (
	"context"
	"fmt"
	"net"
	"regexp"
	"sort"

	"github.com/hashicorp/terraform-plugin-framework-validators/setvalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/objectplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/setplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/hashicorp/terraform-plugin-framework/types/basetypes"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// The project's network (runr_project.network, docs/internal-network.md): who reaches it from
// outside the cluster (access mode, the IP ranges of an allow list) and which projects may call
// it on the region's internal network (callers, by internal name <project>.<organization>).

type networkModel struct {
	AccessMode   types.String `tfsdk:"access_mode"`
	AllowedCIDRs types.Set    `tfsdk:"allowed_cidrs"`
	Callers      types.Set    `tfsdk:"callers"`
	InternalName types.String `tfsdk:"internal_name"`
	InternalURL  types.String `tfsdk:"internal_url"`
}

var networkAttrTypes = map[string]attr.Type{
	"access_mode": types.StringType, "allowed_cidrs": types.SetType{ElemType: types.StringType},
	"callers": types.SetType{ElemType: types.StringType}, "internal_name": types.StringType, "internal_url": types.StringType,
}

// internalNamePattern is an internal name <project slug>.<organization slug>.
var internalNamePattern = regexp.MustCompile(`^[a-z0-9]([-a-z0-9]*[a-z0-9])?\.[a-z0-9]([-a-z0-9]*[a-z0-9])?$`)

func networkSchema() schema.SingleNestedAttribute {
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	return schema.SingleNestedAttribute{
		Optional: true, Computed: true,
		MarkdownDescription: "Who reaches the project ([internal network](https://runr.sh/docs/internal-network/)). Values left out " +
			"stay as they are. Changing the access mode or the allow list needs `network.manage`.",
		PlanModifiers: []planmodifier.Object{objectplanmodifier.UseStateForUnknown()},
		Attributes: map[string]schema.Attribute{
			"access_mode": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
				MarkdownDescription: "`public` (anyone reaches the public URL; the default), `allowlist` (the public URL answers " +
					"`allowed_cidrs` only) or `internal` (no public URL: the projects in `callers` call it on the region's internal network).",
				Validators: []validator.String{stringvalidator.OneOf(runr.AccessPublic, runr.AccessAllowlist, runr.AccessInternal)}},
			"allowed_cidrs": schema.SetAttribute{ElementType: types.StringType, Optional: true, Computed: true,
				PlanModifiers:       []planmodifier.Set{setplanmodifier.UseStateForUnknown()},
				MarkdownDescription: "IP addresses or CIDR ranges the public URL answers in `allowlist` mode (empty in the other modes).",
				Validators:          []validator.Set{setvalidator.ValueStringsAre(cidrValidator{})}},
			"callers": schema.SetAttribute{ElementType: types.StringType, Optional: true, Computed: true,
				PlanModifiers: []planmodifier.Set{setplanmodifier.UseStateForUnknown()},
				MarkdownDescription: "Projects allowed to call this one on the internal network, by internal name " +
					"`<project>.<organization>` (another project's `network.internal_name`; another organization's project " +
					"where the platform allows it). Set, it is the whole allow list; `[]` removes every caller.",
				Validators: []validator.Set{setvalidator.ValueStringsAre(stringvalidator.RegexMatches(internalNamePattern,
					"must be an internal name <project>.<organization> in lower case, e.g. runr_project.billing.network.internal_name"))}},
			"internal_name": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "The project's internal name `<project>.<organization>`, for other projects' `callers`."},
			"internal_url": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "The URL other projects of the region call it at on the internal network."},
		},
	}
}

// cidrValidator accepts an IP address or a CIDR range.
type cidrValidator struct{}

func (cidrValidator) Description(context.Context) string { return "an IP address or a CIDR range" }
func (v cidrValidator) MarkdownDescription(ctx context.Context) string {
	return v.Description(ctx)
}

func (cidrValidator) ValidateString(_ context.Context, req validator.StringRequest, resp *validator.StringResponse) {
	if !known(req.ConfigValue) {
		return
	}
	s := req.ConfigValue.ValueString()
	if net.ParseIP(s) != nil {
		return
	}
	if _, _, err := net.ParseCIDR(s); err != nil {
		resp.Diagnostics.AddAttributeError(req.Path, "invalid IP range", fmt.Sprintf("%q is neither an IP address nor a CIDR range such as 203.0.113.0/24.", s))
	}
}

// validateNetwork checks what the API would refuse or silently change: IP ranges belong to the
// allowlist mode, which needs at least one.
func validateNetwork(ctx context.Context, cfg types.Object, diags *diag.Diagnostics) {
	if !known(cfg) {
		return
	}
	var m networkModel
	diags.Append(cfg.As(ctx, &m, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
	at := path.Root("network")
	if known(m.AllowedCIDRs) && len(m.AllowedCIDRs.Elements()) > 0 && (!known(m.AccessMode) || m.AccessMode.ValueString() != runr.AccessAllowlist) {
		if !m.AccessMode.IsUnknown() {
			diags.AddAttributeError(at.AtName("allowed_cidrs"), "allowed_cidrs needs access_mode \"allowlist\"",
				"The IP ranges apply to the allowlist mode only; set access_mode = \"allowlist\" or remove allowed_cidrs.")
		}
	}
	if known(m.AccessMode) && m.AccessMode.ValueString() == runr.AccessAllowlist && known(m.AllowedCIDRs) && len(m.AllowedCIDRs.Elements()) == 0 {
		diags.AddAttributeError(at.AtName("allowed_cidrs"), "allowlist without IP ranges",
			"Name at least one IP address or range in allowed_cidrs for the allowlist mode.")
	}
}

// applyNetwork changes the server's network to what the configured values say (cur: the
// server's network) and returns the network after it.
func applyNetwork(ctx context.Context, api *runr.Client, projectID string, cfg types.Object, cur *runr.Network, diags *diag.Diagnostics) *runr.Network {
	if !known(cfg) || cur == nil {
		return cur
	}
	var m networkModel
	diags.Append(cfg.As(ctx, &m, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
	if diags.HasError() {
		return cur
	}
	mode := cur.AccessMode
	if known(m.AccessMode) {
		mode = m.AccessMode.ValueString()
	}
	cidrs := cur.AllowedCIDRs
	if known(m.AllowedCIDRs) {
		cidrs = nil
		diags.Append(m.AllowedCIDRs.ElementsAs(ctx, &cidrs, false)...)
	}
	if mode != cur.AccessMode || (mode == runr.AccessAllowlist && !sameStrings(cidrs, cur.AllowedCIDRs)) {
		n, err := api.SetNetwork(ctx, projectID, mode, cidrs)
		if err != nil {
			apiError(diags, "Set the project's network failed", err)
			return cur
		}
		n.Callers = cur.Callers
		cur = n
	}
	if known(m.Callers) {
		var want []string
		diags.Append(m.Callers.ElementsAs(ctx, &want, false)...)
		have := map[string]bool{}
		for _, c := range cur.Callers {
			have[c.Caller] = true
		}
		wanted := map[string]bool{}
		for _, c := range want {
			wanted[c] = true
			if !have[c] {
				if _, err := api.AddNetworkCaller(ctx, projectID, c); err != nil {
					apiError(diags, "Allow caller "+c+" failed", err)
					return cur
				}
			}
		}
		for _, c := range cur.Callers {
			if !wanted[c.Caller] {
				if err := api.RemoveNetworkCaller(ctx, projectID, c.Caller); err != nil && !runr.IsNotFound(err) {
					apiError(diags, "Remove caller "+c.Caller+" failed", err)
					return cur
				}
			}
		}
		n, err := api.GetNetwork(ctx, projectID)
		if err != nil {
			apiError(diags, "Read the project's network failed", err)
			return cur
		}
		cur = n
	}
	return cur
}

// networkValue is the server's network as the attribute's value (null without one).
func networkValue(ctx context.Context, n *runr.Network, diags *diag.Diagnostics) types.Object {
	if n == nil {
		return types.ObjectNull(networkAttrTypes)
	}
	cidrs := append([]string{}, n.AllowedCIDRs...)
	callers := make([]string, 0, len(n.Callers))
	for _, c := range n.Callers {
		callers = append(callers, c.Caller)
	}
	sort.Strings(callers)
	cidrSet, d := types.SetValueFrom(ctx, types.StringType, cidrs)
	diags.Append(d...)
	callerSet, d := types.SetValueFrom(ctx, types.StringType, callers)
	diags.Append(d...)
	mode := n.AccessMode
	if mode == "" {
		mode = runr.AccessPublic
	}
	out, d := types.ObjectValueFrom(ctx, networkAttrTypes, networkModel{
		AccessMode: types.StringValue(mode), AllowedCIDRs: cidrSet, Callers: callerSet,
		InternalName: strOrNull(n.InternalName), InternalURL: strOrNull(n.InternalURL),
	})
	diags.Append(d...)
	return out
}
