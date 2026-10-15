package runrtest

// GPU quota of the fake: GET /models/gpu-quota (HubController::gpuQuota) and the platform
// administrator's PUT /tenants/{tenant}/regions/{region}/gpu-quota (multi-tenant edition,
// RBACMultiTenant).

type mdlaQuota struct {
	quota *int
	used  int
}

func (s *Server) mdlaQuotas() map[string]*mdlaQuota {
	return *featureState(s, "gpu-quota", func() *map[string]*mdlaQuota {
		m := map[string]*mdlaQuota{}
		return &m
	})
}

func (s *Server) mdlaQuotaOf(regionID string) *mdlaQuota {
	m := s.mdlaQuotas()
	q, ok := m[regionID]
	if !ok {
		q = &mdlaQuota{}
		m[regionID] = q
	}
	return q
}

// SeedGPUQuota sets the organization's GPU quota on a region (nil = no limit) and how many
// GPUs its projects and model services hold.
func (s *Server) SeedGPUQuota(regionName string, quota *int, used int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	q := s.mdlaQuotaOf(reg.ID)
	q.quota, q.used = quota, used
}

// GPUQuotaOf returns the GPU quota the fake holds for a region (nil = no limit).
func (s *Server) GPUQuotaOf(regionName string) *int {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		return nil
	}
	return s.mdlaQuotaOf(reg.ID).quota
}

func init() {
	handle("GET /models/gpu-quota", func(s *Server, q *featureReq) (int, any, *apiErr) {
		rid := q.query("knative_region_id")
		if rid == "" {
			return 0, nil, errValidation("knative_region_id", "The knative region id field is required.")
		}
		if s.regionByID(rid) == nil {
			return 0, nil, errNotFound()
		}
		quota := s.mdlaQuotaOf(rid)
		var remaining any
		if quota.quota != nil {
			r := *quota.quota - quota.used
			if r < 0 {
				r = 0
			}
			remaining = r
		}
		var limit any
		if quota.quota != nil {
			limit = *quota.quota
		}
		return 200, map[string]any{"quota": limit, "used": quota.used, "remaining": remaining}, nil
	})
	handle("PUT /tenants/{tenant}/regions/{region}/gpu-quota", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.rbac()
		if st.edition != "multi" || q.Segs[1] != st.tenantID {
			return 0, nil, errNotFound()
		}
		if s.regionByID(q.Segs[3]) == nil {
			return 0, nil, &apiErr{status: 404, message: "The tenant has no access to this region."}
		}
		raw, present := q.Body["gpu_quota"]
		if !present {
			return 0, nil, errValidation("gpu_quota", "The gpu quota field must be present.")
		}
		quota := s.mdlaQuotaOf(q.Segs[3])
		switch v := raw.(type) {
		case nil:
			quota.quota = nil
		case float64:
			if v < 0 || v != float64(int(v)) {
				return 0, nil, errValidation("gpu_quota", "The gpu quota field must be an integer of at least 0.")
			}
			n := int(v)
			quota.quota = &n
		default:
			return 0, nil, errValidation("gpu_quota", "The gpu quota field must be an integer.")
		}
		var out any
		if quota.quota != nil {
			out = *quota.quota
		}
		return 200, map[string]any{"tenant_id": st.tenantID, "region_id": q.Segs[3], "gpu_quota": out}, nil
	})
}
