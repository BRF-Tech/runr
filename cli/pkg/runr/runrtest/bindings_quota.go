package runrtest

import "strings"

// One organization's binding limits (TenantBindingQuotaController) of the fake: multi-tenant
// edition only (RBACMultiTenant), for the fake's one tenant; the installation's defaults are
// those of config/bindings.php.

var fakeBindingQuotaKeys = []string{"max_bindings", "kv_mb", "object_gb", "postgres_gb", "dedicated_postgres"}

var fakeBindingQuotaDefaults = map[string]int64{"max_bindings": 20, "kv_mb": 1024, "object_gb": 50, "postgres_gb": 20,
	"dedicated_postgres": 1}

func (s *Server) bindingQuotaOverrides() map[string]*int64 {
	return *featureState(s, "binding-quota-overrides", func() *map[string]*int64 {
		m := map[string]*int64{}
		return &m
	})
}

// TenantBindingQuotaOverrides returns the fake tenant's binding limit overrides (nil = default).
func (s *Server) TenantBindingQuotaOverrides() map[string]*int64 {
	s.mu.Lock()
	defer s.mu.Unlock()
	out := map[string]*int64{}
	for k, v := range s.bindingQuotaOverrides() {
		out[k] = v
	}
	return out
}

func (s *Server) tenantBindingQuotaJSON() map[string]any {
	overrides, defaults, limits := map[string]any{}, map[string]any{}, map[string]any{}
	used := map[string]any{}
	ov := s.bindingQuotaOverrides()
	for _, k := range fakeBindingQuotaKeys {
		defaults[k] = fakeBindingQuotaDefaults[k]
		limits[k] = fakeBindingQuotaDefaults[k]
		overrides[k] = nil
		if v := ov[k]; v != nil {
			overrides[k], limits[k] = *v, *v
		}
		used[k] = 0
	}
	used["max_bindings"] = len(s.bindSt().bindings)
	return map[string]any{"tenant_id": s.rbac().tenantID, "overrides": overrides, "defaults": defaults,
		"limits": limits, "used": used}
}

func init() {
	handle("GET /tenants/{tenant}/binding-quotas", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if s.rbac().edition != "multi" || q.Segs[1] != s.rbac().tenantID {
			return 0, nil, errNotFound()
		}
		return 200, map[string]any{"data": s.tenantBindingQuotaJSON()}, nil
	})
	handle("PUT /tenants/{tenant}/binding-quotas", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if s.rbac().edition != "multi" || q.Segs[1] != s.rbac().tenantID {
			return 0, nil, errNotFound()
		}
		next := map[string]*int64{}
		for k, v := range q.Body {
			known := false
			for _, key := range fakeBindingQuotaKeys {
				known = known || key == k
			}
			if !known {
				continue // validate() keeps only the known keys
			}
			if v == nil {
				next[k] = nil
				continue
			}
			f, ok := v.(float64)
			if !ok || f != float64(int64(f)) || f < 0 || f > 10000000 {
				return 0, nil, errValidation(k, "The "+strings.ReplaceAll(k, "_", " ")+" field must be an integer between 0 and 10000000.")
			}
			n := int64(f)
			next[k] = &n
		}
		ov := s.bindingQuotaOverrides()
		for k, v := range next {
			ov[k] = v
		}
		return 200, map[string]any{"data": s.tenantBindingQuotaJSON()}, nil
	})
}
