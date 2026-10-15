package runrtest

import (
	"fmt"
	"strings"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// The supply chain of a region (RegionSupplyChainController) of the fake. Key generation, the
// scanner DB refresh and admission installs end after RegionAdminConfig.InstallPolls reads of
// the settings (failed with RegionAdminConfig.FailInstall). Regions named in
// RegionAdminConfig.Disconnected have no cluster (signing-key answers 422).

type rscPending struct {
	part      string // signing, scanner_db, admission
	target    string // the status it ends in
	pollsLeft int
	fails     bool
}

type rscRegion struct {
	data    runr.RegionSupplyChain
	pending []*rscPending
	keySeq  int
	// reports are the runtime image reports of the region (SeedRuntimeImageReport).
	reports []runr.RuntimeImageReport
}

func (s *Server) rscState() map[string]*rscRegion {
	return *featureState(s, "region-supply-chain", func() *map[string]*rscRegion {
		m := map[string]*rscRegion{}
		return &m
	})
}

func (s *Server) rscOf(reg *runr.Region) *rscRegion {
	st := s.rscState()
	if r, ok := st[reg.ID]; ok {
		return r
	}
	zero := 0
	r := &rscRegion{}
	d := &r.data
	d.RegionID = reg.ID
	d.SBOMEnabled, d.ScanEnabled, d.RescanIntervalHours = true, true, 24
	d.ScannerDBMode, d.ScannerDBStatus = "online", "none"
	d.GateMode, d.GateMaxCritical = "off", &zero
	d.VerifyOnDeploy, d.AttestEnabled, d.SigningStatus = true, true, "none"
	d.AdmissionMode, d.AdmissionStatus = "off", "not_installed"
	d.CaaSPolicy = "any"
	d.SigningRetiredKeys, d.AdmissionExemptImages = []runr.RetiredSigningKey{}, []string{}
	d.CaaSAllowedRegistries, d.CaaSTrustedKeys = []string{}, []string{}
	d.Region.ID, d.Region.Name, d.Region.HasRegistry, d.Region.RegistryMode = reg.ID, reg.Name, true, "managed"
	d.Region.ProjectNamespace = reg.Name
	d.ScannerDBRepository = "10.43.0.5:5000/runr-supply-chain/trivy-db"
	d.ScannerDBDefaultSource, d.SupplyChainNamespace, d.CanManage = "mirror.gcr.io/aquasec/trivy-db:2", "runr-supply-chain", true
	st[reg.ID] = r
	return r
}

// RegionSupplyChainState returns the fake's supply-chain settings of a region.
func (s *Server) RegionSupplyChainState(regionName string) runr.RegionSupplyChain {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	return s.rscOf(reg).data
}

// SeedRuntimeImageReport adds a runtime image built in a region with a finished report.
func (s *Server) SeedRuntimeImageReport(regionName, runtime, version string, counts runr.SeverityCounts) runr.RuntimeImageReport {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	r := s.rscOf(reg)
	img := "10.43.0.5:5000/runr-runtimes/" + runtime + ":" + version
	rep := runr.RuntimeImageReport{BuildID: s.newID(), Runtime: runtime, Version: version, Status: "ready", Image: img,
		Report: &runr.SecurityReport{ID: s.newID(), Status: "ok", ImageRef: img, SBOMStatus: "ok", ScanStatus: "ok",
			SignatureStatus: "signed", Counts: counts}}
	r.reports = append(r.reports, rep)
	return rep
}

func (r *rscRegion) setStatus(part, status, msg string) {
	switch part {
	case runr.SupplyChainSigning:
		r.data.SigningStatus, r.data.SigningMessage = status, msg
	case runr.SupplyChainScannerDB:
		r.data.ScannerDBStatus, r.data.ScannerDBMessage = status, msg
	default:
		r.data.AdmissionStatus, r.data.AdmissionMessage = status, msg
	}
}

// advance moves the background changes one read on.
func (r *rscRegion) advance(failMessage string) {
	left := r.pending[:0]
	for _, p := range r.pending {
		if p.pollsLeft > 0 {
			p.pollsLeft--
			left = append(left, p)
			continue
		}
		switch {
		case p.fails:
			r.setStatus(p.part, "failed", failMessage)
		case p.part == runr.SupplyChainSigning:
			r.keySeq++
			r.data.SigningStatus, r.data.SigningMessage = "ready", ""
			r.data.SigningKeyFingerprint = fmt.Sprintf("%064x", 0xc0517e0+r.keySeq)
			r.data.SigningPublicKey = "-----BEGIN PUBLIC KEY-----\nfake-key-" + fmt.Sprint(r.keySeq) + "\n-----END PUBLIC KEY-----\n"
			r.data.SigningKeyCreatedAt = nowRFC3339()
		case p.part == runr.SupplyChainScannerDB:
			r.data.ScannerDBStatus, r.data.ScannerDBUpdatedAt, r.data.ScannerDBRefreshedAt = "ready", nowRFC3339(), nowRFC3339()
		default:
			r.setStatus(p.part, p.target, "")
		}
	}
	r.pending = left
}

func (s *Server) rscStart(r *rscRegion, part, busy, target string) {
	cfg := s.regionAdminSt().cfg
	r.setStatus(part, busy, "")
	r.pending = append(r.pending, &rscPending{part: part, target: target, pollsLeft: cfg.InstallPolls, fails: cfg.FailInstall})
}

func (s *Server) rscRegionOf(q *featureReq) (*runr.Region, *rscRegion, *apiErr) {
	reg := s.regionByID(q.Segs[1])
	if reg == nil {
		return nil, nil, errNotFound()
	}
	return reg, s.rscOf(reg), nil
}

func (s *Server) rscAnswer(status int, r *rscRegion) (int, any, *apiErr) {
	return status, map[string]any{"data": r.data}, nil
}

func init() {
	handle("GET /knative-regions/{knativeRegion}/supply-chain", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rscRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		r.advance(s.regionAdminSt().cfg.FailMessage)
		return s.rscAnswer(200, r)
	})

	handle("PUT /knative-regions/{knativeRegion}/supply-chain", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rscRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		next := r.data
		boolField := func(key string, dst *bool) *apiErr {
			if v, ok := q.Body[key]; ok {
				b, isBool := v.(bool)
				if !isBool {
					return errValidation(key, "The "+strings.ReplaceAll(key, "_", " ")+" field must be true or false.")
				}
				*dst = b
			}
			return nil
		}
		for key, dst := range map[string]*bool{"sbom_enabled": &next.SBOMEnabled, "scan_enabled": &next.ScanEnabled,
			"scan_external_images": &next.ScanExternalImages, "gate_only_fixable": &next.GateOnlyFixable,
			"gate_require_scan": &next.GateRequireScan, "signing_enabled": &next.SigningEnabled,
			"verify_on_deploy": &next.VerifyOnDeploy, "attest_enabled": &next.AttestEnabled,
			"admission_require_sbom": &next.AdmissionRequireSBOM} {
			if err := boolField(key, dst); err != nil {
				return 0, nil, err
			}
		}
		enum := func(key string, dst *string, values ...string) *apiErr {
			v, ok := q.Body[key]
			if !ok {
				return nil
			}
			str, _ := v.(string)
			if !rsbxListed(str, values) {
				return errValidation(key, "The selected "+strings.ReplaceAll(key, "_", " ")+" is invalid.")
			}
			*dst = str
			return nil
		}
		for _, e := range []struct {
			key    string
			dst    *string
			values []string
		}{{"scanner_db_mode", &next.ScannerDBMode, []string{"online", "mirror", "offline"}},
			{"gate_mode", &next.GateMode, []string{"off", "warn", "enforce"}},
			{"admission_mode", &next.AdmissionMode, []string{"off", "warn", "enforce"}},
			{"caas_policy", &next.CaaSPolicy, []string{"any", "allowlist", "signed"}}} {
			if err := enum(e.key, e.dst, e.values...); err != nil {
				return 0, nil, err
			}
		}
		if v, ok := q.Body["rescan_interval_hours"]; ok {
			n, isNum := v.(float64)
			if !isNum || n < 0 || n > 720 || n != float64(int(n)) {
				return 0, nil, errValidation("rescan_interval_hours", "The rescan interval hours field must be between 0 and 720.")
			}
			next.RescanIntervalHours = int(n)
		}
		for key, dst := range map[string]**int{"gate_max_critical": &next.GateMaxCritical, "gate_max_high": &next.GateMaxHigh} {
			v, ok := q.Body[key]
			if !ok {
				continue
			}
			if v == nil {
				*dst = nil
				continue
			}
			n, isNum := v.(float64)
			if !isNum || n < 0 || n != float64(int(n)) {
				return 0, nil, errValidation(key, "The "+strings.ReplaceAll(key, "_", " ")+" field must be an integer of 0 or more.")
			}
			i := int(n)
			*dst = &i
		}
		for key, dst := range map[string]*string{"scanner_db_source": &next.ScannerDBSource, "data_location": &next.DataLocation} {
			if v, ok := q.Body[key]; ok {
				str, _ := v.(string)
				*dst = strings.TrimSpace(str)
			}
		}
		for key, dst := range map[string]*[]string{"admission_exempt_images": &next.AdmissionExemptImages,
			"caas_allowed_registries": &next.CaaSAllowedRegistries, "caas_trusted_keys": &next.CaaSTrustedKeys} {
			v, ok := q.Body[key]
			if !ok {
				continue
			}
			list := []string{}
			raw, isList := v.([]any)
			if v != nil && !isList {
				return 0, nil, errValidation(key, "The "+strings.ReplaceAll(key, "_", " ")+" field must be an array.")
			}
			for _, item := range raw {
				str, _ := item.(string)
				if str = strings.TrimSpace(str); str != "" && !rsbxListed(str, list) {
					if key == "caas_trusted_keys" && !strings.Contains(str, "BEGIN PUBLIC KEY") {
						return 0, nil, errValidation(key, "Every trusted key must be a PEM public key.")
					}
					list = append(list, str)
				}
			}
			*dst = list
		}
		if (next.CaaSPolicy == "allowlist" || next.CaaSPolicy == "signed") && len(next.CaaSAllowedRegistries) == 0 {
			return 0, nil, errValidation("caas_allowed_registries", "Name at least one allowed registry for this policy.")
		}
		if next.CaaSPolicy == "signed" && len(next.CaaSTrustedKeys) == 0 {
			return 0, nil, errValidation("caas_trusted_keys", "Add at least one trusted key for this policy.")
		}
		next.UpdatedBy = "ada"
		r.data = next
		return s.rscAnswer(200, r)
	})

	handle("POST /knative-regions/{knativeRegion}/supply-chain/signing-key", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rscRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		if rsbxListed(reg.Name, s.regionAdminSt().cfg.Disconnected) {
			return 0, nil, &apiErr{status: 422, message: "The region has no cluster connection to create the key in."}
		}
		if r.data.SigningStatus == "generating" {
			return 0, nil, &apiErr{status: 409, message: "A signing key is being generated."}
		}
		if r.data.SigningKeyFingerprint != "" {
			r.data.SigningRetiredKeys = append(r.data.SigningRetiredKeys, runr.RetiredSigningKey{
				Fingerprint: r.data.SigningKeyFingerprint, PublicKey: r.data.SigningPublicKey, RetiredAt: nowRFC3339()})
		}
		s.rscStart(r, runr.SupplyChainSigning, "generating", "ready")
		return s.rscAnswer(202, r)
	})

	handle("DELETE /knative-regions/{knativeRegion}/supply-chain/signing-key/retired", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rscRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		r.data.SigningRetiredKeys = []runr.RetiredSigningKey{}
		return s.rscAnswer(200, r)
	})

	handle("POST /knative-regions/{knativeRegion}/supply-chain/scanner-db/refresh", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rscRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		if !r.data.Region.HasRegistry {
			return 0, nil, &apiErr{status: 422, message: "The region has no registry to mirror the scanner DB into."}
		}
		if r.data.ScannerDBStatus != "refreshing" {
			s.rscStart(r, runr.SupplyChainScannerDB, "refreshing", "ready")
		}
		return s.rscAnswer(202, r)
	})

	handle("POST /knative-regions/{knativeRegion}/supply-chain/admission", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rscRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		if v, ok := q.Body["mode"]; ok {
			mode, _ := v.(string)
			if mode != "enforce" && mode != "warn" {
				return 0, nil, errValidation("mode", "The selected mode is invalid.")
			}
			r.data.AdmissionMode = mode
		}
		if r.data.AdmissionMode == "off" {
			return 0, nil, &apiErr{status: 422, message: "Choose the admission mode (warn or enforce) first."}
		}
		s.rscStart(r, runr.SupplyChainAdmission, "installing", "ready")
		return s.rscAnswer(202, r)
	})

	handle("DELETE /knative-regions/{knativeRegion}/supply-chain/admission", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rscRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		s.rscStart(r, runr.SupplyChainAdmission, "removing", "not_installed")
		r.pending[len(r.pending)-1].fails = false
		return s.rscAnswer(202, r)
	})

	handle("GET /knative-regions/{knativeRegion}/supply-chain/admission/preview", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rscRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		noMatch := map[string]string{"off": "allow", "warn": "warn", "enforce": "deny"}[r.data.AdmissionMode]
		policies := []any{map[string]any{"apiVersion": "policy.sigstore.dev/v1beta1", "kind": "ClusterImagePolicy",
			"metadata": map[string]any{"name": "runr-signed-builds"}}}
		if r.data.CaaSPolicy != "any" {
			policies = append(policies, map[string]any{"apiVersion": "policy.sigstore.dev/v1beta1", "kind": "ClusterImagePolicy",
				"metadata": map[string]any{"name": "runr-caas-" + r.data.CaaSPolicy}})
		}
		return 200, map[string]any{"data": map[string]any{
			"release": "policy-controller", "chart": "sigstore/policy-controller", "chart_version": "0.10.5",
			"namespace": "cosign-system", "project_namespace": reg.Name, "namespace_label": "policy.sigstore.dev/include",
			"values":          map[string]any{"webhook": map[string]any{"failurePolicy": "Fail"}},
			"no_match_policy": noMatch, "policies": policies,
		}}, nil
	})

	handle("GET /knative-regions/{knativeRegion}/supply-chain/public-key", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rscRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		if r.data.SigningPublicKey == "" {
			return 0, nil, &apiErr{status: 404, message: "The region has no signing key."}
		}
		return 200, rawAnswer{contentType: "application/x-pem-file", filename: "runr-" + reg.Name + "-cosign.pub",
			data: []byte(r.data.SigningPublicKey)}, nil
	})

	handle("GET /knative-regions/{knativeRegion}/supply-chain/runtime-images", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rscRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		list := append([]runr.RuntimeImageReport{}, r.reports...)
		return 200, map[string]any{"data": list}, nil
	})

	handle("GET /knative-regions/{knativeRegion}/supply-chain/runtime-images/{report}/sbom", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.rscRegionOf(q)
		if err != nil {
			return 0, nil, err
		}
		for _, rep := range r.reports {
			if rep.Report != nil && rep.Report.ID == q.Segs[4] {
				format := q.query("format")
				switch format {
				case "", runr.SBOMSPDX:
					return 200, rawAnswer{contentType: "application/spdx+json", filename: "runtime-" + rep.Runtime + ".spdx.json",
						data: []byte(`{"spdxVersion":"SPDX-2.3","name":"` + rep.Image + `"}`)}, nil
				case runr.SBOMCycloneDX:
					return 200, rawAnswer{contentType: "application/vnd.cyclonedx+json", filename: "runtime-" + rep.Runtime + ".cdx.json",
						data: []byte(`{"bomFormat":"CycloneDX","specVersion":"1.6"}`)}, nil
				}
				return 0, nil, &apiErr{status: 422, message: "Unknown SBOM format."}
			}
		}
		return 0, nil, errNotFound()
	})
}
