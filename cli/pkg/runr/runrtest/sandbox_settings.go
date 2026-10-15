package runrtest

import (
	"fmt"
	"sort"
	"strings"

	"github.com/runrsh/core/cli/pkg/runr"
)

// The network of a running sandbox (SandboxesController::network) and the sandbox settings of
// the regions (SandboxAdminController::regions / updateRegion) of the fake. Every region of the
// fake offers kata-fc and gvisor; the platform limits are those of config/sandbox.php.

const (
	sbxPlatformMaxTTL    = 86400
	sbxPlatformTTL       = 900
	sbxPlatformMaxCPU    = 4000
	sbxPlatformMaxMemory = 8192
	sbxPlatformMaxDisk   = 10240
)

// sbxRegionSetting is a region's own settings; nil pointers are the platform's.
type sbxRegionSetting struct {
	enabled        bool
	allowedClasses []string
	defaultClass   *string
	allowRunc      bool
	defaultTTL     *int
	maxTTL         *int
	maxCPU         *int
	maxMemory      *int
	maxDisk        *int
}

type sbxSettingsState struct {
	regions map[string]*sbxRegionSetting
	// readOnly are the regions the caller may not change (a platform region seen by a tenant
	// user): can_manage is false and PUT answers 403, like EnsureTenantRegionMutable.
	readOnly map[string]bool
}

func (s *Server) sbxSettings() *sbxSettingsState {
	return featureState(s, "sandbox-settings", func() *sbxSettingsState {
		return &sbxSettingsState{regions: map[string]*sbxRegionSetting{}, readOnly: map[string]bool{}}
	})
}

// SetSandboxRegionReadOnly makes a region (by name) one the caller may not change, as a
// platform region is for the users of an organization.
func (s *Server) SetSandboxRegionReadOnly(regionName string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if r := s.regionByName(regionName); r != nil {
		s.sbxSettings().readOnly[r.ID] = true
	}
}

func (s *Server) sbxRegionSetting(id string) *sbxRegionSetting {
	st := s.sbxSettings()
	if r, ok := st.regions[id]; ok {
		return r
	}
	r := &sbxRegionSetting{enabled: true}
	st.regions[id] = r
	return r
}

// SandboxRegionSettings returns the sandbox settings of a region as GET /sandbox-regions shows
// them.
func (s *Server) SandboxRegionSettings(regionID string) (runr.SandboxRegionSettings, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	r := s.regionByID(regionID)
	if r == nil {
		return runr.SandboxRegionSettings{}, false
	}
	return s.sbxDescribeRegion(r), true
}

func sbxCap(own *int, platform int) int {
	if own != nil && *own > 0 && *own < platform {
		return *own
	}
	return platform
}

func (s *Server) sbxDescribeRegion(r *runr.Region) runr.SandboxRegionSettings {
	set := s.sbxRegionSetting(r.ID)
	installed := []string{"kata-fc", "gvisor"}
	effective := []string{}
	for _, c := range installed {
		if set.allowedClasses == nil || sbxClassListed(c, set.allowedClasses) {
			effective = append(effective, c)
		}
	}
	if set.allowRunc {
		effective = append(effective, "runc")
	}
	if allowed := s.sbxQuotaLimits().AllowedClasses; len(allowed) > 0 {
		kept := []string{}
		for _, c := range effective {
			if sbxClassListed(c, allowed) {
				kept = append(kept, c)
			}
		}
		effective = kept
	}
	out := runr.SandboxRegionSettings{
		Region: &runr.Ref{ID: r.ID, Name: r.Name}, Enabled: set.enabled, InstalledClasses: installed,
		AllowedClasses: set.allowedClasses, EffectiveClasses: effective, AllowRunc: set.allowRunc,
		MaxTTLSeconds: sbxCap(set.maxTTL, sbxPlatformMaxTTL), MaxCPUm: sbxCap(set.maxCPU, sbxPlatformMaxCPU),
		MaxMemoryMB: sbxCap(set.maxMemory, sbxPlatformMaxMemory), MaxDiskMB: sbxCap(set.maxDisk, sbxPlatformMaxDisk),
		CanManage: !s.sbxSettings().readOnly[r.ID],
	}
	out.DefaultTTLSeconds = sbxPlatformTTL
	if set.defaultTTL != nil && *set.defaultTTL > 0 {
		out.DefaultTTLSeconds = *set.defaultTTL
	}
	if out.DefaultTTLSeconds > out.MaxTTLSeconds {
		out.DefaultTTLSeconds = out.MaxTTLSeconds
	}
	if set.defaultClass != nil {
		out.DefaultClass = *set.defaultClass
	}
	switch {
	case out.DefaultClass != "" && sbxClassListed(out.DefaultClass, effective):
		out.EffectiveDefaultClass = out.DefaultClass
		if out.DefaultClass == "kata" {
			out.EffectiveDefaultClass = "kata-fc"
		}
	case len(effective) > 0:
		out.EffectiveDefaultClass = effective[0]
	}
	return out
}

// sbxClassListed: "kata" names every Kata class.
func sbxClassListed(class string, list []string) bool {
	for _, l := range list {
		if l == class || (l == "kata" && strings.HasPrefix(class, "kata-")) {
			return true
		}
	}
	return false
}

func init() {
	handle("PUT /sandboxes/{sandbox}/network", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxFind(q)
		if err != nil {
			return 0, nil, err
		}
		if sb.data.Ended() {
			return 0, nil, &apiErr{status: 409, message: "The sandbox has ended."}
		}
		raw, present := q.Body["network"]
		if !present {
			return 0, nil, errValidation("network", "The network field must be present.")
		}
		var entries []any
		switch v := raw.(type) {
		case nil:
		case string:
			if v != "none" && v != "" {
				return 0, nil, errValidation("network", "The network must be \"none\" or {\"egress_allow\": [...]}.")
			}
		case []any:
			entries = v
		case map[string]any:
			if v["mode"] != "none" {
				list, ok := v["egress_allow"].([]any)
				if !ok {
					return 0, nil, errValidation("network", "The network must be \"none\" or {\"egress_allow\": [...]}.")
				}
				entries = list
			}
		default:
			return 0, nil, errValidation("network", "The network must be \"none\" or {\"egress_allow\": [...]}.")
		}
		if len(entries) > 50 {
			return 0, nil, errValidation("network", "At most 50 egress entries are allowed.")
		}
		network := runr.SandboxNetwork{Mode: "none", EgressAllow: []string{}}
		seen := map[string]bool{}
		for _, e := range entries {
			str, ok := e.(string)
			str = strings.ToLower(strings.TrimSpace(str))
			if !ok || str == "" || strings.ContainsAny(str, " \t\\") {
				return 0, nil, errValidation("network", fmt.Sprintf("The egress entry %v is invalid.", e))
			}
			if strings.HasPrefix(str, "169.254.") {
				return 0, nil, errValidation("network", "The cloud metadata ranges can never be allowed.")
			}
			if !seen[str] {
				seen[str] = true
				network.EgressAllow = append(network.EgressAllow, str)
			}
		}
		if len(network.EgressAllow) > 0 {
			network.Mode = "allowlist"
		}
		sb.data.Network = network
		sb.log("network", network.Mode)
		return 200, map[string]any{"data": sb.data}, nil
	})

	handle("GET /sandbox-regions", func(s *Server, q *featureReq) (int, any, *apiErr) {
		list := []runr.SandboxRegionSettings{}
		for _, r := range s.regions {
			if r.IsActive {
				list = append(list, s.sbxDescribeRegion(r))
			}
		}
		return 200, map[string]any{"data": list}, nil
	})

	handle("PUT /sandbox-regions/{knativeRegion}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if len(q.Segs) < 2 {
			return 0, nil, errNotFound()
		}
		r := s.regionByID(q.Segs[1])
		if r == nil {
			return 0, nil, errNotFound()
		}
		if s.sbxSettings().readOnly[r.ID] {
			return 0, nil, &apiErr{status: 403, message: "Platform regions are managed by the platform administrators."}
		}
		set := *s.sbxRegionSetting(r.ID)
		known := []string{"kata", "gvisor", "runc", "kata-fc", "kata-clh", "kata-qemu", "kata-dragonball"}
		isKnown := func(c string) bool {
			for _, k := range known {
				if k == c {
					return true
				}
			}
			return false
		}
		optInt := func(key string, min, max int, dst **int) *apiErr {
			v, ok := q.Body[key]
			if !ok {
				return nil
			}
			if v == nil {
				*dst = nil
				return nil
			}
			f, isNum := v.(float64)
			if !isNum || f != float64(int(f)) {
				return errValidation(key, "The "+strings.ReplaceAll(key, "_", " ")+" field must be an integer.")
			}
			if int(f) < min || (max > 0 && int(f) > max) {
				return errValidation(key, fmt.Sprintf("The %s field must be between %d and %d.", strings.ReplaceAll(key, "_", " "), min, max))
			}
			n := int(f)
			*dst = &n
			return nil
		}
		if v, ok := q.Body["enabled"]; ok {
			b, isBool := v.(bool)
			if !isBool {
				return 0, nil, errValidation("enabled", "The enabled field must be true or false.")
			}
			set.enabled = b
		}
		if v, ok := q.Body["allow_runc"]; ok {
			b, isBool := v.(bool)
			if !isBool {
				return 0, nil, errValidation("allow_runc", "The allow runc field must be true or false.")
			}
			set.allowRunc = b
		}
		if v, ok := q.Body["allowed_classes"]; ok {
			if v == nil {
				set.allowedClasses = nil
			} else {
				list, isList := v.([]any)
				if !isList {
					return 0, nil, errValidation("allowed_classes", "The allowed classes field must be an array.")
				}
				classes := []string{}
				for _, c := range list {
					str, _ := c.(string)
					if !isKnown(str) {
						return 0, nil, errValidation("allowed_classes", fmt.Sprintf("The selected class %v is invalid.", c))
					}
					classes = append(classes, str)
				}
				sort.Strings(classes)
				set.allowedClasses = classes
			}
		}
		if v, ok := q.Body["default_class"]; ok {
			if v == nil {
				set.defaultClass = nil
			} else {
				str, _ := v.(string)
				if !isKnown(str) {
					return 0, nil, errValidation("default_class", "The selected default class is invalid.")
				}
				set.defaultClass = &str
			}
		}
		for _, f := range []struct {
			key      string
			min, max int
			dst      **int
		}{
			{"default_ttl_seconds", 10, 0, &set.defaultTTL},
			{"max_ttl_seconds", 60, sbxPlatformMaxTTL, &set.maxTTL},
			{"max_cpu_m", 50, sbxPlatformMaxCPU, &set.maxCPU},
			{"max_memory_mb", 64, sbxPlatformMaxMemory, &set.maxMemory},
			{"max_disk_mb", 64, sbxPlatformMaxDisk, &set.maxDisk},
		} {
			if err := optInt(f.key, f.min, f.max, f.dst); err != nil {
				return 0, nil, err
			}
		}
		if set.defaultClass != nil && *set.defaultClass == "runc" && !set.allowRunc {
			return 0, nil, errValidation("default_class", "runc can be the default only when the region allows runc.")
		}
		*s.sbxRegionSetting(r.ID) = set
		return 200, map[string]any{"data": s.sbxDescribeRegion(r)}, nil
	})
}
