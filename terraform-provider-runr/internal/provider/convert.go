package provider

import (
	"context"

	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/types"
)

func known(v interface {
	IsNull() bool
	IsUnknown() bool
}) bool {
	return !v.IsNull() && !v.IsUnknown()
}

func strOrNull(s string) types.String {
	if s == "" {
		return types.StringNull()
	}
	return types.StringValue(s)
}

func intOrNull(p *int) types.Int64 {
	if p == nil {
		return types.Int64Null()
	}
	return types.Int64Value(int64(*p))
}

func boolOrNull(p *bool) types.Bool {
	if p == nil {
		return types.BoolNull()
	}
	return types.BoolValue(*p)
}

func strPtr(v types.String) *string {
	if !known(v) {
		return nil
	}
	s := v.ValueString()
	return &s
}

func intPtr(v types.Int64) *int {
	if !known(v) {
		return nil
	}
	n := int(v.ValueInt64())
	return &n
}

func boolPtr(v types.Bool) *bool {
	if !known(v) {
		return nil
	}
	b := v.ValueBool()
	return &b
}

func stringList(ctx context.Context, v types.List, diags *diag.Diagnostics) *[]string {
	if !known(v) {
		return nil
	}
	var out []string
	diags.Append(v.ElementsAs(ctx, &out, false)...)
	if out == nil {
		out = []string{}
	}
	return &out
}

func intList(ctx context.Context, v types.List, diags *diag.Diagnostics) *[]int {
	if !known(v) {
		return nil
	}
	var raw []int64
	diags.Append(v.ElementsAs(ctx, &raw, false)...)
	out := make([]int, len(raw))
	for i, n := range raw {
		out[i] = int(n)
	}
	return &out
}

func listOfStrings(ctx context.Context, s []string, diags *diag.Diagnostics) types.List {
	if len(s) == 0 {
		return types.ListNull(types.StringType)
	}
	l, d := types.ListValueFrom(ctx, types.StringType, s)
	diags.Append(d...)
	return l
}

func listOfInts(ctx context.Context, s []int, diags *diag.Diagnostics) types.List {
	if len(s) == 0 {
		return types.ListNull(types.Int64Type)
	}
	vals := make([]int64, len(s))
	for i, n := range s {
		vals[i] = int64(n)
	}
	l, d := types.ListValueFrom(ctx, types.Int64Type, vals)
	diags.Append(d...)
	return l
}

func equalStringPtrs(a *[]string, b []string) bool {
	if a == nil {
		return true
	}
	if len(*a) != len(b) {
		return false
	}
	for i := range b {
		if (*a)[i] != b[i] {
			return false
		}
	}
	return true
}
