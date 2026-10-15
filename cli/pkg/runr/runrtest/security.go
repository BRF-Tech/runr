package runrtest

import (
	"encoding/json"
	"strconv"

	"github.com/runrsh/core/cli/pkg/runr"
)

// Software supply chain (ProjectSecurityController) and data residency (ResidencyController).
// Reports are seeded per version with SeedSecurityReport; a version without one has no report.

type securityState struct {
	// reports: version id → report.
	reports map[string]*securityReport
	// gate is the policy mode of every region ("off", "warn", "enforce").
	gate string
	// residency restricts project creation to these region names (nil = every region).
	allowed []string
}

type securityReport struct {
	summary  runr.SecurityReport
	findings []runr.SecurityFinding
	sboms    map[string]string // format → document
	running  bool
	// pollsLeft is how many reads a queued scan stays "running" before it finishes.
	pollsLeft int
}

// advance moves a queued scan on, one read at a time; it finishes "ok".
func (r *securityReport) advance() {
	if r == nil || !r.running {
		return
	}
	if r.pollsLeft > 0 {
		r.pollsLeft--
		r.summary.Status = "running"
		return
	}
	r.running = false
	r.summary.Status, r.summary.ScanStatus, r.summary.SBOMStatus = "ok", "ok", "ok"
	r.summary.ScannedAt, r.summary.FinishedAt = nowRFC3339(), nowRFC3339()
	if r.sboms == nil || len(r.sboms) == 0 {
		r.sboms = map[string]string{runr.SBOMSPDX: `{"spdxVersion":"SPDX-2.3"}`, runr.SBOMCycloneDX: `{"bomFormat":"CycloneDX"}`}
	}
}

func (s *Server) securityState() *securityState {
	return featureState(s, "security", func() *securityState {
		return &securityState{reports: map[string]*securityReport{}, gate: "warn"}
	})
}

// SeedSecurityReport records a finished supply-chain report for a version of a project: the
// counts, the findings and an SPDX and a CycloneDX SBOM.
func (s *Server) SeedSecurityReport(projectName string, versionNumber int, counts runr.SeverityCounts, findings ...runr.SecurityFinding) runr.SecurityReport {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	var v *version
	for _, x := range p.versions {
		if x.data.Number == versionNumber {
			v = x
		}
	}
	if v == nil {
		s.t.Fatalf("runrtest: version %d of %q does not exist", versionNumber, projectName)
	}
	pkgs := 42
	r := &securityReport{
		summary: runr.SecurityReport{ID: s.newID(), SubjectType: "version", VersionID: v.data.ID, ImageKind: "built",
			ImageRef: "registry.runr.test/" + p.data.Slug + ":" + v.data.Label, ImageDigest: "sha256:" + strconv.Itoa(1000+versionNumber),
			Status: "ok", SBOMStatus: "ok", ScanStatus: "ok", SignatureStatus: "signed", Counts: counts,
			FindingsTotal: len(findings), Packages: &pkgs, ScannedAt: nowRFC3339(), SignedAt: nowRFC3339(), FinishedAt: nowRFC3339()},
		findings: findings,
		sboms: map[string]string{
			runr.SBOMSPDX:      `{"spdxVersion":"SPDX-2.3","name":"` + p.data.Slug + `"}`,
			runr.SBOMCycloneDX: `{"bomFormat":"CycloneDX","specVersion":"1.6"}`,
		},
	}
	for _, f := range findings {
		if f.Fixed != "" {
			switch f.Severity {
			case "CRITICAL":
				r.summary.Fixable.Critical++
			case "HIGH":
				r.summary.Fixable.High++
			}
		}
	}
	s.securityState().reports[v.data.ID] = r
	return r.summary
}

// SetSecurityGate sets the policy mode the fake reports ("off", "warn" or "enforce"): with
// "enforce" a version with a critical finding is blocked.
func (s *Server) SetSecurityGate(mode string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.securityState().gate = mode
}

// RestrictResidency limits the regions the organization may create projects in.
func (s *Server) RestrictResidency(regionNames ...string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.securityState().allowed = regionNames
}

func (s *Server) securityPolicy() map[string]any {
	return map[string]any{"mode": s.securityState().gate, "max_critical": 0, "max_high": nil, "only_fixable": false,
		"require_scan": false, "require_signature": false, "trusted_fingerprints": []any{}, "tenant": false}
}

// securitySummary is ImageSecurityReport::summary as JSON (nil without a report).
func securitySummary(r *securityReport) any {
	if r == nil {
		return nil
	}
	return securityJSONMap(r.summary)
}

// securityDetail is ImageSecurityReport::detail.
func securityDetail(r *securityReport) any {
	if r == nil {
		return nil
	}
	m := securityJSONMap(r.summary)
	findings := []any{}
	for _, f := range r.findings {
		findings = append(findings, securityJSONMap(f))
	}
	m["findings"] = findings
	m["sbom_formats"] = []string{runr.SBOMSPDX, runr.SBOMCycloneDX}
	m["steps"] = []any{"sbom", "scan", "sign"}
	return m
}

func securityJSONMap(v any) map[string]any {
	data, _ := json.Marshal(v)
	m := map[string]any{}
	_ = json.Unmarshal(data, &m)
	return m
}

func (s *Server) securityVersionRow(p *project, v *version) map[string]any {
	return map[string]any{"id": v.data.ID, "number": v.data.Number, "label": v.data.Label,
		"build_status": v.data.BuildStatus, "created_at": nowRFC3339(), "image_kind": "built",
		"image": "registry.runr.test/" + p.data.Slug + ":" + v.data.Label, "applicable": true,
		"report": securitySummary(s.securityState().reports[v.data.ID])}
}

func (s *Server) securityDecision(v *version) map[string]any {
	r := s.securityState().reports[v.data.ID]
	violations := []any{}
	if r != nil && r.summary.Counts.Critical > 0 && s.securityState().gate != "off" {
		violations = append(violations, map[string]any{"rule": "max_critical", "limit": 0, "actual": r.summary.Counts.Critical})
	}
	return map[string]any{"applicable": true, "blocked": len(violations) > 0 && s.securityState().gate == "enforce",
		"overridden": false, "violations": violations, "policy": s.securityPolicy(), "report": securitySummary(r)}
}

func (s *Server) securityVersion(p *project, id string) *version {
	for _, v := range p.versions {
		if v.data.ID == id {
			return v
		}
	}
	return nil
}

func init() {
	handle("GET /projects/{project}/security", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		versions := []any{}
		for i := len(p.versions) - 1; i >= 0; i-- {
			versions = append(versions, s.securityVersionRow(p, p.versions[i]))
		}
		deployments := []any{}
		for i := len(p.deployments) - 1; i >= 0; i-- {
			d := p.deployments[i].data
			row := map[string]any{"id": d.ID, "status": d.Status, "version_id": nil, "version_label": nil,
				"created_at": nowRFC3339(), "report": nil}
			if d.Version != nil {
				row["version_id"], row["version_label"] = d.Version.ID, d.Version.Name
				row["report"] = securitySummary(s.securityState().reports[d.Version.ID])
			}
			deployments = append(deployments, row)
		}
		var region any
		if reg := s.regionByID(p.data.RegionID); reg != nil {
			region = map[string]any{"id": reg.ID, "name": reg.Name, "data_location": "eu"}
		}
		return 200, map[string]any{"data": map[string]any{
			"region": region, "policy": s.securityPolicy(),
			"pipeline": map[string]any{"sbom": true, "scan": true, "scan_external_images": false, "signing": true,
				"signing_fingerprint": "SHA256:runrtest", "admission": "off", "rescan_interval_hours": 24},
			"versions": versions, "deployments": deployments, "overrides": []any{},
		}}, nil
	})

	handle("GET /projects/{project}/versions/{version}/security", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		v := s.securityVersion(p, q.Segs[3])
		if v == nil {
			return 0, nil, errNotFound()
		}
		s.securityState().reports[v.data.ID].advance()
		return 200, map[string]any{"data": map[string]any{
			"version": s.securityVersionRow(p, v), "report": securityDetail(s.securityState().reports[v.data.ID]),
			"decision": s.securityDecision(v),
		}}, nil
	})

	handle("GET /projects/{project}/deployments/{deployment}/security", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		d := s.deploymentByID(p, q.Segs[3])
		if d == nil {
			return 0, nil, errNotFound()
		}
		out := map[string]any{"deployment_id": d.data.ID, "version": nil, "report": nil}
		if d.data.Version != nil {
			if v := s.securityVersion(p, d.data.Version.ID); v != nil {
				out["version"] = s.securityVersionRow(p, v)
				out["report"] = securityDetail(s.securityState().reports[v.data.ID])
			}
		}
		return 200, map[string]any{"data": out}, nil
	})

	handle("POST /projects/{project}/versions/{version}/security/scan", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		v := s.securityVersion(p, q.Segs[3])
		if v == nil {
			return 0, nil, errNotFound()
		}
		st := s.securityState()
		r := st.reports[v.data.ID]
		if r != nil && r.running {
			return 0, nil, &apiErr{status: 409, message: "A scan of this version is already running."}
		}
		if r == nil {
			r = &securityReport{summary: runr.SecurityReport{ID: s.newID(), SubjectType: "version", VersionID: v.data.ID},
				sboms: map[string]string{}}
			st.reports[v.data.ID] = r
		}
		r.running = true
		r.pollsLeft = 1
		r.summary.Status = "pending"
		r.summary.ScanStatus = "pending"
		return 202, map[string]any{"data": securitySummary(r)}, nil
	})

	handle("GET /projects/{project}/versions/{version}/sbom", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		v := s.securityVersion(p, q.Segs[3])
		if v == nil {
			return 0, nil, errNotFound()
		}
		r := s.securityState().reports[v.data.ID]
		if r == nil {
			return 0, nil, &apiErr{status: 404, message: "This version has no supply-chain report."}
		}
		format := q.query("format")
		if format == "" {
			format = runr.SBOMSPDX
		}
		media := map[string]string{runr.SBOMSPDX: "application/spdx+json", runr.SBOMCycloneDX: "application/vnd.cyclonedx+json"}[format]
		if media == "" {
			return 0, nil, &apiErr{status: 422, message: "Unknown SBOM format."}
		}
		doc, ok := r.sboms[format]
		if !ok {
			return 0, nil, &apiErr{status: 404, message: "No SBOM in this format."}
		}
		ext := map[string]string{runr.SBOMSPDX: "spdx.json", runr.SBOMCycloneDX: "cdx.json"}[format]
		return 200, rawAnswer{contentType: media, filename: p.data.Slug + "-v" + strconv.Itoa(v.data.Number) + "." + ext,
			data: []byte(doc)}, nil
	})

	handle("GET /residency", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.securityState()
		allowed := map[string]bool{}
		for _, n := range st.allowed {
			allowed[n] = true
		}
		regions := []any{}
		var ids []string
		for _, r := range s.regions {
			ok := st.allowed == nil || allowed[r.Name]
			if ok {
				ids = append(ids, r.ID)
			}
			regions = append(regions, map[string]any{"id": r.ID, "name": r.Name, "data_location": "eu", "allowed": ok})
		}
		var allowedIDs any
		if st.allowed != nil {
			allowedIDs = ids
		}
		var tenant any
		if s.rbac().edition == "multi" {
			tenant = s.rbac().tenantID
		}
		return 200, map[string]any{"data": map[string]any{
			"tenant_id": tenant, "restricted": st.allowed != nil, "residency_enforced": st.allowed != nil,
			"allowed_region_ids": allowedIDs, "allowed_locations": []any{}, "default_region_id": nil, "regions": regions,
		}}, nil
	})
}
