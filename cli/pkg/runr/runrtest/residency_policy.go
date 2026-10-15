package runrtest

import (
	"sort"
	"strings"
	"time"
)

// An organization's security policy (ResidencyController::show / update) of the fake:
// multi-tenant edition only, for the fake's one tenant. Residency fields need
// tenants.residency.manage, gate fields regions.supply_chain.manage (scoped tokens).

type tenantPolicy struct {
	enforced        bool
	regionIDs       []string
	locations       []string
	defaultRegion   *string
	gateMode        *string
	gateMaxCritical *int
	gateMaxHigh     *int
	gateRequireScan *bool
	updatedAt       string
}

func (s *Server) tenantPolicyState() *tenantPolicy {
	return featureState(s, "tenant-policy", func() *tenantPolicy {
		return &tenantPolicy{enforced: true, regionIDs: []string{}, locations: []string{}}
	})
}

// TenantSecurityPolicyState returns the fake tenant's allowed region ids, allowed locations and
// gate mode ("" = none).
func (s *Server) TenantSecurityPolicyState() (regionIDs, locations []string, gateMode string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.tenantPolicyState()
	if p.gateMode != nil {
		gateMode = *p.gateMode
	}
	return append([]string(nil), p.regionIDs...), append([]string(nil), p.locations...), gateMode
}

func (s *Server) tenantPolicyJSON() map[string]any {
	p := s.tenantPolicyState()
	regions := []any{}
	for _, r := range s.regions {
		regions = append(regions, map[string]any{"id": r.ID, "name": r.Name, "data_location": "eu", "region_mode": "managed"})
	}
	var def any
	if p.defaultRegion != nil {
		def = *p.defaultRegion
	}
	var mode, maxCrit, maxHigh, reqScan any
	if p.gateMode != nil {
		mode = *p.gateMode
	}
	if p.gateMaxCritical != nil {
		maxCrit = *p.gateMaxCritical
	}
	if p.gateMaxHigh != nil {
		maxHigh = *p.gateMaxHigh
	}
	if p.gateRequireScan != nil {
		reqScan = *p.gateRequireScan
	}
	return map[string]any{
		"tenant_id": s.rbac().tenantID, "residency_enforced": p.enforced, "allowed_region_ids": p.regionIDs,
		"allowed_locations": p.locations, "default_region_id": def,
		"restricted": p.enforced && (len(p.regionIDs) > 0 || len(p.locations) > 0),
		"gate_mode":  mode, "gate_max_critical": maxCrit, "gate_max_high": maxHigh, "gate_require_scan": reqScan,
		"updated_by": "ada", "updated_at": p.updatedAt, "regions": regions, "locations": []string{"eu"},
		"abilities": map[string]any{"residency": s.holds("tenants.residency.manage"), "gate": s.holds("regions.supply_chain.manage")},
	}
}

func init() {
	handle("GET /tenants/{tenant}/security-policy", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if s.rbac().edition != "multi" || q.Segs[1] != s.rbac().tenantID {
			return 0, nil, errNotFound()
		}
		return 200, map[string]any{"data": s.tenantPolicyJSON()}, nil
	})
	handle("PUT /tenants/{tenant}/security-policy", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if s.rbac().edition != "multi" || q.Segs[1] != s.rbac().tenantID {
			return 0, nil, errNotFound()
		}
		residency, gate := false, false
		for k := range q.Body {
			switch k {
			case "residency_enforced", "allowed_region_ids", "allowed_locations", "default_region_id":
				residency = true
			case "gate_mode", "gate_max_critical", "gate_max_high", "gate_require_scan":
				gate = true
			}
		}
		if !residency && !gate {
			return 0, nil, &apiErr{status: 422, message: "Give at least one residency or gate setting."}
		}
		if residency && !s.holds("tenants.residency.manage") {
			return 0, nil, &apiErr{status: 403, message: "This action is unauthorized.", permissions: []string{"tenants.residency.manage"}}
		}
		if gate && !s.holds("regions.supply_chain.manage") {
			return 0, nil, &apiErr{status: 403, message: "This action is unauthorized.", permissions: []string{"regions.supply_chain.manage"}}
		}
		next := *s.tenantPolicyState()
		strList := func(key string) ([]string, *apiErr) {
			raw, _ := q.Body[key].([]any)
			seen := map[string]bool{}
			out := []string{}
			for _, v := range raw {
				str, ok := v.(string)
				str = strings.TrimSpace(str)
				if !ok || str == "" {
					return nil, errValidation(key, "The "+strings.ReplaceAll(key, "_", " ")+" must be strings.")
				}
				if !seen[str] {
					seen[str] = true
					out = append(out, str)
				}
			}
			sort.Strings(out)
			return out, nil
		}
		if v, ok := q.Body["residency_enforced"]; ok {
			b, isBool := v.(bool)
			if !isBool {
				return 0, nil, errValidation("residency_enforced", "The residency enforced field must be true or false.")
			}
			next.enforced = b
		}
		if _, ok := q.Body["allowed_region_ids"]; ok {
			ids, err := strList("allowed_region_ids")
			if err != nil {
				return 0, nil, err
			}
			for _, id := range ids {
				if s.regionByID(id) == nil {
					return 0, nil, errValidation("allowed_region_ids", "The allowed region "+id+" does not exist.")
				}
			}
			next.regionIDs = ids
		}
		if _, ok := q.Body["allowed_locations"]; ok {
			locs, err := strList("allowed_locations")
			if err != nil {
				return 0, nil, err
			}
			next.locations = locs
		}
		if v, ok := q.Body["default_region_id"]; ok {
			if v == nil {
				next.defaultRegion = nil
			} else {
				id, _ := v.(string)
				if s.regionByID(id) == nil {
					return 0, nil, errValidation("default_region_id", "The default region does not exist.")
				}
				next.defaultRegion = &id
			}
		}
		if v, ok := q.Body["gate_mode"]; ok {
			if v == nil {
				next.gateMode = nil
			} else {
				m, _ := v.(string)
				if m != "off" && m != "warn" && m != "enforce" {
					return 0, nil, errValidation("gate_mode", "The selected gate mode is invalid.")
				}
				next.gateMode = &m
			}
		}
		for _, f := range []struct {
			key string
			dst **int
		}{{"gate_max_critical", &next.gateMaxCritical}, {"gate_max_high", &next.gateMaxHigh}} {
			v, ok := q.Body[f.key]
			if !ok {
				continue
			}
			if v == nil {
				*f.dst = nil
				continue
			}
			n, isNum := v.(float64)
			if !isNum || n < 0 || n != float64(int(n)) {
				return 0, nil, errValidation(f.key, "The "+strings.ReplaceAll(f.key, "_", " ")+" field must be an integer of 0 or more.")
			}
			i := int(n)
			*f.dst = &i
		}
		if v, ok := q.Body["gate_require_scan"]; ok {
			if v == nil {
				next.gateRequireScan = nil
			} else {
				b, isBool := v.(bool)
				if !isBool {
					return 0, nil, errValidation("gate_require_scan", "The gate require scan field must be true or false.")
				}
				next.gateRequireScan = &b
			}
		}
		if next.defaultRegion != nil && len(next.regionIDs) > 0 {
			found := false
			for _, id := range next.regionIDs {
				found = found || id == *next.defaultRegion
			}
			if !found {
				return 0, nil, errValidation("default_region_id", "The default region must be one of the allowed regions.")
			}
		}
		next.updatedAt = time.Now().UTC().Format(time.RFC3339)
		*s.tenantPolicyState() = next
		return 200, map[string]any{"data": s.tenantPolicyJSON()}, nil
	})
}
