package runrtest

import (
	"github.com/runrsh/core/cli/pkg/runr"
)

// The supply-chain report of a model service's image (ModelServiceSecurityController) of the
// fake: seeded per service with SeedModelServiceReport; a service without one has no report.

type modelSecurityState struct {
	reports map[string]*securityReport // service id → report
	// unscanned: service ids whose region does not scan external images.
	unscanned map[string]bool
}

func (s *Server) modelSecurity() *modelSecurityState {
	return featureState(s, "model-security", func() *modelSecurityState {
		return &modelSecurityState{reports: map[string]*securityReport{}, unscanned: map[string]bool{}}
	})
}

func fakeModelServiceImage(svc *fakeModelService) string {
	engine := svc.data.Engine
	if engine == "" {
		engine = "vllm"
	}
	return "ghcr.io/runrsh/runr/model-" + engine + ":0.5.0"
}

// SeedModelServiceReport records a finished report of a model service's image with counts and
// findings, and its SBOMs.
func (s *Server) SeedModelServiceReport(serviceName string, counts runr.SeverityCounts, findings ...runr.SecurityFinding) {
	s.mu.Lock()
	defer s.mu.Unlock()
	svc := s.modelServiceByRef(serviceName)
	if svc == nil {
		s.t.Fatalf("runrtest: model service %q does not exist", serviceName)
	}
	pkgs := 310
	s.modelSecurity().reports[svc.data.ID] = &securityReport{
		summary: runr.SecurityReport{ID: s.newID(), SubjectType: "model_service", ImageKind: "external",
			ImageRef: fakeModelServiceImage(svc), ImageDigest: "sha256:4b1d", Status: "ok", SBOMStatus: "ok",
			ScanStatus: "ok", SignatureStatus: "unsigned", Counts: counts, FindingsTotal: len(findings),
			Packages: &pkgs, ScannedAt: nowRFC3339(), FinishedAt: nowRFC3339()},
		findings: findings,
		sboms: map[string]string{
			runr.SBOMSPDX:      `{"spdxVersion":"SPDX-2.3","name":"model-service-` + svc.data.Name + `"}`,
			runr.SBOMCycloneDX: `{"bomFormat":"CycloneDX","specVersion":"1.6"}`,
		},
	}
}

// SetModelServiceUnscanned makes the region of a model service one that does not scan external
// images (scanned false).
func (s *Server) SetModelServiceUnscanned(serviceName string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if svc := s.modelServiceByRef(serviceName); svc != nil {
		s.modelSecurity().unscanned[svc.data.ID] = true
	}
}

func init() {
	handle("GET /model-services/{service}/security", func(s *Server, q *featureReq) (int, any, *apiErr) {
		svc := s.modelServiceByID(q.Segs[1])
		if svc == nil {
			return 0, nil, errNotFound()
		}
		st := s.modelSecurity()
		r := st.reports[svc.data.ID]
		return 200, map[string]any{"data": map[string]any{
			"image":   fakeModelServiceImage(svc),
			"scanned": !st.unscanned[svc.data.ID],
			"current": r != nil,
			"report":  securityDetail(r),
		}}, nil
	})
	handle("GET /model-services/{service}/sbom", func(s *Server, q *featureReq) (int, any, *apiErr) {
		svc := s.modelServiceByID(q.Segs[1])
		if svc == nil {
			return 0, nil, errNotFound()
		}
		r := s.modelSecurity().reports[svc.data.ID]
		if r == nil {
			return 0, nil, &apiErr{status: 404, message: "This image has no supply-chain report yet."}
		}
		format := q.query("format")
		if format == "" {
			format = runr.SBOMSPDX
		}
		media := map[string]string{runr.SBOMSPDX: "application/spdx+json", runr.SBOMCycloneDX: "application/vnd.cyclonedx+json"}[format]
		doc, ok := r.sboms[format]
		if media == "" || !ok {
			return 0, nil, &apiErr{status: 422, message: "Unknown SBOM format."}
		}
		ext := map[string]string{runr.SBOMSPDX: "spdx.json", runr.SBOMCycloneDX: "cdx.json"}[format]
		return 200, rawAnswer{contentType: media, filename: "model-service-" + svc.data.Name + "." + ext, data: []byte(doc)}, nil
	})
}
