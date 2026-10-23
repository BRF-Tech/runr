package runrtest

import (
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// The region's own OpenBao in the fake (OpenBaoController): installs and removals end after
// RegionAdminConfig.InstallPolls reads of its status. It needs External Secrets Operator, which
// the fake treats as ready unless SetRegionOpenBao says otherwise.

type fakeOpenBao struct {
	status           string
	message          string
	defaultForValues bool
	values           int
	esoStatus        string
	installedAt      string
}

func (s *Server) openBaoSt() map[string]*fakeOpenBao {
	return *featureState(s, "openbao", func() *map[string]*fakeOpenBao {
		m := map[string]*fakeOpenBao{}
		return &m
	})
}

func (s *Server) openBaoOf(regionID string) *fakeOpenBao {
	st := s.openBaoSt()
	if b, ok := st[regionID]; ok {
		return b
	}
	b := &fakeOpenBao{status: runr.KnativeNotInstalled, defaultForValues: true, esoStatus: runr.KnativeReady}
	st[regionID] = b
	return b
}

// SetRegionOpenBao sets the state of a region's OpenBao: its status, the status of External
// Secrets Operator there and how many secret values live in it.
func (s *Server) SetRegionOpenBao(regionName, status, esoStatus string, values int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, r := range s.regions {
		if r.Name == regionName {
			b := s.openBaoOf(r.ID)
			b.status, b.esoStatus, b.values = status, esoStatus, values
			if status == runr.KnativeReady {
				b.installedAt = nowRFC3339()
			}
			return
		}
	}
	panic("runrtest: no region " + regionName)
}

// openBaoReadyIn reports whether a region runs its OpenBao and External Secrets Operator (dynamic
// credentials need both).
func (s *Server) openBaoReadyIn(regionID string) bool {
	b := s.openBaoOf(regionID)
	return b.status == runr.KnativeReady && b.esoStatus == runr.KnativeReady
}

func (s *Server) openBaoData(b *fakeOpenBao) map[string]any {
	var installed any
	if b.installedAt != "" {
		installed = b.installedAt
	}
	version := any(nil)
	if b.status == runr.KnativeReady {
		version = "2.4.1"
	}
	return map[string]any{
		"status": b.status, "message": nullStr(b.message), "version": version, "release_version": "2.4.1",
		"installed_at": installed, "default_for_values": b.defaultForValues, "values": b.values,
		"eso_status": b.esoStatus, "can_manage": s.holds("regions.manage"),
	}
}

func init() {
	handle("GET /knative-regions/{knativeRegion}/openbao", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		s.rlcAdvance(r)
		return 200, map[string]any{"data": s.openBaoData(s.openBaoOf(reg.ID))}, nil
	})
	handle("POST /knative-regions/{knativeRegion}/openbao", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		b := s.openBaoOf(reg.ID)
		if b.status == runr.KnativeInstalling || b.status == runr.KnativeUninstalling {
			return 0, nil, &apiErr{status: 422, message: "Another operation is in progress on this region."}
		}
		if b.esoStatus != runr.KnativeReady {
			return 0, nil, &apiErr{status: 422, message: "OpenBao in region \"" + reg.Name + "\" needs External Secrets Operator there: install it first on the region's Secrets tab."}
		}
		b.status, b.message = runr.KnativeInstalling, "Installation queued..."
		s.rlcStart(r, "openbao", func(failed bool, message string) {
			if failed {
				b.status, b.message = runr.KnativeError, message
				return
			}
			b.status, b.message, b.installedAt = runr.KnativeReady, "", nowRFC3339()
		})
		return 202, map[string]any{"message": "OpenBao is being installed.", "data": s.openBaoData(b)}, nil
	})
	handle("PATCH /knative-regions/{knativeRegion}/openbao", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		on, ok := q.Body["default_for_values"].(bool)
		if !ok {
			return 0, nil, errValidation("default_for_values", "The default for values field must be true or false.")
		}
		b := s.openBaoOf(reg.ID)
		b.defaultForValues = on
		return 200, map[string]any{"data": s.openBaoData(b)}, nil
	})
	handle("DELETE /knative-regions/{knativeRegion}/openbao", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		b := s.openBaoOf(reg.ID)
		switch {
		case b.status == runr.KnativeNotInstalled:
			return 0, nil, &apiErr{status: 422, message: "Region \"" + reg.Name + "\" has no OpenBao of its own."}
		case b.values > 0:
			return 0, nil, &apiErr{status: 409, message: "Secret values or dynamic credentials of projects in region \"" + reg.Name + "\" live in its OpenBao: move them before removing it."}
		}
		b.status, b.message = runr.KnativeUninstalling, "Removal queued..."
		s.rlcStart(r, "openbao", func(failed bool, message string) {
			if failed {
				b.status, b.message = runr.KnativeError, message
				return
			}
			b.status, b.message, b.installedAt = runr.KnativeNotInstalled, "", ""
		})
		return 202, map[string]any{"message": "OpenBao is being removed.", "data": s.openBaoData(b)}, nil
	})
}
