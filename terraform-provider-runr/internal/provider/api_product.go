package provider

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Shared by the API product resources (docs/api-product.md): runr_gateway, runr_gateway_route,
// runr_api_plan, runr_api_consumer and runr_api_key.

// findGateway resolves a gateway by its id, its region's name or id, its host or its custom
// domain (runr gateway accepts the same).
func findGateway(ctx context.Context, c *runr.Client, ref string) (*runr.Gateway, error) {
	gateways, err := c.ListGateways(ctx)
	if err != nil {
		return nil, err
	}
	ref = strings.TrimSpace(ref)
	for _, g := range gateways {
		region := ""
		if g.Region != nil {
			region = g.Region.Name
		}
		if g.ID == ref || g.RegionID == ref || (region != "" && region == ref) || g.Host == ref ||
			(g.CustomDomain != "" && g.CustomDomain == ref) {
			return &g, nil
		}
	}
	return nil, &runr.NotFoundError{Kind: "gateway", Ref: ref}
}

// int64FromSibling plans a left-out Optional+Computed number as the planned value of another
// attribute of the same object when that one is new or changes: the API's default for a burst
// is the rate it belongs to. While the other attribute stays, the number keeps its value (also
// one set outside Terraform).
type int64FromSibling struct{ sibling string }

func (m int64FromSibling) Description(context.Context) string {
	return "Defaults to " + m.sibling + " when that is new or changes; kept otherwise."
}

func (m int64FromSibling) MarkdownDescription(ctx context.Context) string { return m.Description(ctx) }

func (m int64FromSibling) PlanModifyInt64(ctx context.Context, req planmodifier.Int64Request, resp *planmodifier.Int64Response) {
	if !req.ConfigValue.IsNull() || !req.PlanValue.IsUnknown() {
		return
	}
	sibling := req.Path.ParentPath().AtName(m.sibling)
	var planned types.Int64
	resp.Diagnostics.Append(req.Plan.GetAttribute(ctx, sibling, &planned)...)
	if planned.IsUnknown() {
		return
	}
	if known(req.StateValue) {
		var prior types.Int64
		resp.Diagnostics.Append(req.State.GetAttribute(ctx, sibling, &prior)...)
		if planned.Equal(prior) {
			resp.PlanValue = req.StateValue
			return
		}
	}
	resp.PlanValue = planned
}

// int64OrNull is a nullable count of the API.
func int64OrNull(p *int64) types.Int64 {
	if p == nil {
		return types.Int64Null()
	}
	return types.Int64Value(*p)
}

// int64Ptr is a nullable count for the API (nil: null).
func int64Ptr(v types.Int64) *int64 {
	if !known(v) {
		return nil
	}
	n := v.ValueInt64()
	return &n
}

// nullable is a string for a request body: nil (JSON null) for null or "".
func nullable(v types.String) any {
	if !known(v) || v.ValueString() == "" {
		return nil
	}
	return v.ValueString()
}

// keepEmpty keeps "" (the configuration's way to clear a text) where the API answers null.
func keepEmpty(prior types.String, s string) types.String {
	if s == "" && known(prior) && prior.ValueString() == "" {
		return prior
	}
	return strOrNull(s)
}

// sameInstant keeps the configuration's spelling of a time the API answers in its own format
// (2027-01-01T00:00:00Z / 2027-01-01T00:00:00.000000Z); another time in RFC 3339, UTC.
func sameInstant(prior types.String, s string) types.String {
	t, err := time.Parse(time.RFC3339Nano, s)
	if s == "" || err != nil {
		return strOrNull(s)
	}
	if known(prior) {
		if p, err := time.Parse(time.RFC3339Nano, prior.ValueString()); err == nil && p.Equal(t) {
			return prior
		}
	}
	return types.StringValue(t.UTC().Format(time.RFC3339Nano))
}

// stringSet is a set of strings (nil: null).
func stringSet(ctx context.Context, s []string, diags *diag.Diagnostics) types.Set {
	if s == nil {
		return types.SetNull(types.StringType)
	}
	v, d := types.SetValueFrom(ctx, types.StringType, s)
	diags.Append(d...)
	return v
}

// setStrings are the strings of a set (nil: null or unknown).
func setStrings(ctx context.Context, v types.Set, diags *diag.Diagnostics) []string {
	if !known(v) {
		return nil
	}
	out := []string{}
	diags.Append(v.ElementsAs(ctx, &out, false)...)
	return out
}

// metadataMap is a consumer's metadata as strings: values that are not strings (set through the
// API) as JSON.
func metadataMap(ctx context.Context, m map[string]any, prior types.Map, diags *diag.Diagnostics) types.Map {
	if len(m) == 0 {
		if known(prior) && len(prior.Elements()) == 0 {
			return prior
		}
		return types.MapNull(types.StringType)
	}
	out := make(map[string]string, len(m))
	for k, v := range m {
		switch x := v.(type) {
		case string:
			out[k] = x
		default:
			b, err := json.Marshal(x)
			if err != nil {
				b = []byte(fmt.Sprint(x))
			}
			out[k] = string(b)
		}
	}
	v, d := types.MapValueFrom(ctx, types.StringType, out)
	diags.Append(d...)
	return v
}
